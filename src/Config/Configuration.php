<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Config;

use LogicException;

/**
 * Runtime configuration for clone detection
 * This is built from CLI options and Config file
 */
final class Configuration
{

    public const DEFAULT_MIN_NODE_COUNT = 50;

    private int $minNodeCount;
    private bool $anonymizeVariables;
    private bool $anonymizeLiterals;
    private bool $anonymizeNames;
    private bool $anonymizeIdentifiers;

    public function __construct(
        int $minNodeCount,
        bool $anonymizeVariables,
        bool $anonymizeLiterals,
        bool $anonymizeNames,
        bool $anonymizeIdentifiers,
    )
    {
        if ($minNodeCount < 1) {
            throw new LogicException('minNodeCount must be at least 1');
        }

        $this->minNodeCount = $minNodeCount;
        $this->anonymizeVariables = $anonymizeVariables;
        $this->anonymizeLiterals = $anonymizeLiterals;
        $this->anonymizeNames = $anonymizeNames;
        $this->anonymizeIdentifiers = $anonymizeIdentifiers;
    }

    /**
     * Create Configuration from Config object and CLI overrides
     */
    public static function fromConfig(
        Config $config,
        ?int $minNodeCountOverride,
    ): self
    {
        return new self(
            $minNodeCountOverride ?? $config->getMinNodeCount() ?? self::DEFAULT_MIN_NODE_COUNT,
            $config->shouldAnonymizeVariables(),
            $config->shouldAnonymizeLiterals(),
            $config->shouldAnonymizeNames(),
            $config->shouldAnonymizeIdentifiers(),
        );
    }

    public function getMinNodeCount(): int
    {
        return $this->minNodeCount;
    }

    public function shouldAnonymizeVariables(): bool
    {
        return $this->anonymizeVariables;
    }

    public function shouldAnonymizeLiterals(): bool
    {
        return $this->anonymizeLiterals;
    }

    public function shouldAnonymizeNames(): bool
    {
        return $this->anonymizeNames;
    }

    public function shouldAnonymizeIdentifiers(): bool
    {
        return $this->anonymizeIdentifiers;
    }

}
