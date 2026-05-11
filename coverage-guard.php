<?php declare(strict_types = 1);

use ShipMonk\CopyPasteDetector\AST\Parser;
use ShipMonk\CopyPasteDetector\AST\SubtreeExtractor;
use ShipMonk\CopyPasteDetector\Detection\CloneDetector;
use ShipMonk\CopyPasteDetector\Hashing\AstNormalizer;
use ShipMonk\CopyPasteDetector\Hashing\HashIndex;
use ShipMonk\CopyPasteDetector\Hashing\SubtreeHasher;
use ShipMonk\CoverageGuard\Config;
use ShipMonk\CoverageGuard\Hierarchy\ClassMethodBlock;
use ShipMonk\CoverageGuard\Hierarchy\CodeBlock;
use ShipMonk\CoverageGuard\Rule\CoverageError;
use ShipMonk\CoverageGuard\Rule\CoverageRule;
use ShipMonk\CoverageGuard\Rule\InspectionContext;

$config = new Config();
$config->addRule(new class implements CoverageRule {

    public function inspect(
        CodeBlock $codeBlock,
        InspectionContext $context,
    ): ?CoverageError
    {
        if (!$codeBlock instanceof ClassMethodBlock) {
            return null;
        }

        if ($codeBlock->getExecutableLinesCount() < 5) {
            return null;
        }

        $methodReflection = $context->getMethodReflection();
        if ($methodReflection === null) {
            return null;
        }

        $coverage = $codeBlock->getCoveragePercentage();
        $classReflection = $methodReflection->getDeclaringClass();
        $requiredCoverage = $this->getRequiredCoverage($classReflection);

        if ($coverage < $requiredCoverage) {
            return CoverageError::create("Method <bold>{$codeBlock->getMethodName()}</bold> requires $requiredCoverage% coverage, but has only $coverage%.");
        }

        return null;
    }

    /**
     * @param ReflectionClass<object> $classReflection
     */
    private function getRequiredCoverage(ReflectionClass $classReflection): int
    {
        $isCore = in_array($classReflection->getName(), [
            CloneDetector::class,
            SubtreeExtractor::class,
            SubtreeHasher::class,
            AstNormalizer::class,
            HashIndex::class,
            Parser::class,
        ], true);

        return match (true) {
            $isCore => 80,
            default => 60,
        };
    }

});

return $config;
