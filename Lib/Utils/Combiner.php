<?php
namespace Lib\Utils;

use Math_Combinatorics;

class Combiner {
    const DEFAULT_TRENDS_ORDER = 'desc';

    public static function getCombinations($itemsToCombine, $numberOfItemsInEachCombination) {
        $mathCombinatorics =  new Math_Combinatorics();
        $combinations = $mathCombinatorics->combinations($itemsToCombine, $numberOfItemsInEachCombination);
        $order = getenv('TRENDS_ORDER') ?: self::DEFAULT_TRENDS_ORDER;
        if ($order == 'asc') {
            $combinations = array_reverse($combinations);
        }

        if ($order == 'random') {
            shuffle($combinations);
        }
        return $combinations;
    }
}