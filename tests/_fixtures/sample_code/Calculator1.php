<?php declare(strict_types = 1);

namespace TestFixtures;

use function is_numeric;
use function strtoupper;

final class Calculator1
{

    public function calculateSum(array $numbers): int
    {
        $total = 0;
        foreach ($numbers as $number) {
            if ($number > 0) {
                $total += $number;
            }
        }
        return $total;
    }

    public function calculateProduct(array $numbers): int
    {
        $result = 1;
        foreach ($numbers as $number) {
            if ($number > 0) {
                $result *= $number;
            }
        }
        return $result;
    }

    public function processData(array $data): array
    {
        $processed = [];
        foreach ($data as $key => $value) {
            if (is_numeric($value)) {
                $processed[$key] = $value * 2;
            } else {
                $processed[$key] = strtoupper($value);
            }
        }
        return $processed;
    }

}
