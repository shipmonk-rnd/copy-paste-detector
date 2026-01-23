<?php declare(strict_types = 1);

namespace CopyPasteDetector\Detection;

use CopyPasteDetector\AST\Parser;
use CopyPasteDetector\AST\Subtree;
use CopyPasteDetector\AST\SubtreeExtractor;
use CopyPasteDetector\Cache\SubtreeCache;
use CopyPasteDetector\Config\Configuration;
use CopyPasteDetector\Exception\ErrorException;
use CopyPasteDetector\Hashing\AstNormalizer;
use CopyPasteDetector\Hashing\HashIndex;
use CopyPasteDetector\Hashing\SubtreeHasher;
use LogicException;
use PhpParser\Node\Stmt;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use function array_merge;
use function count;
use function max;
use function microtime;
use function sprintf;
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
    private ?OutputInterface $output = null;

    public function __construct(Configuration $configuration)
    {
        $this->parser = new Parser();
        $normalizer = new AstNormalizer(
            $configuration->shouldAnonymizeVariables(),
            $configuration->shouldAnonymizeLiterals(),
            $configuration->shouldAnonymizeNames(),
            $configuration->shouldAnonymizeIdentifiers(),
        );
        $this->subtreeExtractor = new SubtreeExtractor(new SubtreeHasher($normalizer));
    }

    /**
     * Log a message in verbose mode
     */
    private function logVerbose(string $message): void
    {
        if ($this->output !== null && $this->output->isVerbose()) {
            $this->output->writeln(
                sprintf('<comment>%s</comment>', $message),
                OutputInterface::VERBOSITY_VERBOSE,
            );
        }
    }

    /**
     * Detect clones in a set of PHP files
     *
     * @param list<string> $filePaths Array of PHP file paths to analyze
     * @param int $minNodeCount Minimum number of nodes for a subtree to be considered
     * @param OutputInterface|null $output Optional output interface for verbose logging
     * @param SubtreeCache|null $cache Optional cache for storing/retrieving subtrees
     * @return list<CloneGroup> Array of detected clone groups (each containing 2+ identical subtrees)
     */
    public function detect(
        array $filePaths,
        int $minNodeCount = Configuration::DEFAULT_MIN_NODE_COUNT,
        ?OutputInterface $output = null,
        ?SubtreeCache $cache = null,
    ): array
    {
        $this->output = $output;
        $startTime = microtime(true);

        // Parse files and extract subtrees (with caching)
        $allSubtrees = $this->parseAndExtractSubtrees($filePaths, $minNodeCount, $cache);

        // Build hash index and create clone groups
        $hashIndex = $this->buildHashIndex($allSubtrees);
        $cloneGroups = $this->createCloneGroups($hashIndex);

        // Log total time
        $totalTime = microtime(true) - $startTime;
        $this->logVerbose(sprintf('Total time: %.3fs', $totalTime));

        return $cloneGroups;
    }

    /**
     * Parse files and extract subtrees with caching support
     *
     * @param list<string> $filePaths
     * @return list<Subtree>
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
                $allSubtrees = array_merge($allSubtrees, $cachedSubtrees);
                $progressBar?->advance();
                continue;
            }

            $ast = $this->parseFile($filePath);
            $subtrees = $this->subtreeExtractor->extract($ast, $filePath, $minNodeCount);
            $allSubtrees = array_merge($allSubtrees, $subtrees);
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
     */
    private function parseFile(string $filePath): array
    {
        try {
            return $this->parser->parseFile($filePath);
        } catch (ErrorException $e) {
            throw new LogicException("Failed to parse '{$filePath}': {$e->getMessage()}", 0, $e);
        }
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
        $progressBar->setRedrawFrequency(max(1, (int) ($max / 50)));
        $progressBar->minSecondsBetweenRedraws(0.1);
        $progressBar->maxSecondsBetweenRedraws(1.0);

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
            if (count($subtrees) >= 2) {
                $cloneGroups[] = new CloneGroup($subtrees);
            }
        }

        $this->sortCloneGroups($cloneGroups);

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
