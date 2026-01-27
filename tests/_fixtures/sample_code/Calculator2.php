<?php declare(strict_types = 1);

namespace TestFixtures;

use function is_numeric;
use function strtoupper;

final class Calculator2
{

    public function sumPositive(array $values): int
    {
        $sum = 0;
        foreach ($values as $val) {
            if ($val > 0) {
                $sum += $val;
            }
        }
        return $sum;
    }

    public function multiplyPositive(array $values): int
    {
        $product = 1;
        foreach ($values as $val) {
            if ($val > 0) {
                $product *= $val;
            }
        }
        return $product;
    }

    public function transformData(array $items): array
    {
        $transformed = [];
        foreach ($items as $index => $item) {
            if (is_numeric($item)) {
                $transformed[$index] = $item * 2;
            } else {
                $transformed[$index] = strtoupper($item);
            }
        }
        return $transformed;
    }

}
