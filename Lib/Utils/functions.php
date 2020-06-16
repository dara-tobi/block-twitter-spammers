<?php

// Laravel's data_get helper
function data_get($target, $key, $default = null)
{
    if (is_null($key)) {
        return $target;
    }

    $key = is_array($key) ? $key : explode('.', $key);

    foreach ($key as $segment) {
        if (is_array($target)) {
            if (! array_key_exists($segment, $target)) {
                return $default ?? null;
            }

            $target = $target[$segment];
        } elseif ($target instanceof ArrayAccess) {
            if (! isset($target[$segment])) {
                return $default ?? null;
            }

            $target = $target[$segment];
        } elseif (is_object($target)) {
            if (! isset($target->{$segment})) {
                return $default ?? null;
            }

            $target = $target->{$segment};
        } else {
            return $default ?? null;
        }
    }

    return $target;
}

function printWithLineBreaks($string) {
    echo "\n$string\n";
}