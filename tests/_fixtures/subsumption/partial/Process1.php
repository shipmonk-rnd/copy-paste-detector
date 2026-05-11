<?php
function process($data) {
    $sum = 0;
    foreach ($data as $item) {
        if ($item > 0) {
            $sum += $item * 2 + 1;
        }
    }
    return $sum;
}
