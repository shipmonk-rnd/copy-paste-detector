<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Config;

use LogicException;

/**
 * This class is expected to be returned from a config file passed via --config option.
 *
 * @api
 */
final class Config
{

    private ?int $minNodeCount = null;
    private ?string $cacheDir = null;

    /**
     * @var list<string>
     */
    private array $paths = [];

    /**
     * @var list<string>
     */
    private array $excludePaths = [];

    private ?string $editorUrl = null;

    private bool $anonymizeVariables = true;
    private bool $anonymizeLiterals = false;
    private bool $anonymizeNames = false;
    private bool $anonymizeIdentifiers = false;

    private bool $sequenceDetectionEnabled = true;
    private int $sequenceMinStmts = 3;

    public function __construct()
    {
    }

    /**
     * Set the minimum number of nodes for a subtree to be considered a clone.
     * Higher values mean fewer but larger clones will be detected.
     *
     * @throws LogicException
     */
    public function setMinNodeCount(int $minNodeCount): self
    {
        if ($minNodeCount < 1) {
            throw new LogicException('minNodeCount must be at least 1');
        }

        $this->minNodeCount = $minNodeCount;
        return $this;
    }

    /**
     * Set the directory for caching parsed subtrees.
     * Default: system temp directory + '/copy-paste-detector-cache'
     */
    public function setCacheDir(string $cacheDir): self
    {
        $this->cacheDir = $cacheDir;
        return $this;
    }

    /**
     * Set paths to analyze for clones.
     * Can be overridden by CLI arguments.
     *
     * @param list<string> $paths
     */
    public function setPaths(array $paths): self
    {
        $this->paths = $paths;
        return $this;
    }

    /**
     * Add a path to analyze for clones.
     */
    public function addPath(string $path): self
    {
        $this->paths[] = $path;
        return $this;
    }

    /**
     * Set paths to exclude from analysis.
     * Files matching these paths will be skipped.
     *
     * @param list<string> $paths
     */
    public function setExcludePaths(array $paths): self
    {
        $this->excludePaths = $paths;
        return $this;
    }

    /**
     * Add a path to exclude from analysis.
     */
    public function addExcludePath(string $path): self
    {
        $this->excludePaths[] = $path;
        return $this;
    }

    /**
     * Set the editor URL pattern to make filepaths clickable in CLI output via OSC 8 hyperlink.
     *
     * Available placeholders:
     * - {file} - Absolute file path
     * - {relFile} - Relative file path (from current working directory)
     * - {line} - Line number
     *
     * Common editor URL patterns:
     * - PHPStorm: phpstorm://open?file={file}&line={line}
     * - VS Code: vscode://file/{file}:{line}
     * - Sublime: subl://open?url=file://{file}&line={line}
     */
    public function setEditorUrl(string $editorUrl): self
    {
        $this->editorUrl = $editorUrl;
        return $this;
    }

    /**
     * Enable or disable variable name anonymization.
     * When enabled, variable names like $foo, $bar will be treated as equivalent (default: true).
     */
    public function setAnonymizeVariables(bool $anonymize): self
    {
        $this->anonymizeVariables = $anonymize;
        return $this;
    }

    /**
     * Enable or disable literal anonymization.
     * When enabled, string and numeric literals will be treated as equivalent (default: false).
     */
    public function setAnonymizeLiterals(bool $anonymize): self
    {
        $this->anonymizeLiterals = $anonymize;
        return $this;
    }

    /**
     * Enable or disable name anonymization (function names, class names).
     * When enabled, names will be treated as equivalent (default: false).
     */
    public function setAnonymizeNames(bool $anonymize): self
    {
        $this->anonymizeNames = $anonymize;
        return $this;
    }

    /**
     * Enable or disable identifier anonymization (method names, constant names).
     * When enabled, identifiers will be treated as equivalent (default: false).
     */
    public function setAnonymizeIdentifiers(bool $anonymize): self
    {
        $this->anonymizeIdentifiers = $anonymize;
        return $this;
    }

    public function getMinNodeCount(): ?int
    {
        return $this->minNodeCount;
    }

    public function getCacheDir(): ?string
    {
        return $this->cacheDir;
    }

    public function getEditorUrl(): ?string
    {
        return $this->editorUrl;
    }

    /**
     * @return list<string>
     */
    public function getPaths(): array
    {
        return $this->paths;
    }

    /**
     * @return list<string>
     */
    public function getExcludePaths(): array
    {
        return $this->excludePaths;
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

    /**
     * Enable or disable detection of cloned sequences of sibling statements
     * (statement-list slices that aren't bounded by a single AST node).
     * Default: true.
     */
    public function setSequenceDetectionEnabled(bool $enabled): self
    {
        $this->sequenceDetectionEnabled = $enabled;
        return $this;
    }

    /**
     * Minimum number of consecutive statements required for a sequence clone.
     * Lower values find more (and shorter) clones. Default: 3.
     *
     * @throws LogicException
     */
    public function setSequenceMinStmts(int $minStmts): self
    {
        if ($minStmts < 2) {
            throw new LogicException('sequenceMinStmts must be at least 2');
        }

        $this->sequenceMinStmts = $minStmts;
        return $this;
    }

    public function isSequenceDetectionEnabled(): bool
    {
        return $this->sequenceDetectionEnabled;
    }

    public function getSequenceMinStmts(): int
    {
        return $this->sequenceMinStmts;
    }

    /**
     * Get the anonymization settings as an immutable value object
     */
    public function getAnonymizationSettings(): AnonymizationSettings
    {
        return new AnonymizationSettings(
            variables: $this->anonymizeVariables,
            literals: $this->anonymizeLiterals,
            names: $this->anonymizeNames,
            identifiers: $this->anonymizeIdentifiers,
        );
    }

}
