<?php
namespace Lib\Utils;

use Math_Combinatorics;

class Combiner {

    public static function getCombinations($itemsToCombine, $numberOfItemsInEachCombination) {
        $mathCombinatorics =  new Math_Combinatorics();
        return $mathCombinatorics->combinations($itemsToCombine, $numberOfItemsInEachCombination);
    }
}