<?php
$sum = 0;
foreach ($data as $item) {
    if ($item > 0) {
        $sum += $item * 2 + 1;
    }
}
