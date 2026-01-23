<?php declare(strict_types = 1);

namespace CopyPasteDetector\Config;

use CopyPasteDetector\Exception\ErrorException;
use Throwable;
use function is_file;
use function str_ends_with;

final class ConfigResolver
{

    public function __construct(
        private readonly string $cwd,
    )
    {
    }

    /**
     * @throws ErrorException
     */
    public function resolveConfig(?string $configPath): ResolvedConfig
    {
        $wasAutoDetected = $configPath === null;
        $resolvedConfigPath = $this->resolveConfigPath($configPath);

        if (is_file($resolvedConfigPath)) {
            return new ResolvedConfig(
                $this->loadConfig($resolvedConfigPath),
                $resolvedConfigPath,
                $wasAutoDetected,
            );
        }

        return new ResolvedConfig(
            new Config(),
            null,
            $wasAutoDetected,
        );
    }

    /**
     * @throws ErrorException
     */
    private function resolveConfigPath(?string $configPath): string
    {
        if ($configPath !== null) {
            if (!is_file($configPath)) {
                throw new ErrorException("Provided config file not found: '{$configPath}'");
            }

            if (!str_ends_with($configPath, '.php')) {
                throw new ErrorException("Provided config file must have php extension: '{$configPath}'");
            }

            return $configPath;
        }

        return $this->cwd . '/copy-paste-detector.php';
    }

    /**
     * @throws ErrorException
     */
    private function loadConfig(string $configFile): Config
    {
        $loadedConfig = static function () use ($configFile): mixed {
            return require $configFile;
        };

        try {
            $result = $loadedConfig();
        } catch (Throwable $e) {
            $line = $e->getLine();
            $file = $e->getFile();
            $position = $file === $configFile ? "line $line" : "$file:$line";
            throw new ErrorException($e::class . " while loading config file '$configFile' at $position. " . $e->getMessage(), $e);
        }

        if (!$result instanceof Config) {
            throw new ErrorException("Config file '$configFile' must return an instance of " . Config::class);
        }

        return $result;
    }

}
