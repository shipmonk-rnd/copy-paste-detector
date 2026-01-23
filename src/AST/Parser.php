<?php declare(strict_types = 1);

namespace CopyPasteDetector\AST;

use CopyPasteDetector\Exception\ErrorException;
use PhpParser\Error as ParserError;
use PhpParser\Node\Stmt;
use PhpParser\Parser as PhpParser;
use PhpParser\ParserFactory;
use function array_values;
use function file_exists;
use function file_get_contents;
use function is_readable;

/**
 * Wrapper around nikic/php-parser for parsing PHP source code into ASTs
 */
final class Parser
{

    private PhpParser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Parse PHP source code into an AST
     *
     * @param string $code PHP source code
     * @return list<Stmt> Array of AST nodes (statements)
     *
     * @throws ErrorException if parsing fails
     */
    public function parse(string $code): array
    {
        try {
            /** @throws ParserError */
            $ast = $this->parser->parse($code);
            if ($ast === null) {
                throw new ErrorException('Failed to parse code: parser returned null');
            }

            return array_values($ast);
        } catch (ParserError $e) {
            throw new ErrorException('Parse error: ' . $e->getMessage(), $e);
        }
    }

    /**
     * Parse a PHP file into an AST
     *
     * @param string $filePath Path to PHP file
     * @return list<Stmt> Array of AST nodes (statements)
     *
     * @throws ErrorException if file cannot be read or parsing fails
     */
    public function parseFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new ErrorException("File not found: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new ErrorException("File not readable: {$filePath}");
        }

        $code = file_get_contents($filePath);
        if ($code === false) {
            throw new ErrorException("Failed to read file: {$filePath}");
        }

        return $this->parse($code);
    }

}
