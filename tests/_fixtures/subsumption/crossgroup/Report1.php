<?php
function report($items, $label) {
    $total = 100;
    echo $label;
    foreach ($items as $item) {
        if ($item > 10) {
            $total += $item * 2;
        } else {
            $total += $item;
        }
    }
    echo $label . $total;
}
