<?php
function patternA($x) {
    $a = $x + 1;
    $b = $a * 2;
    $c = $b - 3;
    return $c + $a;
}

function patternB($y) {
    $arr = [];
    for ($i = 0; $i < 10; $i++) {
        $arr[] = $y * $i;
    }
    return $arr;
}
