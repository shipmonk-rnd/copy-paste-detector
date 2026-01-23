<?php declare(strict_types = 1);

namespace CopyPasteDetector\Tests;

use CopyPasteDetector\AST\Parser;
use CopyPasteDetector\Exception\ErrorException;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{

    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser();
    }

    /**
     * @throws ErrorException
     */
    public function testParseValidCode(): void
    {
        $code = '<?php $x = 1; $y = 2;';
        $ast = $this->parser->parse($code);

        self::assertNotEmpty($ast);
        self::assertCount(2, $ast); // Two statements
    }

    /**
     * @throws ErrorException
     */
    public function testParseInvalidCode(): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Parse error');

        $code = '<?php $x = ;'; // Invalid syntax
        $this->parser->parse($code);
    }

    /**
     * @throws ErrorException
     */
    public function testParseFileNotFound(): void
    {
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('File not found');

        $this->parser->parseFile('/nonexistent/file.php');
    }

}
