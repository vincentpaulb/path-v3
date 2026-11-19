<?php

namespace App\Support;

final class IndustrySectorIcon
{
    public static function forName(?string $name): string
    {
        $name = strtolower($name ?? '');

        return match (true) {
            $name === '', $name === 'not classified' => 'fa-location-dot',
            str_contains($name, 'food') => 'fa-utensils',
            str_contains($name, 'beverage') => 'fa-mug-hot',
            str_contains($name, 'furniture') => 'fa-couch',
            (bool) preg_match('/gift|decor|handicraft/', $name) => 'fa-gift',
            (bool) preg_match('/textile|apparel|leather/', $name) => 'fa-shirt',
            (bool) preg_match('/pharma|health|wellness/', $name) => 'fa-heart-pulse',
            (bool) preg_match('/fishing|fishery|marine|aquaculture/', $name) => 'fa-fish',
            (bool) preg_match('/crop|animal|agricultur|livestock/', $name) => 'fa-wheat-awn',
            (bool) preg_match('/forestry|logging|wood|cork/', $name) => 'fa-tree',
            str_contains($name, 'paper') => 'fa-file-lines',
            str_contains($name, 'chemical') => 'fa-flask',
            (bool) preg_match('/metal|machinery|engineering|transport equipment/', $name) => 'fa-gears',
            (bool) preg_match('/information|communication|\bict\b/', $name) => 'fa-laptop',
            str_contains($name, 'halal') => 'fa-certificate',
            (bool) preg_match('/rubber|plastic/', $name) => 'fa-industry',
            default => 'fa-tags',
        };
    }
}
