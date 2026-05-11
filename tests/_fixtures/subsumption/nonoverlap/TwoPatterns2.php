<?php
function patternA2($z) {
    $d = $z + 1;
    $e = $d * 2;
    $f = $e - 3;
    return $f + $d;
}

function patternB2($w) {
    $list = [];
    for ($j = 0; $j < 10; $j++) {
        $list[] = $w * $j;
    }
    return $list;
}
