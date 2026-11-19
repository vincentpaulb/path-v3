@extends('layouts.app')
@section('title', 'Public directory review')
@section('content')
<h1>Public directory review</h1>
<p>Review visitor reports and publish approved highlights. <a href="{{ route('public.proposals') }}">Open public directory</a></p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert">{{ $errors->first() }}</div>@endif
<section class="card" style="padding:20px;margin-bottom:20px">
    <h2>Project highlights and location verification</h2>
    <form method="GET"><label>Funded project <select name="proposal" required>
        <option value="">Select a proponent</option>
        @foreach($proposals as $item)<option value="{{ $item->id }}" @selected($selected?->id === $item->id)>{{ $item->proponent }}</option>@endforeach
    </select></label><button class="btn primary">Load project</button></form>
    @if($selected)
    <form method="POST" enctype="multipart/form-data" action="{{ route('public-directory.save', $selected) }}" style="display:grid;gap:12px;margin-top:18px">
        @csrf @method('PUT')
        <h3>{{ $selected->proponent }}</h3>
        <p>Saved coordinates: {{ $selected->latitude ?? 'None' }}, {{ $selected->longitude ?? 'None' }}. Verify these against a reliable source before marking them verified.</p>
        <label>Public outcome summary<textarea name="summary" maxlength="1500" rows="4">{{ old('summary', $selected->publicProfile?->summary) }}</textarea></label>
        <label>Project photo (JPEG, PNG or WebP; maximum 4 MB)<input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
        @if($selected->publicProfile?->photo_path)<img src="{{ route('public-directory.preview', $selected) }}" alt="Saved project photo for review" style="max-width:100%;max-height:250px;object-fit:contain"><label><input type="checkbox" name="remove_photo" value="1"> Remove saved photo</label>@endif
        <label>Photo description<input name="photo_alt" maxlength="200" value="{{ old('photo_alt', $selected->publicProfile?->photo_alt) }}"></label>
        <label><input type="checkbox" name="approved" value="1" @checked(old('approved', $selected->publicProfile?->approved))> I approve this summary and photo for public display, with no confidential information.</label>
        <label><input type="checkbox" name="verified" value="1" @checked(old('verified', $selected->publicProfile?->verified_coordinates === \App\Models\PublicProjectProfile::coordinates($selected)))> I have verified the saved coordinates.</label>
        <button class="btn primary">Save public content</button>
    </form>
    @endif
</section>
<section class="card" style="padding:20px"><h2>Visitor reports</h2>
    <p>Resolve or dismiss reports after review. Make any confirmed corrections through the proposal editor.</p>
    @forelse($reports as $report)
    <article style="border-top:1px solid #ddd;padding:12px 0">
        <h3>{{ $report->proposal?->proponent ?? 'Archived proposal' }}</h3>
        <p>{{ ucfirst($report->category) }} · {{ $report->created_at->format('M j, Y H:i') }}</p>
        <p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $report->message }}</p>
        <form method="POST" action="{{ route('public-directory.report-review', $report) }}">@csrf @method('PATCH')
            <label>Review status<select name="status">@foreach(['pending','resolved','dismissed'] as $status)<option @selected($report->status === $status) value="{{ $status }}">{{ ucfirst($status) }}</option>@endforeach</select></label>
            <button class="btn ghost">Save review</button>
        </form>
    </article>
    @empty<p>No visitor reports.</p>@endforelse
    {{ $reports->links() }}
</section>
@endsection
