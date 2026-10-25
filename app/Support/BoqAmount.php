<?php

namespace App\Support;

class BoqAmount
{
    public static function format(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole).','.str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
