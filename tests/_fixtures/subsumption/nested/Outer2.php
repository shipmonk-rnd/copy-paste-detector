<?php
function outerTwo($items) {
    $output = [];
    foreach ($items as $k => $v) {
        if ($v > 0) {
            $output[$k] = $v * 2 + 1;
        } else {
            $output[$k] = $v * 3 - 1;
        }
    }
    return $output;
}
