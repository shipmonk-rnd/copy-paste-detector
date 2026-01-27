<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Config;

/**
 * Immutable value object representing anonymization settings
 */
final class AnonymizationSettings
{

    public function __construct(
        public readonly bool $variables,
        public readonly bool $literals,
        public readonly bool $names,
        public readonly bool $identifiers,
    )
    {
    }

}
