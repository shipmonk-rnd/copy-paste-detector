<?php
function process($values) {
    $total = 0;
    foreach ($values as $val) {
        if ($val > 0) {
            $total += $val * 2 + 1;
        }
    }
    return $total;
}
