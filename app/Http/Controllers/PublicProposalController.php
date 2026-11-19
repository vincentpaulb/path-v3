<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use Illuminate\Http\Request;

class PublicProposalController extends Controller
{
    public function __invoke(Request $request)
    {
        $range = new \App\Support\DateRangeFilter($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'province' => ['nullable', 'string', 'max:100'],
            'sector' => ['nullable', 'string', 'max:100'],
            'industry' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $filters += ['date_from' => $range->from, 'date_to' => $range->to];

        // Keep this public projection separate from authenticated proposal details.
        // Soft-deleted proposals are excluded by the model's global scope.
        $base = Proposal::query()->funded();
        $provinces = (clone $base)->distinct()->orderBy('province')->pluck('province');
        $sectors = (clone $base)->distinct()->orderBy('sector')->pluck('sector');
        $query = clone $base;
        $range->apply($query, 'date_funded');
        $industryOptions = \App\Models\IndustrySector::query()
            ->whereHas('proposals', fn ($q) => $q->funded())
            ->orderBy('name')->pluck('name');
        if (($filters['industry'] ?? '') === '__unclassified__') {
            $query->whereDoesntHave('industrySector');
        } elseif (!empty($filters['industry'])) {
            $query->whereHas('industrySector', fn ($q) => $q->where('name', $filters['industry']));
        }
        foreach (['province', 'sector'] as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->where('proponent', 'like', '%'.$search.'%');
        }

        // Aggregate the entire filtered result, not just the current list page.
        // Only explicitly public fields and saved coordinates enter the map payload.
        $regions = (clone $query)->select('province')->selectRaw('COUNT(*) as total')
            ->groupBy('province')->orderBy('province')->get()->map(fn ($row) => [
                'province' => $row->province,
                'total' => (int) $row->total,
            ])->all();
        $industries = (clone $query)->with('industrySector:id,code,name')
            ->select('industry_sector_id')->selectRaw('COUNT(*) as total')
            ->groupBy('industry_sector_id')->get()->map(fn ($row) => [
                'name' => $row->industrySector?->name ?? 'Not classified',
                'total' => (int) $row->total,
            ])->sortBy('name')->values()->all();
        $locations = (clone $query)->whereBetween('latitude', [-90, 90])
            ->whereBetween('longitude', [-180, 180])
            ->where(fn ($q) => $q->where('latitude', '!=', 0)->orWhere('longitude', '!=', 0))
            ->orderBy('proponent')
            ->with(['industrySector:id,code,name', 'publicProfile'])
            ->get(['id', 'proponent', 'address', 'province', 'sector', 'industry_sector_id', 'latitude', 'longitude', 'location_source'])
            ->map(fn ($row) => [
                'map_key' => hash_hmac('sha256', (string) $row->id, config('app.key')),
                'proponent' => $row->proponent,
                'address' => $row->address,
                'province' => $row->province, 'sector' => $row->sector,
                'industry_sector' => $row->industrySector?->name ?? 'Not classified',
                'industry_icon' => \App\Support\IndustrySectorIcon::forName($row->industrySector?->name),
                'location_accuracy' => $this->locationLabel($row),
                'highlight' => $row->publicProfile?->approved ? $row->publicProfile->summary : null,
                'photo' => $row->publicProfile?->approved && $row->publicProfile->photo_path
                    ? route('public.projects.photo', ['key' => hash_hmac('sha256', (string) $row->id, config('app.key'))]) : null,
                'photo_alt' => $row->publicProfile?->approved ? $row->publicProfile->photo_alt : null,
                'coordinates' => [(float) $row->latitude, (float) $row->longitude],
            ])->all();
        $proposals = $query->with(['industrySector:id,code,name', 'publicProfile' => fn ($q) => $q->where('approved', true)])->orderBy('proponent')->orderBy('id')
            ->paginate(12, ['id', 'proponent', 'address', 'sector', 'industry_sector_id', 'province'])
            ->appends(array_intersect_key($filters, array_flip(['search', 'province', 'sector', 'industry', 'date_from', 'date_to'])));
        $mapKeys = $proposals->getCollection()->map(function ($row) {
            $key = hash_hmac('sha256', (string) $row->id, config('app.key'));
            unset($row->id, $row->industry_sector_id);
            return $key;
        })->all();
        $mappedKeys = array_column($locations, 'map_key');
        $frameProvinces = !empty($filters['province']) ? [$filters['province']] : $provinces->all();
        $provinceBounds = config('public-directory.province_bounds', []);
        $initialBounds = [];
        foreach ($frameProvinces as $province) {
            if (isset($provinceBounds[$province])) {
                array_push($initialBounds, ...$provinceBounds[$province]);
            } else {
                foreach ($locations as $location) {
                    if ($location['province'] === $province) $initialBounds[] = $location['coordinates'];
                }
            }
        }
        if (!$initialBounds) {
            foreach ($provinceBounds as $extent) array_push($initialBounds, ...$extent);
        }

        return response()->view('public.proposals', compact('proposals', 'provinces', 'sectors', 'regions', 'industries', 'industryOptions', 'filters', 'locations', 'mapKeys', 'mappedKeys', 'initialBounds'))
            ->header('Cache-Control', 'private, no-store');
    }

    private function locationLabel(Proposal $proposal): string
    {
        if ($proposal->publicProfile?->verified_coordinates === \App\Models\PublicProjectProfile::coordinates($proposal)) {
            return 'Verified by staff';
        }
        return match ($proposal->location_source) {
            'geocoded' => 'Approximate — address search',
            'manual' => 'Manually placed — not verified',
            default => 'Saved location — not verified',
        };
    }
}
