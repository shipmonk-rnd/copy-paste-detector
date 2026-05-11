<?php
function outerOne($data) {
    $result = [];
    foreach ($data as $key => $value) {
        if ($value > 0) {
            $result[$key] = $value * 2 + 1;
        } else {
            $result[$key] = $value * 3 - 1;
        }
    }
    return $result;
}
