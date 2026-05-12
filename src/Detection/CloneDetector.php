<?php declare(strict_types = 1);

namespace ShipMonk\CopyPasteDetector\Detection;

use PhpParser\Node\Stmt;
use ShipMonk\CopyPasteDetector\AST\Parser;
use ShipMonk\CopyPasteDetector\AST\SiblingList;
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
use function array_merge;
use function array_push;
use function count;
use function max;
use function usort;

/**
 * Main clone detection orchestrator
 * Coordinates the entire detection pipeline: parsing, hashing, and clone grouping
 *
 * Uses subtree hashing approach (similar to CloneDR):
 * - Normalizes AST subtrees (anonymizes identifiers/literals)
 * - Computes MD5 fingerprints of normalized subtrees
 * - Groups subtrees by hash - identical hashes = Type-2 clones
 *
 * Additionally runs a sequence-clone pass over sibling-stmt lists to catch
 * copy-pasted statement blocks that don't correspond to a single AST node.
 */
final class CloneDetector
{

    private readonly Parser $parser;
    private readonly SubtreeExtractor $subtreeExtractor;
    private readonly SubsumptionFilter $subsumptionFilter;
    private readonly ?SequenceCloneDetector $sequenceDetector;
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
        $this->sequenceDetector = $config->isSequenceDetectionEnabled()
            ? new SequenceCloneDetector($config->getSequenceMinStmts())
            : null;
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

        [$allSubtrees, $allSiblingLists] = $this->parseAndExtract($filePaths, $minNodeCount, $cache);

        $hashIndex = $this->buildHashIndex($allSubtrees);
        $cloneGroups = $this->createCloneGroups($hashIndex);

        if ($this->sequenceDetector !== null) {
            $cloneGroups = array_merge(
                $cloneGroups,
                $this->sequenceDetector->detect($allSiblingLists, $minNodeCount),
            );
        }

        // Patch filter must run before subsumption
        if ($changedLines !== null) {
            $cloneGroups = (new PatchFilter($changedLines))->filter($cloneGroups);
        }

        $cloneGroups = $this->subsumptionFilter->filter($cloneGroups);
        $this->sortCloneGroups($cloneGroups);

        return $cloneGroups;
    }

    /**
     * Parse files and extract subtrees (with caching) plus sibling-stmt lists.
     *
     * @param list<string> $filePaths
     * @return array{0: list<Subtree>, 1: list<SiblingList>}
     *
     * @throws ErrorException
     */
    private function parseAndExtract(
        array $filePaths,
        int $minNodeCount,
        ?SubtreeCache $cache,
    ): array
    {
        $allSubtrees = [];
        $allSiblingLists = [];
        $totalFiles = count($filePaths);

        $progressBar = $this->createProgressBar($totalFiles);

        foreach ($filePaths as $filePath) {
            $cached = $cache?->get($filePath, $minNodeCount);
            if ($cached !== null) {
                array_push($allSubtrees, ...$cached->getSubtrees());
                array_push($allSiblingLists, ...$cached->getSiblingLists());
                $progressBar?->advance();
                continue;
            }

            $ast = $this->parseFile($filePath);
            $result = $this->subtreeExtractor->extract($ast, $filePath, $minNodeCount);

            array_push($allSubtrees, ...$result->getSubtrees());
            array_push($allSiblingLists, ...$result->getSiblingLists());

            $cache?->set($filePath, $minNodeCount, $result);

            $progressBar?->advance();
        }

        if ($progressBar !== null) {
            $progressBar->finish();
            if ($this->output !== null) {
                $this->output->writeln('');
            }
        }

        return [$allSubtrees, $allSiblingLists];
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
