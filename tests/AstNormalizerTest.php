<?php declare(strict_types = 1);

namespace CopyPasteDetector\Tests;

use CopyPasteDetector\AST\Parser;
use CopyPasteDetector\Hashing\AstNormalizer;
use CopyPasteDetector\Hashing\SubtreeHasher;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\TestCase;

final class AstNormalizerTest extends TestCase
{

    private Parser $parser;

    protected function setUp(): void
    {
        $this->parser = new Parser();
    }

    public function testAnonymizeVariablesProducesSameHashForDifferentVarNames(): void
    {
        $code1 = '<?php
            function foo($input) {
                $result = $input + 1;
                return $result;
            }
        ';

        $code2 = '<?php
            function foo($value) {
                $output = $value + 1;
                return $output;
            }
        ';

        $normalizer = new AstNormalizer(
            anonymizeVariables: true,
            anonymizeLiterals: false,
            anonymizeNames: false,
            anonymizeIdentifiers: false,
        );
        $hasher = new SubtreeHasher($normalizer);

        $ast1 = $this->parser->parse($code1);
        $ast2 = $this->parser->parse($code2);
        self::assertArrayHasKey(0, $ast1);
        self::assertArrayHasKey(0, $ast2);

        // Both should have same hash since only variable names differ
        $hash1 = $hasher->hashNode($ast1[0]);
        $hash2 = $hasher->hashNode($ast2[0]);

        self::assertSame($hash1, $hash2);
    }

    public function testNoAnonymizeVariablesProducesDifferentHashForDifferentVarNames(): void
    {
        $code1 = '<?php $foo = 1;';
        $code2 = '<?php $bar = 1;';

        $normalizer = new AstNormalizer(
            anonymizeVariables: false,
            anonymizeLiterals: false,
            anonymizeNames: false,
            anonymizeIdentifiers: false,
        );
        $hasher = new SubtreeHasher($normalizer);

        $ast1 = $this->parser->parse($code1);
        $ast2 = $this->parser->parse($code2);
        self::assertArrayHasKey(0, $ast1);
        self::assertArrayHasKey(0, $ast2);

        $hash1 = $hasher->hashNode($ast1[0]);
        $hash2 = $hasher->hashNode($ast2[0]);

        self::assertNotSame($hash1, $hash2);
    }

    public function testAnonymizeLiteralsProducesSameHashForDifferentStrings(): void
    {
        $code1 = '<?php $x = "hello";';
        $code2 = '<?php $x = "world";';

        $normalizer = new AstNormalizer(
            anonymizeVariables: true,
            anonymizeLiterals: true,
            anonymizeNames: false,
            anonymizeIdentifiers: false,
        );
        $hasher = new SubtreeHasher($normalizer);

        $ast1 = $this->parser->parse($code1);
        $ast2 = $this->parser->parse($code2);
        self::assertArrayHasKey(0, $ast1);
        self::assertArrayHasKey(0, $ast2);

        $hash1 = $hasher->hashNode($ast1[0]);
        $hash2 = $hasher->hashNode($ast2[0]);

        self::assertSame($hash1, $hash2);
    }

    public function testAnonymizeLiteralsProducesSameHashForDifferentIntegers(): void
    {
        $code1 = '<?php $x = 42;';
        $code2 = '<?php $x = 999;';

        $normalizer = new AstNormalizer(
            anonymizeVariables: true,
            anonymizeLiterals: true,
            anonymizeNames: false,
            anonymizeIdentifiers: false,
        );
        $hasher = new SubtreeHasher($normalizer);

        $ast1 = $this->parser->parse($code1);
        $ast2 = $this->parser->parse($code2);
        self::assertArrayHasKey(0, $ast1);
        self::assertArrayHasKey(0, $ast2);

        $hash1 = $hasher->hashNode($ast1[0]);
        $hash2 = $hasher->hashNode($ast2[0]);

        self::assertSame($hash1, $hash2);
    }

    public function testAnonymizeLiteralsProducesSameHashForDifferentFloats(): void
    {
        $code1 = '<?php $x = 3.14;';
        $code2 = '<?php $x = 2.71;';

        $normalizer = new AstNormalizer(
            anonymizeVariables: true,
            anonymizeLiterals: true,
            anonymizeNames: false,
            anonymizeIdentifiers: false,
        );
        $hasher = new SubtreeHasher($normalizer);

        $ast1 = $this->parser->parse($code1);
        $ast2 = $this->parser->parse($code2);
        self::assertArrayHasKey(0, $ast1);
        self::assertArrayHasKey(0, $ast2);

        $hash1 = $hasher->hashNode($ast1[0]);
        $hash2 = $hasher->hashNode($ast2[0]);

        self::assertSame($hash1, $hash2);
    }

    public function testNoAnonymizeLiteralsProducesDifferentHashForDifferentLiterals(): void
    {
        $code1 = '<?php $x = "hello";';
        $code2 = '<?php $x = "world";';

        $normalizer = new AstNormalizer(
            anonymizeVariables: true,
            anonymizeLiterals: false,
            anonymizeNames: false,
            anonymizeIdentifiers: false,
        );
        $hasher = new SubtreeHasher($normalizer);

        $ast1 = $this->parser->parse($code1);
        $ast2 = $this->parser->parse($code2);
        self::assertArrayHasKey(0, $ast1);
        self::assertArrayHasKey(0, $ast2);

        $hash1 = $hasher->hashNode($ast1[0]);
        $hash2 = $hasher->hashNode($ast2[0]);

        self::assertNotSame($hash1, $hash2);
    }

    public function testAnonymizeNamesProducesSameHashForDifferentFunctionCalls(): void
    {
        $code1 = '<?php strlen($x);';
        $code2 = '<?php count($x);';

        $normalizer = new AstNormalizer(
            anonymizeVariables: true,
            anonymizeLiterals: false,
            anonymizeNames: true,
            anonymizeIdentifiers: false,
        );
        $hasher = new SubtreeHasher($normalizer);

        $ast1 = $this->parser->parse($code1);
        $ast2 = $this->parser->parse($code2);
        self::assertArrayHasKey(0, $ast1);
        self::assertArrayHasKey(0, $ast2);

        $hash1 = $hasher->hashNode($ast1[0]);
        $hash2 = $hasher->hashNode($ast2[0]);

        self::assertSame($hash1, $hash2);
    }

    public function testNoAnonymizeNamesProducesDifferentHashForDifferentFunctionCalls(): void
    {
        $code1 = '<?php strlen($x);';
        $code2 = '<?php count($x);';

        $normalizer = new AstNormalizer(
            anonymizeVariables: true,
            anonymizeLiterals: false,
            anonymizeNames: false,
            anonymizeIdentifiers: false,
        );
        $hasher = new SubtreeHasher($normalizer);

        $ast1 = $this->parser->parse($code1);
        $ast2 = $this->parser->parse($code2);
        self::assertArrayHasKey(0, $ast1);
        self::assertArrayHasKey(0, $ast2);

        $hash1 = $hasher->hashNode($ast1[0]);
        $hash2 = $hasher->hashNode($ast2[0]);

        self::assertNotSame($hash1, $hash2);
    }

    public function testAnonymizeIdentifiersProducesSameHashForDifferentMethodNames(): void
    {
        $code1 = '<?php $obj->methodA();';
        $code2 = '<?php $obj->methodB();';

        $normalizer = new AstNormalizer(
            anonymizeVariables: true,
            anonymizeLiterals: false,
            anonymizeNames: false,
            anonymizeIdentifiers: true,
        );
        $hasher = new SubtreeHasher($normalizer);

        $ast1 = $this->parser->parse($code1);
        $ast2 = $this->parser->parse($code2);
        self::assertArrayHasKey(0, $ast1);
        self::assertArrayHasKey(0, $ast2);

        $hash1 = $hasher->hashNode($ast1[0]);
        $hash2 = $hasher->hashNode($ast2[0]);

        self::assertSame($hash1, $hash2);
    }

    public function testNoAnonymizeIdentifiersProducesDifferentHashForDifferentMethodNames(): void
    {
        $code1 = '<?php $obj->methodA();';
        $code2 = '<?php $obj->methodB();';

        $normalizer = new AstNormalizer(
            anonymizeVariables: true,
            anonymizeLiterals: false,
            anonymizeNames: false,
            anonymizeIdentifiers: false,
        );
        $hasher = new SubtreeHasher($normalizer);

        $ast1 = $this->parser->parse($code1);
        $ast2 = $this->parser->parse($code2);
        self::assertArrayHasKey(0, $ast1);
        self::assertArrayHasKey(0, $ast2);

        $hash1 = $hasher->hashNode($ast1[0]);
        $hash2 = $hasher->hashNode($ast2[0]);

        self::assertNotSame($hash1, $hash2);
    }

    public function testNormalizationDoesNotModifyOriginalAst(): void
    {
        $code = '<?php $originalVar = "originalString";';

        $ast = $this->parser->parse($code);
        self::assertArrayHasKey(0, $ast);
        $originalNode = $ast[0];

        $normalizer = new AstNormalizer(
            anonymizeVariables: true,
            anonymizeLiterals: true,
            anonymizeNames: true,
            anonymizeIdentifiers: true,
        );

        // Normalize
        $normalizer->normalize($originalNode);

        // Original should be unchanged - verify by reparsing and comparing structure
        $printer = new Standard();
        $reprinted = $printer->prettyPrint([$originalNode]);

        self::assertStringContainsString('originalVar', $reprinted);
        self::assertStringContainsString('originalString', $reprinted);
    }

    public function testComplexCodeWithAllAnonymizationFlags(): void
    {
        $code1 = '<?php
            class UserService {
                public function createUser($name, $email) {
                    $user = new User();
                    $user->setName($name);
                    $user->setEmail($email);
                    return $user;
                }
            }
        ';

        $code2 = '<?php
            class ProductService {
                public function createProduct($title, $price) {
                    $product = new Product();
                    $product->setTitle($title);
                    $product->setPrice($price);
                    return $product;
                }
            }
        ';

        // With all anonymization, these should be identical
        $normalizer = new AstNormalizer(
            anonymizeVariables: true,
            anonymizeLiterals: true,
            anonymizeNames: true,
            anonymizeIdentifiers: true,
        );
        $hasher = new SubtreeHasher($normalizer);

        $ast1 = $this->parser->parse($code1);
        $ast2 = $this->parser->parse($code2);
        self::assertArrayHasKey(0, $ast1);
        self::assertArrayHasKey(0, $ast2);

        $hash1 = $hasher->hashNode($ast1[0]);
        $hash2 = $hasher->hashNode($ast2[0]);

        self::assertSame($hash1, $hash2);
    }

}
