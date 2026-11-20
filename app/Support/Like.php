<?php

namespace App\Support;

/** A LIKE pattern that matches the user's text literally: % and _ typed in a search box are not wildcards. */
final class Like
{
    public static function contains(string $term): string
    {
        return '%'.addcslashes($term, '\\%_').'%';
    }
}
