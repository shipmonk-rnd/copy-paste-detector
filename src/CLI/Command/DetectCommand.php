<?php declare(strict_types = 1);

namespace CopyPasteDetector\CLI\Command;

use CopyPasteDetector\Cache\SubtreeCache;
use CopyPasteDetector\Config\Config;
use CopyPasteDetector\Config\ConfigResolver;
use CopyPasteDetector\Config\Configuration;
use CopyPasteDetector\Config\ResolvedConfig;
use CopyPasteDetector\Detection\CloneDetector;
use CopyPasteDetector\Detection\CloneGroup;
use CopyPasteDetector\Exception\ErrorException;
use CopyPasteDetector\Reporting\SyntaxHighlighter;
use CopyPasteDetector\Reporting\TextReporter;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UnexpectedValueException;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function file_exists;
use function getcwd;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function microtime;
use function pathinfo;
use function realpath;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use const PATHINFO_EXTENSION;

/**
 * CLI command for detecting code clones
 */
final class DetectCommand extends Command
{

    protected function configure(): void
    {
        $this
            ->setName('detect')
            ->setDescription('Detect structural code clones in PHP files')
            ->addArgument(
                'paths',
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'Paths to directories or files to analyze (can be set in config file)',
            )
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to config file (default: copy-paste-detector.php in current directory)',
            )
            ->addOption(
                'min-node-count',
                'm',
                InputOption::VALUE_REQUIRED,
                'Minimum number of nodes for a subtree to be considered (default: 50)',
            )
            ->addOption(
                'cache-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Directory for caching parsed subtrees (default: system temp directory)',
            )
            ->setHelp(<<<'HELP'
The <info>detect</info> command analyzes PHP files for structural code clones.

It uses subtree hashing (similar to CloneDR) to find exact structural duplicates and Type-2 clone detection.

<comment>Usage:</comment>
    <info>php bin/copy-paste-detector detect /path/to/code</info>
    <info>php bin/copy-paste-detector detect src/ tests/</info>
    <info>php bin/copy-paste-detector detect src/ --min-node-count=30</info>
    <info>php bin/copy-paste-detector detect src/ --config=my-config.php</info>

<comment>Options:</comment>
    <info>--config</info>            Path to config file (default: copy-paste-detector.php)
    <info>--min-node-count</info>    Minimum nodes in a subtree (higher = fewer, larger clones)
    <info>--cache-dir</info>         Directory for caching parsed subtrees
HELP,);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int
    {
        return $this->executeDetection($input, $output); // @phpstan-ignore missingType.checkedException (ErrorException in throws would violate LSP, but is solved on Application level)
    }

    /**
     * @throws ErrorException
     */
    private function executeDetection(
        InputInterface $input,
        OutputInterface $output,
    ): int
    {
        $stderr = $this->getStderr($output);

        $cwd = getcwd();
        if ($cwd === false) {
            throw new ErrorException('Could not determine current working directory');
        }

        $resolvedConfig = $this->loadConfiguration($input, $cwd);
        $config = $resolvedConfig->getConfig();
        $this->displayConfigPath($resolvedConfig, $stderr, $cwd);

        [$cacheDir, $usingDefaultCacheDir, $cliOverrideCacheDir] = $this->resolveCacheDir($input, $config);
        [$minNodeCountOverride, $usingDefaultMinNodeCount, $cliOverrideMinNodeCount] = $this->resolveMinNodeCount($input, $config);

        [$paths, $usingDefaultPaths, $overriddenConfigPaths] = $this->resolvePaths($input, $config);

        $configuration = Configuration::fromConfig($config, $minNodeCountOverride);

        $realExcludePaths = $this->resolveExcludePaths($config, $cwd);
        $this->warnAboutIneffectiveExcludes($paths, $realExcludePaths, $cwd, $stderr);

        $files = $this->collectPhpFilesFromPaths($paths, $realExcludePaths);
        if (count($files) === 0) {
            throw new ErrorException('No PHP files found in specified paths');
        }

        $this->displayScanInfo(
            $stderr,
            $cwd,
            $paths,
            $usingDefaultPaths,
            $overriddenConfigPaths,
            $realExcludePaths,
            $configuration->getMinNodeCount(),
            $usingDefaultMinNodeCount,
            $cliOverrideMinNodeCount,
            $cacheDir,
            $usingDefaultCacheDir,
            $cliOverrideCacheDir,
        );

        $startTime = microtime(true);
        $cloneGroups = $this->detectClones($files, $configuration, $cacheDir, $stderr);
        $elapsedTime = microtime(true) - $startTime;

        $this->outputReport($cloneGroups, $elapsedTime, $output);

        return Command::SUCCESS;
    }

    private function getStderr(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;
    }

    /**
     * @throws ErrorException
     */
    private function loadConfiguration(
        InputInterface $input,
        string $cwd,
    ): ResolvedConfig
    {
        $configPath = $input->getOption('config');

        if ($configPath !== null && !is_string($configPath)) {
            throw new LogicException('Config option must be a string or null');
        }

        $configResolver = new ConfigResolver($cwd);

        return $configResolver->resolveConfig($configPath);
    }

    private function displayConfigPath(
        ResolvedConfig $resolvedConfig,
        OutputInterface $stderr,
        string $cwd,
    ): void
    {
        if ($resolvedConfig->wasAutoDetected() && $resolvedConfig->getUsedConfigPath() !== null) {
            $configPath = $this->relativizePath($resolvedConfig->getUsedConfigPath(), $cwd);
            $stderr->writeln(sprintf('Config: <fg=#aaaaaa>%s</>', $configPath));
        }
    }

    /**
     * @return array{string, bool, bool} [cacheDir, usingDefault, cliOverride]
     */
    private function resolveCacheDir(
        InputInterface $input,
        Config $config,
    ): array
    {
        $cacheDirOption = $input->getOption('cache-dir');
        $configCacheDir = $config->getCacheDir();

        $usingDefault = $cacheDirOption === null && $configCacheDir === null;
        $cliOverride = $cacheDirOption !== null && $configCacheDir !== null;

        $cacheDir = $cacheDirOption !== null
            ? (string) $cacheDirOption // @phpstan-ignore cast.string
            : $configCacheDir ?? sys_get_temp_dir() . '/copy-paste-detector-cache';

        return [$cacheDir, $usingDefault, $cliOverride];
    }

    /**
     * @return array{int|null, bool, bool} [minNodeCountOverride, usingDefault, cliOverride]
     */
    private function resolveMinNodeCount(
        InputInterface $input,
        Config $config,
    ): array
    {
        $minNodeCountOption = $input->getOption('min-node-count');
        $minNodeCountOverride = $minNodeCountOption !== null ? (int) $minNodeCountOption : null; // @phpstan-ignore cast.int

        $configMinNodeCount = $config->getMinNodeCount();
        $usingDefault = $minNodeCountOverride === null && $configMinNodeCount === null;
        $cliOverride = $minNodeCountOverride !== null && $configMinNodeCount !== null;

        return [$minNodeCountOverride, $usingDefault, $cliOverride];
    }

    /**
     * Resolve and normalize exclude paths to absolute paths
     *
     * @return list<string>
     *
     * @throws ErrorException
     */
    private function resolveExcludePaths(
        Config $config,
        string $cwd,
    ): array
    {
        $realExcludePaths = [];

        foreach ($config->getExcludePaths() as $excludePath) {
            $originalPath = $excludePath;

            if (!str_starts_with($excludePath, '/')) {
                $excludePath = $cwd . '/' . $excludePath;
            }

            $resolved = realpath($excludePath);

            if ($resolved === false) {
                throw new ErrorException("Exclude path does not exist: {$originalPath}");
            }

            $realExcludePaths[] = $resolved;
        }

        return $realExcludePaths;
    }

    /**
     * Warn if any exclude paths are not within any of the scanned paths
     *
     * @param list<string> $paths
     * @param list<string> $realExcludePaths
     *
     * @throws ErrorException
     */
    private function warnAboutIneffectiveExcludes(
        array $paths,
        array $realExcludePaths,
        string $cwd,
        OutputInterface $stderr,
    ): void
    {
        $realPaths = [];

        foreach ($paths as $path) {
            $realPaths[] = $this->resolveRealpath($path, "Path '$path' does not exist");
        }

        foreach ($realExcludePaths as $realExcludePath) {
            $isWithinScanPaths = false;

            foreach ($realPaths as $realPath) {
                if ($realExcludePath === $realPath || str_starts_with($realExcludePath, $realPath . '/')) {
                    $isWithinScanPaths = true;
                    break;
                }
            }

            if (!$isWithinScanPaths) {
                $relativePath = $this->relativizePath($realExcludePath, $cwd);
                $stderr->writeln("<comment>Warning: Exclude path {$relativePath} is not within any scanned path</comment>");
            }
        }
    }

    /**
     * @return array{list<string>, bool, list<string>|null} [paths, usingDefault, overriddenConfigPaths]
     *
     * @throws ErrorException
     */
    private function resolvePaths(
        InputInterface $input,
        Config $config,
    ): array
    {
        $cliPaths = $input->getArgument('paths');
        if (!is_array($cliPaths)) {
            throw new LogicException('Paths argument must be an array');
        }

        /** @var list<string> $paths */
        $paths = array_values(array_filter($cliPaths, is_string(...)));
        $configPaths = $config->getPaths();

        $usingDefault = false;
        $overriddenConfigPaths = null;

        if ($paths !== [] && $configPaths !== [] && $paths !== $configPaths) {
            $overriddenConfigPaths = $configPaths;
        }

        if ($paths === []) {
            $paths = $configPaths;
        }

        if ($paths === [] && is_dir('src')) {
            $paths = ['src'];
            $usingDefault = true;
        }

        if ($paths === []) {
            throw new ErrorException('No paths specified. Provide paths as arguments or configure them in the config file.');
        }

        foreach ($paths as $path) {
            if (!file_exists($path)) {
                throw new ErrorException("Path does not exist: {$path}");
            }
        }

        return [$paths, $usingDefault, $overriddenConfigPaths];
    }

    /**
     * @param list<string> $paths
     * @param list<string>|null $overriddenConfigPaths
     * @param list<string> $realExcludePaths
     */
    private function displayScanInfo(
        OutputInterface $stderr,
        string $cwd,
        array $paths,
        bool $usingDefaultPaths,
        ?array $overriddenConfigPaths,
        array $realExcludePaths,
        int $minNodeCount,
        bool $usingDefaultMinNodeCount,
        bool $cliOverrideMinNodeCount,
        string $cacheDir,
        bool $usingDefaultCacheDir,
        bool $cliOverrideCacheDir,
    ): void
    {
        $relativePaths = array_map(fn (string $path) => $this->relativizePath($path, $cwd), $paths);
        $pathsNote = $this->buildOptionNote($usingDefaultPaths, $overriddenConfigPaths !== null, 'paths argument');
        $stderr->writeln(sprintf('Scanning: %s%s', implode(', ', array_map(static fn (string $p) => "<fg=#aaaaaa>{$p}</>", $relativePaths)), $pathsNote));

        if ($realExcludePaths !== []) {
            $relativeExcludePaths = array_map(fn (string $path) => $this->relativizePath($path, $cwd), $realExcludePaths);
            $stderr->writeln(sprintf('Excluding: %s', implode(', ', array_map(static fn (string $p) => "<fg=#aaaaaa>{$p}</>", $relativeExcludePaths))));
        }

        $limitNote = $this->buildOptionNote($usingDefaultMinNodeCount, $cliOverrideMinNodeCount, '-m');
        $stderr->writeln(sprintf('Limit: <fg=#aaaaaa>≥%d nodes</>%s', $minNodeCount, $limitNote));

        $cacheNote = $this->buildOptionNote($usingDefaultCacheDir, $cliOverrideCacheDir, '--cache-dir');
        $stderr->writeln(sprintf('Cache: <fg=#aaaaaa>%s</>%s', $this->relativizePath($cacheDir, $cwd), $cacheNote));

        $stderr->writeln('');
    }

    private function buildOptionNote(
        bool $usingDefault,
        bool $cliOverride,
        string $optionName,
    ): string
    {
        if ($usingDefault) {
            return " <comment>(default, use {$optionName} to adjust)</comment>";
        }
        if ($cliOverride) {
            return ' <comment>(config value overridden by cli)</comment>';
        }
        return '';
    }

    /**
     * @param list<string> $files
     * @return list<CloneGroup>
     */
    private function detectClones(
        array $files,
        Configuration $configuration,
        string $cacheDir,
        OutputInterface $stderr,
    ): array
    {
        $cache = new SubtreeCache($cacheDir);
        $detector = new CloneDetector($configuration);

        return $detector->detect(
            $files,
            $configuration->getMinNodeCount(),
            $stderr,
            $cache,
        );
    }

    /**
     * @param list<CloneGroup> $cloneGroups
     */
    private function outputReport(
        array $cloneGroups,
        float $elapsedTime,
        OutputInterface $output,
    ): void
    {
        $highlighter = new SyntaxHighlighter($output->isDecorated());
        $reporter = new TextReporter($highlighter);
        $report = $reporter->report($cloneGroups, $elapsedTime);

        $output->writeln($report);
    }

    /**
     * Collect PHP files from multiple paths
     *
     * @param list<string> $paths
     * @param list<string> $realExcludePaths
     * @return list<string>
     *
     * @throws ErrorException
     */
    private function collectPhpFilesFromPaths(
        array $paths,
        array $realExcludePaths,
    ): array
    {
        $allFiles = [];

        foreach ($paths as $path) {
            foreach ($this->collectPhpFiles($path) as $file) {
                if (!$this->isExcluded($file, $realExcludePaths)) {
                    $allFiles[$file] = true;
                }
            }
        }

        return array_keys($allFiles);
    }

    /**
     * Check if a file path should be excluded
     *
     * @param list<string> $realExcludePaths
     *
     * @throws ErrorException
     */
    private function isExcluded(
        string $filePath,
        array $realExcludePaths,
    ): bool
    {
        $realFilePath = $this->resolveRealpath($filePath, "File path '$filePath' does not exist, should not happen");

        foreach ($realExcludePaths as $realExcludePath) {
            if ($realFilePath === $realExcludePath) {
                return true;
            }

            if (str_starts_with($realFilePath, $realExcludePath . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively collect all PHP files from a path
     *
     * @return list<string>
     */
    private function collectPhpFiles(string $path): array
    {
        if (is_file($path)) {
            return $this->isPhpFile($path) ? [$path] : [];
        }

        /** @var list<string> $files */
        $files = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            );
        } catch (UnexpectedValueException $e) {
            throw new LogicException("Path '$path' not found, should not happen, validated above", 0, $e);
        }

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                throw new LogicException('Iterator must yield SplFileInfo instances');
            }

            if ($file->isFile() && $this->isPhpFile($file->getPathname())) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Check if a file is a PHP file
     */
    private function isPhpFile(string $path): bool
    {
        return pathinfo($path, PATHINFO_EXTENSION) === 'php';
    }

    /**
     * Make a path relative to the given base path if it starts with it
     */
    private function relativizePath(
        string $path,
        string $basePath,
    ): string
    {
        $prefix = $basePath . '/';
        if (str_starts_with($path, $prefix)) {
            return './' . substr($path, strlen($prefix));
        }

        // Already relative path - add ./ prefix (but not for ../ paths)
        if (!str_starts_with($path, '/') && !str_starts_with($path, '../')) {
            return './' . $path;
        }

        return $path;
    }

    /**
     * Resolve a path to its real path, throwing an exception if it doesn't exist
     *
     * @throws ErrorException
     */
    private function resolveRealpath(
        string $path,
        string $errorMessage,
    ): string
    {
        $resolved = realpath($path);

        if ($resolved === false) {
            throw new ErrorException($errorMessage);
        }

        return $resolved;
    }

}
