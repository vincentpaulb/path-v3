@extends('layouts.app')
@section('title','Edit Evaluator')
@section('page-title','Edit Evaluator')
@section('top-back')
    <a class="back topbar-back" href="{{ route('evaluators.show',$evaluator) }}">← Back to evaluator</a>
@endsection
@section('content')
<form method="POST" action="{{ route('evaluators.update',$evaluator) }}" class="form-layout">@csrf @method('PUT')
<section class="panel"><div class="panel-head"><div><span class="eyebrow">EVALUATOR DIRECTORY</span><h2>Evaluator information</h2></div></div>
@if($errors->any())<div class="form-error"><b>Please correct the following:</b><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="form-grid">
<label>First name<input name="first_name" value="{{ old('first_name',$evaluator->first_name) }}" required></label>
<label>Middle initial<input name="middle_initial" value="{{ old('middle_initial',$evaluator->middle_initial) }}" maxlength="2"></label>
<label>Last name<input name="last_name" value="{{ old('last_name',$evaluator->last_name) }}" required></label>
<label>Sex<select name="sex" required>@foreach(['Female','Male','Prefer not to say'] as $sex)<option @selected(old('sex',$evaluator->sex)===$sex)>{{ $sex }}</option>@endforeach</select></label>
<label>Classification<select name="classification" required>@foreach(['Internal','External'] as $classification)<option @selected(old('classification',$evaluator->classification)===$classification)>{{ $classification }}</option>@endforeach</select></label>
<label>Email<input type="email" name="email" value="{{ old('email',$evaluator->email) }}" required></label>
<label>Contact number<input name="contact_number" value="{{ old('contact_number',$evaluator->contact_number) }}"></label>
<label>Designation<input name="designation" value="{{ old('designation',$evaluator->designation) }}" required></label>
<label>Organization<input name="organization" value="{{ old('organization',$evaluator->organization) }}" required></label>
</div></section>
<section class="panel credential-panel"><div class="panel-head"><div><span class="eyebrow">PORTAL CREDENTIALS</span><h2>Evaluator login account</h2></div><span class="role-chip">Evaluator</span></div>
<p class="directory-help">Username changes take effect immediately. A new password is optional; use Generate password when you want to reset the evaluator's login password.</p>
<div class="form-grid">
<label>Username<input name="username" value="{{ old('username',$evaluator->account?->username) }}" autocomplete="off" required></label>
<label>New password
<div class="password-generator"><input id="editEvaluatorPassword" type="password" name="password" value="{{ old('password') }}" minlength="8" autocomplete="new-password" placeholder="Leave blank to keep current password"><button class="btn ghost small" type="button" onclick="fillGeneratedPassword('editEvaluatorPassword')"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate</button></div>
<small>Minimum 8 characters with uppercase, lowercase, and special character. Leave blank unless resetting.</small>
</label>
</div></section>
<div class="form-actions"><a class="btn ghost" href="{{ route('evaluators.show',$evaluator) }}">Cancel</a><button class="btn primary">Save changes</button></div></form>
<script>
function secureGeneratedPassword(length = 10) {
    const lower = 'abcdefghijkmnopqrstuvwxyz';
    const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const numbers = '23456789';
    const special = '!@#$%^&*';
    const all = lower + upper + numbers + special;
    const pick = set => set[Math.floor(Math.random() * set.length)];
    const chars = [pick(lower), pick(upper), pick(special), pick(numbers)];
    while (chars.length < Math.max(8, length)) chars.push(pick(all));
    for (let i = chars.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [chars[i], chars[j]] = [chars[j], chars[i]]; }
    return chars.join('');
}
function fillGeneratedPassword(id) { document.getElementById(id).value = secureGeneratedPassword(); }
</script>
@endsection
