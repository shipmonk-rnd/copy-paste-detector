<?php declare(strict_types = 1);

namespace CopyPasteDetector\Tests;

use CopyPasteDetector\AST\NodeCounter;
use CopyPasteDetector\AST\Parser;
use CopyPasteDetector\Exception\ErrorException;
use LogicException;
use PHPUnit\Framework\TestCase;
use function array_key_exists;

final class NodeCounterTest extends TestCase
{

    private NodeCounter $counter;
    private Parser $parser;

    protected function setUp(): void
    {
        $this->counter = new NodeCounter();
        $this->parser = new Parser();
    }

    /**
     * @throws ErrorException
     */
    public function testCountSimpleExpression(): void
    {
        $code = '<?php $x = 1;';
        $ast = $this->parser->parse($code);

        if (!array_key_exists(0, $ast)) {
            throw new LogicException('Parsed AST must contain at least one node');
        }

        $count = $this->counter->count($ast[0]);

        // Should count: Expression statement + Assignment + Variable + Scalar
        self::assertGreaterThan(0, $count);
    }

    /**
     * @throws ErrorException
     */
    public function testCountComplexStructure(): void
    {
        $code = '<?php
            function foo($a, $b) {
                if ($a > $b) {
                    return $a;
                } else {
                    return $b;
                }
            }
        ';

        $ast = $this->parser->parse($code);

        if (!array_key_exists(0, $ast)) {
            throw new LogicException('Parsed AST must contain at least one node');
        }

        $count = $this->counter->count($ast[0]);

        // Function with if-else should have many nodes
        self::assertGreaterThan(10, $count);
    }

}
