<?php

return [
    'search_url' => env('GEOCODING_SEARCH_URL', 'https://nominatim.openstreetmap.org/search'),
    'user_agent' => env('GEOCODING_USER_AGENT', 'PATH-DOSTVII/1.0 (+'.env('APP_URL', 'http://localhost').')'),
    'cache_days' => (int) env('GEOCODING_CACHE_DAYS', 30),
    'country_code' => env('GEOCODING_COUNTRY_CODE', 'ph'),
    'tile_url' => env('MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
    'tile_attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>',
    'mapbox_public_token' => env('MAPBOX_PUBLIC_TOKEN'),
    'mapbox_style' => env('MAPBOX_STYLE', 'mapbox/outdoors-v12'),
    'default_latitude' => (float) env('MAP_DEFAULT_LATITUDE', 10.3157),
    'default_longitude' => (float) env('MAP_DEFAULT_LONGITUDE', 123.8854),
    'default_zoom' => (int) env('MAP_DEFAULT_ZOOM', 8),
];
