<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Detection;

use PhpParser\Node\Stmt;
use ShipMonk\CopyPasteDetector\AST\Parser;
use ShipMonk\CopyPasteDetector\AST\Subtree;
use ShipMonk\CopyPasteDetector\AST\SubtreeExtractor;
use ShipMonk\CopyPasteDetector\Cache\SubtreeCache;
use ShipMonk\CopyPasteDetector\Config\Config;
use ShipMonk\CopyPasteDetector\Exception\ErrorException;
use ShipMonk\CopyPasteDetector\Hashing\AstNormalizer;
use ShipMonk\CopyPasteDetector\Hashing\HashIndex;
use ShipMonk\CopyPasteDetector\Hashing\SubtreeHasher;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use function array_push;
use function count;
use function max;
use function stream_isatty;
use function usort;

/**
 * Main clone detection orchestrator
 * Coordinates the entire detection pipeline: parsing, hashing, and clone grouping
 *
 * Uses subtree hashing approach (similar to CloneDR):
 * - Normalizes AST subtrees (anonymizes identifiers/literals)
 * - Computes MD5 fingerprints of normalized subtrees
 * - Groups subtrees by hash - identical hashes = Type-2 clones
 */
final class CloneDetector
{

    private readonly Parser $parser;
    private readonly SubtreeExtractor $subtreeExtractor;
    private readonly SubsumptionFilter $subsumptionFilter;
    private ?OutputInterface $output = null;

    public function __construct(Config $config)
    {
        $this->parser = new Parser();
        $normalizer = new AstNormalizer(
            $config->shouldAnonymizeVariables(),
            $config->shouldAnonymizeLiterals(),
            $config->shouldAnonymizeNames(),
            $config->shouldAnonymizeIdentifiers(),
        );
        $this->subtreeExtractor = new SubtreeExtractor(new SubtreeHasher($normalizer));
        $this->subsumptionFilter = new SubsumptionFilter();
    }

    /**
     * Detect clones in a set of PHP files
     *
     * @param list<string> $filePaths Array of PHP file paths to analyze
     * @param int $minNodeCount Minimum number of nodes for a subtree to be considered
     * @param OutputInterface|null $output Optional output interface for verbose logging
     * @param SubtreeCache|null $cache Optional cache for storing/retrieving subtrees
     * @param ChangedLines|null $changedLines When provided, only clone groups containing ≥1 instance
     *                                        inside the changed lines are reported.
     * @return list<CloneGroup> Array of detected clone groups (each containing 2+ identical subtrees)
     *
     * @throws ErrorException
     */
    public function detect(
        array $filePaths,
        int $minNodeCount,
        ?OutputInterface $output,
        ?SubtreeCache $cache,
        ?ChangedLines $changedLines = null,
    ): array
    {
        $this->output = $output;

        // Parse files and extract subtrees (with caching)
        $allSubtrees = $this->parseAndExtractSubtrees($filePaths, $minNodeCount, $cache);

        // Build hash index and create clone groups
        $hashIndex = $this->buildHashIndex($allSubtrees);

        $cloneGroups = $this->createCloneGroups($hashIndex);

        // Patch filter must run before subsumption
        if ($changedLines !== null) {
            $cloneGroups = (new PatchFilter($changedLines))->filter($cloneGroups);
        }

        $cloneGroups = $this->subsumptionFilter->filter($cloneGroups);
        $this->sortCloneGroups($cloneGroups);

        return $cloneGroups;
    }

    /**
     * Parse files and extract subtrees with caching support
     *
     * @param list<string> $filePaths
     * @return list<Subtree>
     *
     * @throws ErrorException
     */
    private function parseAndExtractSubtrees(
        array $filePaths,
        int $minNodeCount,
        ?SubtreeCache $cache,
    ): array
    {
        $allSubtrees = [];
        $totalFiles = count($filePaths);

        $progressBar = $this->createProgressBar($totalFiles);

        foreach ($filePaths as $filePath) {
            // Try cache first
            $cachedSubtrees = $cache?->get($filePath, $minNodeCount);
            if ($cachedSubtrees !== null) {
                array_push($allSubtrees, ...$cachedSubtrees);
                $progressBar?->advance();
                continue;
            }

            $ast = $this->parseFile($filePath);
            $subtrees = $this->subtreeExtractor->extract($ast, $filePath, $minNodeCount);
            array_push($allSubtrees, ...$subtrees);
            $cache?->set($filePath, $minNodeCount, $subtrees);

            $progressBar?->advance();
        }

        if ($progressBar !== null) {
            $progressBar->finish();
            if ($this->output !== null) {
                $this->output->writeln('');
            }
        }

        return $allSubtrees;
    }

    /**
     * Parse a single file, returning AST
     *
     * @return list<Stmt>
     *
     * @throws ErrorException
     */
    private function parseFile(string $filePath): array
    {
        return $this->parser->parseFile($filePath);
    }

    /**
     * Create and configure a modern-looking progress bar
     */
    private function createProgressBar(int $max): ?ProgressBar
    {
        if ($this->output === null) {
            return null;
        }

        $progressBar = new ProgressBar($this->output, $max);

        // Symfony animates the bar in place whenever the output is "decorated",
        // which --ansi forces on regardless of whether a real terminal is attached.
        // The in-place redraw relies on cursor-control escape sequences that CI log
        // viewers (e.g. GitLab) do not collapse, so every redraw frame ends up
        // stacked in the log. Only animate in place on an interactive terminal;
        // otherwise emit one line per redraw (like PHPStan), which stays readable in
        // CI while keeping colors intact. Non-interactive output redraws far less
        // frequently so the log stays compact.
        $interactive = $this->outputIsInteractive();
        $progressBar->setOverwrite($interactive);
        $progressBar->setRedrawFrequency(max(1, (int) ($max / ($interactive ? 50 : 10))));
        $progressBar->minSecondsBetweenRedraws($interactive ? 0.1 : 1.0);
        $progressBar->maxSecondsBetweenRedraws($interactive ? 1.0 : 10.0);

        // Percentage Fill style - clean visual percentage
        if ($this->output->isDebug() || $this->output->isVeryVerbose()) {
            // Detailed format with ETA and memory
            $progressBar->setFormat(
                ' %current%/%max% %bar% %percent:3s%% %remaining:-10s% %memory:6s%',
            );
        } else {
            // Simpler format
            $progressBar->setFormat(
                ' %current%/%max% %bar% %percent:3s%% %remaining:-10s%',
            );
        }

        $progressBar->setBarCharacter('▓');
        $progressBar->setEmptyBarCharacter('░');
        $progressBar->setProgressCharacter('▓');
        $progressBar->setBarWidth(40);

        $progressBar->start();

        return $progressBar;
    }

    /**
     * Whether the progress output is attached to a real interactive terminal.
     * Used to decide between in-place animation (TTY) and one-line-per-redraw (CI/pipes).
     */
    private function outputIsInteractive(): bool
    {
        $output = $this->output;

        if ($output instanceof StreamOutput) {
            return stream_isatty($output->getStream());
        }

        return false;
    }

    /**
     * Build hash index from subtrees
     *
     * @param list<Subtree> $subtrees
     */
    private function buildHashIndex(array $subtrees): HashIndex
    {
        $hashIndex = new HashIndex();

        foreach ($subtrees as $subtree) {
            $hashIndex->index($subtree);
        }

        return $hashIndex;
    }

    /**
     * Create and sort clone groups from hash index
     *
     * @return list<CloneGroup>
     */
    private function createCloneGroups(HashIndex $hashIndex): array
    {
        $hashGroups = $hashIndex->getCloneGroups();
        $cloneGroups = [];

        foreach ($hashGroups as $subtrees) {
            $cloneGroups[] = new CloneGroup($subtrees);
        }

        return $cloneGroups;
    }

    /**
     * Sort clone groups by size and instance count
     *
     * @param list<CloneGroup> $cloneGroups
     */
    private function sortCloneGroups(array &$cloneGroups): void
    {
        usort($cloneGroups, static function (CloneGroup $a, CloneGroup $b): int {
            $sizeComparison = $b->getNodeCount() <=> $a->getNodeCount();
            if ($sizeComparison !== 0) {
                return $sizeComparison;
            }
            return $b->getInstanceCount() <=> $a->getInstanceCount();
        });
    }

}
