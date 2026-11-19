<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class GeocodingController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyPermission(['proposals.create', 'proposals.edit']), 403);

        $data = $request->validate([
            'q' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $query = trim(preg_replace('/\s+/u', ' ', $data['q']));
        $cacheKey = 'geocoding:nominatim:v3:'.sha1(Str::lower($query));
        $parts = array_values(array_filter(array_map('trim', explode(',', $query))));
        $suggestions = [];
        if (count($parts) === 2) {
            $street = preg_replace('/^\d+[A-Za-z-]*\s+/u', '', $parts[0]);
            $street = preg_replace('/\bAve\b\.?/iu', 'Avenue', $street);
            $suggestions[] = $street.', '.$parts[1];
        }
        if (count($parts) >= 3) {
            // Preserve locality while dropping building/house/postcode detail that
            // may not exist in OpenStreetMap. Never treat these as exact matches.
            $locality = array_slice($parts, 2);
            $locality = array_map(fn ($part) => trim(preg_replace('/\b\d{4}\b/u', '', $part)), $locality);
            $suggestions[] = implode(', ', array_merge([$parts[0]], $locality));
            $street = preg_replace('/^\d+[A-Za-z-]*\s+/u', '', $parts[1]);
            $street = preg_replace('/\bAve\.?\b/iu', 'Avenue', $street);
            $suggestions[] = implode(', ', array_merge([$street], $locality));
        }
        $suggestions = array_values(array_unique(array_filter($suggestions, fn ($value) => $value !== $query)));

        if (Cache::has($cacheKey)) {
            return response()->json(['results' => Cache::get($cacheKey), 'cached' => true, 'suggestions' => $suggestions]);
        }

        $limiterKey = 'geocoding:nominatim:global';
        if (RateLimiter::tooManyAttempts($limiterKey, 1)) {
            return response()->json([
                'message' => 'The map search is briefly busy. Please wait a second and try again.',
                'retry_after' => max(1, RateLimiter::availableIn($limiterKey)),
            ], 429);
        }
        RateLimiter::hit($limiterKey, 2);

        try {
            $response = Http::acceptJson()
                ->withUserAgent(config('geocoding.user_agent'))
                ->timeout(10)
                ->get(config('geocoding.search_url'), [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => 5,
                    'countrycodes' => config('geocoding.country_code', 'ph'),
                ]);
        } catch (ConnectionException) {
            return response()->json(['message' => 'The server could not connect to the address-search provider. This is a connection problem, not an invalid address. Please try again later or select the location directly on the map.'], 503);
        }

        if (! $response->successful()) {
            return response()->json(['message' => 'The free geocoding service could not complete the search. Please try again later or select the location on the map.'], 503);
        }

        $results = collect($response->json())
            ->take(5)
            ->map(fn (array $result) => [
                'label' => (string) ($result['display_name'] ?? ''),
                'latitude' => is_numeric($result['lat'] ?? null) ? round((float) $result['lat'], 7) : null,
                'longitude' => is_numeric($result['lon'] ?? null) ? round((float) $result['lon'], 7) : null,
                'type' => (string) ($result['type'] ?? ''),
                'match_note' => match ($result['class'] ?? $result['category'] ?? '') {
                    'highway' => 'Street match — move the pin to the actual building.',
                    'boundary', 'place' => 'Area match — not an exact project site. Place the pin at the actual site.',
                    default => 'Mapped place — verify the building and pin before saving.',
                },
            ])
            ->filter(fn (array $result) => $result['label'] !== '' && $result['latitude'] !== null && $result['longitude'] !== null
                && abs($result['latitude']) <= 90 && abs($result['longitude']) <= 180)
            ->values()
            ->all();

        Cache::put($cacheKey, $results, $results
            ? now()->addDays(max(1, (int) config('geocoding.cache_days', 30)))
            : now()->addMinutes(5));

        return response()->json(['results' => $results, 'cached' => false, 'suggestions' => $suggestions]);
    }
}
