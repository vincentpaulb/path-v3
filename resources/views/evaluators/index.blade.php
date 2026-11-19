@extends('layouts.app')
@section('title','Evaluators')
@section('page-title','Evaluator Management')
@section('content')
<div class="page-intro">
    <div><h1>Evaluators</h1></div>
    @if(auth()->user()->hasPermission('evaluators.create'))<button class="btn primary" onclick="openNewEvaluator()">+ Add evaluator</button>@endif
</div>
<section class="panel table-panel">
    <div class="table-wrap"><table><thead><tr><th>Evaluator</th><th>Classification</th><th>Designation</th><th>Organization</th><th>Portal account</th><th class="num">Evaluated</th><th class="num">In progress</th><th></th></tr></thead><tbody>
    @forelse($evaluators as $e)
        <tr>
            <td><b>{{ $e->full_name }}</b><small>{{ $e->email ?: 'No email' }} · {{ $e->contact_number ?: 'No contact' }}</small></td>
            <td><span class="role-chip">{{ $e->classification }}</span></td>
            <td>{{ $e->designation }}</td>
            <td>{{ $e->organization }}</td>
            <td>
                @if($e->account)
                    <span class="account-match matched"><i class="fa-solid fa-circle-check"></i> {{ $e->account->username }}</span>
                @else
                    <span class="account-match pending"><i class="fa-solid fa-circle-exclamation"></i> Account missing</span>
                @endif
            </td>
            <td class="num">{{ $e->evaluated_count }}</td>
            <td class="num">{{ $e->currently_evaluating_count }}</td>
            <td><a class="row-action" href="{{ route('evaluators.show',$e) }}">Open →</a></td>
        </tr>
    @empty
        <tr><td colspan="8" class="empty">No evaluators have been added yet.</td></tr>
    @endforelse
    </tbody></table></div>
    {{ $evaluators->links() }}
</section>
@if(auth()->user()->hasPermission('evaluators.create'))
<dialog id="newEvaluator" class="modal">
    <form method="POST" action="{{ route('evaluators.store') }}" class="modal-card">@csrf
        <div class="panel-head"><div><span class="eyebrow">EVALUATOR DIRECTORY & ACCESS</span><h2>Add evaluator</h2></div></div>
        <p class="directory-help">A portal account will be created automatically with the <b>Evaluator</b> role. The generated password is shown so you can provide it securely to the evaluator.</p>
        @if($errors->any())<div class="form-error"><b>Please correct the following:</b><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="form-grid">
            <label>First name<input name="first_name" value="{{ old('first_name') }}" required></label><label>Middle initial<input name="middle_initial" value="{{ old('middle_initial') }}" maxlength="2"></label>
            <label>Last name<input name="last_name" value="{{ old('last_name') }}" required></label><label>Sex<select name="sex" required>@foreach(['Female','Male','Prefer not to say'] as $sex)<option @selected(old('sex')===$sex)>{{ $sex }}</option>@endforeach</select></label>
            <label>Classification<select name="classification" required>@foreach(['Internal','External'] as $classification)<option value="{{ $classification }}" @selected(old('classification','Internal')===$classification)>{{ $classification }}</option>@endforeach</select></label><label>Email<input type="email" name="email" value="{{ old('email') }}" required></label>
            <label>Contact number<input name="contact_number" value="{{ old('contact_number') }}"></label><label>Designation<input name="designation" value="{{ old('designation') }}" required></label><label>Organization<input name="organization" value="{{ old('organization') }}" required></label>
        </div>
        <div class="credential-section">
            <div class="credential-heading"><div><span class="eyebrow">PORTAL CREDENTIALS</span><h3>Evaluator login account</h3></div><span class="role-chip">Evaluator</span></div>
            <div class="form-grid">
                <label>Username<input name="username" value="{{ old('username') }}" autocomplete="off" required></label>
                <label>Password
                    <div class="password-generator"><input id="newEvaluatorPassword" type="text" name="password" value="{{ old('password') }}" minlength="8" autocomplete="new-password" required><button class="btn ghost small" type="button" onclick="fillGeneratedPassword('newEvaluatorPassword')"><i class="fa-solid fa-rotate"></i> Generate</button></div>
                    <small>Minimum 8 characters with uppercase, lowercase, and special character.</small>
                </label>
            </div>
        </div>
        <div class="form-actions"><button type="button" class="btn ghost" onclick="newEvaluator.close()">Cancel</button><button class="btn primary">Create evaluator & account</button></div>
    </form>
</dialog>
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
function openNewEvaluator() { if (!document.getElementById('newEvaluatorPassword').value) fillGeneratedPassword('newEvaluatorPassword'); newEvaluator.showModal(); }
@if($errors->any()) document.addEventListener('DOMContentLoaded', () => { if (!document.getElementById('newEvaluatorPassword').value) fillGeneratedPassword('newEvaluatorPassword'); newEvaluator.showModal(); }); @endif
</script>
@endif
@endsection
