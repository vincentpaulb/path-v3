<?php

namespace App\Http\Controllers;

use App\Models\IndustrySector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class IndustrySectorController extends Controller
{
    public function index(): View
    {
        $sectors = IndustrySector::query()
            ->withCount(['proposals' => fn ($query) => $query->withTrashed()])
            ->ordered()
            ->get();

        return view('industry-sectors.index', [
            'sectors' => $sectors,
            'activeCount' => $sectors->where('is_active', true)->count(),
            'inactiveCount' => $sectors->where('is_active', false)->count(),
            'usedCount' => $sectors->where('proposals_count', '>', 0)->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] ??= ((int) IndustrySector::max('sort_order') + 10);
        IndustrySector::create($data);

        return back()->with('success', "Industry sector added: {$data['code']}.");
    }

    public function update(Request $request, IndustrySector $industrySector): RedirectResponse
    {
        $data = $this->validated($request, $industrySector);
        if ($industrySector->proposals()->withTrashed()->exists() && $data['code'] !== $industrySector->code) {
            return back()->with('error', 'The sector code is locked because it is already referenced by a proposal. Its name and description may still be edited.');
        }

        $industrySector->update($data);

        return back()->with('success', "Industry sector updated: {$industrySector->code}.");
    }

    public function toggle(IndustrySector $industrySector): RedirectResponse
    {
        $industrySector->update(['is_active' => !$industrySector->is_active]);

        return back()->with('success', $industrySector->is_active
            ? "{$industrySector->code} is active and available on proposal forms."
            : "{$industrySector->code} is inactive. Existing proposal references and codes are preserved.");
    }

    public function destroy(IndustrySector $industrySector): RedirectResponse
    {
        if ($industrySector->proposals()->withTrashed()->exists()) {
            return back()->with('error', 'This industry sector is referenced by proposals and cannot be deleted. Deactivate it instead.');
        }

        $label = "{$industrySector->code} — {$industrySector->name}";
        $industrySector->delete();

        return back()->with('success', "Unused industry sector removed: {$label}.");
    }

    private function validated(Request $request, ?IndustrySector $industrySector = null): array
    {
        $request->merge([
            'code' => strtoupper(trim((string) $request->input('code'))),
            'name' => trim((string) $request->input('name')),
            'description' => trim((string) $request->input('description')),
        ]);

        return $request->validate([
            'code' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/', Rule::unique('industry_sectors', 'code')->ignore($industrySector?->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
    }
}
