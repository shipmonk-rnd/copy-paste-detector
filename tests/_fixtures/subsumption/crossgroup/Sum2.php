<?php
function sum($items) {
    $total = 0;
    foreach ($items as $item) {
        if ($item > 10) {
            $total += $item * 2;
        } else {
            $total += $item;
        }
    }
    return $total;
}
