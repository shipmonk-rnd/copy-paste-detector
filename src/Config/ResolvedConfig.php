<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Config;

/**
 * Result of config resolution containing the config and metadata about how it was resolved
 */
final class ResolvedConfig
{

    public function __construct(
        private readonly Config $config,
        private readonly ?string $usedConfigPath,
        private readonly bool $wasAutoDetected,
    )
    {
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Returns the path to the config file that was loaded, or null if using defaults
     */
    public function getUsedConfigPath(): ?string
    {
        return $this->usedConfigPath;
    }

    /**
     * Returns true if the config file was auto-detected (not explicitly provided via --config)
     */
    public function wasAutoDetected(): bool
    {
        return $this->wasAutoDetected;
    }

}
