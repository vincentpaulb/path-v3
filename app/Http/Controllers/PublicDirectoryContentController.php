<?php

namespace App\Http\Controllers;

use App\Models\{Proposal, PublicProjectProfile, PublicProjectReport};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PublicDirectoryContentController extends Controller
{
    private function publicProposal(string $key): Proposal
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $key), 404);
        $id = Proposal::funded()->pluck('id')->first(
            fn ($id) => hash_equals(hash_hmac('sha256', (string) $id, config('app.key')), $key)
        );
        abort_unless($id, 404);
        return Proposal::funded()->with('publicProfile')->findOrFail($id);
    }

    public function report(Request $request, string $key)
    {
        $proposal = $this->publicProposal($key);
        $data = $request->validate([
            'category' => ['required', Rule::in(['address', 'location', 'industry', 'other'])],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        PublicProjectReport::create($data + ['proposal_id' => $proposal->id]);
        return response()->json(['message' => 'Thank you. Your report was sent for staff review.'], 201);
    }

    public function photo(string $key)
    {
        $profile = $this->publicProposal($key)->publicProfile;
        abort_unless($profile?->approved && $profile->photo_path && Storage::disk('local')->exists($profile->photo_path), 404);
        return response()->file(Storage::disk('local')->path($profile->photo_path), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function index(Request $request)
    {
        $proposals = Proposal::funded()->orderBy('proponent')->get(['id', 'proponent']);
        $selected = $request->filled('proposal')
            ? Proposal::with('publicProfile')->findOrFail($request->integer('proposal')) : null;
        $reports = PublicProjectReport::with('proposal:id,proponent')->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest()->paginate(15)->withQueryString();
        return view('public.directory-review', compact('proposals', 'selected', 'reports'));
    }

    public function save(Request $request, Proposal $proposal)
    {
        $data = $request->validate([
            'summary' => ['nullable', 'string', 'max:1500'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=6000,max_height=6000'],
            'photo_alt' => ['nullable', 'string', 'max:200'],
            'approved' => ['nullable', 'boolean'], 'verified' => ['nullable', 'boolean'],
            'remove_photo' => ['nullable', 'boolean'],
        ]);
        $profile = $proposal->publicProfile ?? new PublicProjectProfile(['proposal_id' => $proposal->id]);
        $path = $profile->photo_path;
        if ($request->boolean('remove_photo')) $path = null;
        if ($request->hasFile('photo')) $path = $request->file('photo')->store('public-project-highlights', 'local');
        $profile->fill([
            'summary' => $data['summary'] ?? null, 'photo_path' => $path,
            'photo_alt' => $data['photo_alt'] ?? null,
            'approved' => $request->boolean('approved'),
            'verified_coordinates' => $request->boolean('verified') && $proposal->latitude !== null && $proposal->longitude !== null
                ? PublicProjectProfile::coordinates($proposal) : null,
        ])->save();
        return redirect()->route('public-directory.review', ['proposal' => $proposal->id])->with('status', 'Public directory content saved.');
    }

    public function preview(Proposal $proposal)
    {
        $path = $proposal->publicProfile?->photo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function review(Request $request, PublicProjectReport $report)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['pending', 'resolved', 'dismissed'])]]);
        $report->update($data);
        return back()->with('status', 'Report review status updated. Proposal details were not changed.');
    }
}
