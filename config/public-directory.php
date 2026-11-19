<?php

return [
    // Generous map framing extents (southwest/northeast), not boundary polygons.
    // Include surrounding islands and water so initial views do not crop provinces.
    'province_bounds' => [
        'Cebu' => [[9.3, 123.2], [11.65, 124.65]],
        'Bohol' => [[9.45, 123.65], [10.35, 124.7]],
        'Siquijor' => [[9.05, 123.4], [9.35, 123.8]],
        'Negros' => [[9.0, 122.25], [11.05, 123.65]],
        'Negros Oriental' => [[9.0, 122.55], [10.55, 123.65]],
        'Negros Occidental' => [[9.3, 122.25], [11.05, 123.6]],
    ],
    // Approximate province reference points, never individual project locations.
    // Unlisted provinces remain visible in the directory without a map marker.
    'province_centers' => [
        'Cebu' => [10.59, 123.89],
        'Bohol' => [9.85, 124.14],
        'Siquijor' => [9.20, 123.59],
        'Negros' => [10.00, 123.00],
        'Negros Oriental' => [9.75, 123.00],
        'Negros Occidental' => [10.42, 122.95],
    ],
];
