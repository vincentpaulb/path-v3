<?php

namespace App\Http\Controllers;

use App\Models\Province;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProvinceController extends Controller
{
    public function index(): View
    {
        $provinces = Province::query()->ordered()->get();

        $proposalCounts = DB::table('proposals')
            ->selectRaw('province, COUNT(*) as aggregate')
            ->whereNotNull('province')
            ->groupBy('province')
            ->pluck('aggregate', 'province');

        $userCounts = DB::table('users')
            ->selectRaw('province, COUNT(*) as aggregate')
            ->whereNotNull('province')
            ->groupBy('province')
            ->pluck('aggregate', 'province');

        $generatedCodeCounts = DB::table('proposals')
            ->selectRaw('province, COUNT(*) as aggregate')
            ->whereNotNull('proposal_code')
            ->groupBy('province')
            ->pluck('aggregate', 'province');

        $provinces->each(function (Province $province) use ($proposalCounts, $userCounts, $generatedCodeCounts) {
            $province->proposal_count = (int) ($proposalCounts[$province->name] ?? 0);
            $province->user_count = (int) ($userCounts[$province->name] ?? 0);
            $province->generated_code_count = (int) ($generatedCodeCounts[$province->name] ?? 0);
        });

        return view('provinces.index', [
            'provinces' => $provinces,
            'activeCount' => $provinces->where('is_active', true)->count(),
            'inactiveCount' => $provinces->where('is_active', false)->count(),
            'historicalCount' => $provinces->filter(fn (Province $province) => ! $province->is_active && $province->proposal_count > 0)->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'region' => trim((string) $request->input('region')),
            'proposal_code' => strtoupper(trim((string) $request->input('proposal_code'))),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('provinces', 'name')],
            'region' => ['nullable', 'string', 'max:120'],
            'proposal_code' => ['required', 'string', 'max:4', 'regex:/^[A-Z]+$/', Rule::unique('provinces', 'proposal_code')],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] = $data['sort_order'] ?? ((int) Province::max('sort_order') + 10);

        Province::create($data);

        return back()->with('success', "Province added: {$data['name']}.");
    }

    public function update(Request $request, Province $province): RedirectResponse
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'region' => trim((string) $request->input('region')),
            'proposal_code' => strtoupper(trim((string) $request->input('proposal_code'))),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('provinces', 'name')->ignore($province->id)],
            'region' => ['nullable', 'string', 'max:120'],
            'proposal_code' => ['required', 'string', 'max:4', 'regex:/^[A-Z]+$/', Rule::unique('provinces', 'proposal_code')->ignore($province->id)],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);

        $oldName = $province->name;
        $newName = $data['name'];

        if ($data['proposal_code'] !== $province->proposal_code
            && DB::table('proposals')->where('province', $oldName)->whereNotNull('proposal_code')->exists()) {
            return back()->with('error', 'The province Proposal Code is locked because generated proposal identifiers already use it.');
        }

        DB::transaction(function () use ($province, $data, $oldName, $newName) {
            if ($oldName !== $newName) {
                // Province references in PATH are intentionally stored as text for
                // backward compatibility. Keep all active and archived records in
                // sync when an administrator corrects/renames a master value.
                DB::table('proposals')->where('province', $oldName)->update(['province' => $newName]);
                DB::table('users')->where('province', $oldName)->update(['province' => $newName]);
            }

            $province->update($data);
        });

        return back()->with('success', $oldName === $newName
            ? "Province updated: {$newName}."
            : "Province renamed from {$oldName} to {$newName}. Existing proposal and user records were updated safely.");
    }

    public function toggle(Request $request, Province $province): RedirectResponse
    {
        $province->update(['is_active' => ! $province->is_active]);

        return back()->with('success', $province->is_active
            ? "{$province->name} is active and available for new provincial records."
            : "{$province->name} is now inactive. Historical records are preserved, but it will no longer be offered for new provincial records.");
    }

    public function destroy(Province $province): RedirectResponse
    {
        $proposalCount = DB::table('proposals')->where('province', $province->name)->count();
        $userCount = DB::table('users')->where('province', $province->name)->count();

        if ($proposalCount > 0 || $userCount > 0) {
            return back()->with('error', "{$province->name} cannot be permanently removed because it is still referenced by {$proposalCount} proposal(s) and {$userCount} user account(s). Deactivate it instead.");
        }

        $name = $province->name;
        $province->delete();

        return back()->with('success', "Unused province removed permanently: {$name}.");
    }
}
