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
use function pathinfo;
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

It uses subtree hashing (similar to CloneDR) to find exact structural duplicates.
Variable names and literals are anonymized, enabling Type-2 clone detection
(structurally identical code with different identifiers).

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
        $stderr = $this->getStderr($output);

        $cwd = getcwd();
        if ($cwd === false) {
            $stderr->writeln('<error>Error: Could not determine current working directory</error>');
            return Command::FAILURE;
        }

        $resolvedConfig = $this->loadConfiguration($input, $stderr, $cwd);
        if ($resolvedConfig === null) {
            return Command::FAILURE;
        }

        $config = $resolvedConfig->getConfig();
        $this->displayConfigPath($resolvedConfig, $stderr, $cwd);

        [$cacheDir, $usingDefaultCacheDir, $cliOverrideCacheDir] = $this->resolveCacheDir($input, $config);
        [$minNodeCountOverride, $usingDefaultMinNodeCount, $cliOverrideMinNodeCount] = $this->resolveMinNodeCount($input, $config);

        $pathsResult = $this->resolvePaths($input, $config, $stderr);
        if ($pathsResult === null) {
            return Command::FAILURE;
        }
        [$paths, $usingDefaultPaths, $overriddenConfigPaths] = $pathsResult;

        $configuration = Configuration::fromConfig($config, $minNodeCountOverride);

        $files = $this->collectPhpFilesFromPaths($paths);
        if (count($files) === 0) {
            $stderr->writeln('<error>Error: No PHP files found in specified paths</error>');
            return Command::FAILURE;
        }

        $this->displayScanInfo(
            $stderr,
            $cwd,
            $paths,
            $usingDefaultPaths,
            $overriddenConfigPaths,
            $configuration->getMinNodeCount(),
            $usingDefaultMinNodeCount,
            $cliOverrideMinNodeCount,
            $cacheDir,
            $usingDefaultCacheDir,
            $cliOverrideCacheDir,
        );

        $cloneGroups = $this->detectClones($files, $configuration, $cacheDir, $stderr);
        $this->outputReport($cloneGroups, $output);

        return Command::SUCCESS;
    }

    private function getStderr(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface
            ? $output->getErrorOutput()
            : $output;
    }

    private function loadConfiguration(
        InputInterface $input,
        OutputInterface $stderr,
        string $cwd,
    ): ?ResolvedConfig
    {
        $configPath = $input->getOption('config');

        if ($configPath !== null && !is_string($configPath)) {
            throw new LogicException('Config option must be a string or null');
        }

        $configResolver = new ConfigResolver($cwd);

        try {
            return $configResolver->resolveConfig($configPath);
        } catch (ErrorException $e) {
            $stderr->writeln("<error>Error loading configuration: {$e->getMessage()}</error>");
            return null;
        }
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
     * @return array{list<string>, bool, list<string>|null}|null [paths, usingDefault, overriddenConfigPaths] or null on error
     */
    private function resolvePaths(
        InputInterface $input,
        Config $config,
        OutputInterface $stderr,
    ): ?array
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
            $stderr->writeln('<error>Error: No paths specified. Provide paths as arguments or configure them in the config file.</error>');
            return null;
        }

        foreach ($paths as $path) {
            if (!file_exists($path)) {
                $stderr->writeln("<error>Error: Path does not exist: {$path}</error>");
                return null;
            }
        }

        return [$paths, $usingDefault, $overriddenConfigPaths];
    }

    /**
     * @param list<string> $paths
     * @param list<string>|null $overriddenConfigPaths
     */
    private function displayScanInfo(
        OutputInterface $stderr,
        string $cwd,
        array $paths,
        bool $usingDefaultPaths,
        ?array $overriddenConfigPaths,
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
        OutputInterface $output,
    ): void
    {
        $highlighter = new SyntaxHighlighter($output->isDecorated());
        $reporter = new TextReporter($highlighter);
        $report = $reporter->report($cloneGroups);

        $output->writeln($report);
    }

    /**
     * Collect PHP files from multiple paths
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private function collectPhpFilesFromPaths(array $paths): array
    {
        $allFiles = [];

        foreach ($paths as $path) {
            foreach ($this->collectPhpFiles($path) as $file) {
                $allFiles[$file] = true;
            }
        }

        return array_keys($allFiles);
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

}
