@extends('layouts.app')
@section('title','Edit User')
@section('page-title','Edit User')
@section('top-back')
    <a class="back topbar-back" href="{{ route('users.show',$user) }}">← Back to user</a>
@endsection
@section('content')
<form method="POST" action="{{ route('users.update',$user) }}" class="form-layout">
    @csrf @method('PUT')
    <section class="panel">
        <div class="panel-head"><div><span class="eyebrow">ACCOUNT DETAILS</span><h2>Update user</h2></div></div>
        <div class="form-grid">
            <label>First name<input name="first_name" value="{{ old('first_name',$user->first_name) }}" required></label>
            <label>Middle initial<input name="middle_initial" value="{{ old('middle_initial',$user->middle_initial) }}"></label>
            <label>Last name<input name="last_name" value="{{ old('last_name',$user->last_name) }}" required></label>
            <label>Email<input type="email" name="email" value="{{ old('email',$user->email) }}" required></label>
            <label>Contact<input name="mobile_number" value="{{ old('mobile_number',$user->mobile_number) }}"></label>
            <label>Organization<input name="company" value="{{ old('company',$user->company) }}"></label>
            <label>Designation<input name="job_desc" value="{{ old('job_desc',$user->job_desc) }}"></label>
            <label>Role
                <select name="role_id" id="editUserRole" required>
                    @foreach($roles as $role)
                        <option value="{{ $role->id }}" data-scope="{{ $role->data_scope }}" @selected((int)old('role_id',$user->role_id)===$role->id)>{{ $role->name }}</option>
                    @endforeach
                </select>
                @error('role_id')<span class="field-error">{{ $message }}</span>@enderror
                <small>Permissions for this role are controlled under Roles & Permissions.</small>
            </label>
            <label>Province
                <select name="province" id="editUserProvince">
                    <option value="">All / Not applicable</option>
                    @foreach($provinces as $province)<option @selected(old('province',$user->province)===$province)>{{ $province }}</option>@endforeach
                </select>
                @error('province')<span class="field-error">{{ $message }}</span>@enderror
            </label>
        </div>
    </section>

    <section class="panel credential-panel">
        <div class="panel-head">
            <div><span class="eyebrow">PORTAL CREDENTIALS</span><h2>User login account</h2></div>
            <span class="role-chip">{{ $user->accessRole?->name ?? $user->role }}</span>
        </div>
        <p class="directory-help">Username changes take effect immediately. A new password is optional; use Generate when you want to reset this user's login password.</p>
        <div class="form-grid">
            <label>Username
                <input name="username" value="{{ old('username',$user->username) }}" autocomplete="off" required>
                @error('username')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <label>New password
                <div class="password-generator">
                    <input id="editUserPassword" type="password" name="password" value="{{ old('password') }}" minlength="8" autocomplete="new-password" placeholder="Leave blank to keep current password">
                    <button class="btn ghost small" type="button" onclick="fillGeneratedUserPassword('editUserPassword')"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate</button>
                </div>
                @error('password')<span class="field-error">{{ $message }}</span>@enderror
                <small>Minimum 8 characters with uppercase, lowercase, and special character. Leave blank unless resetting.</small>
            </label>
        </div>
    </section>

    <div class="form-actions"><a class="btn ghost" href="{{ route('users.show',$user) }}">Cancel</a><button class="btn primary">Save changes</button></div>
</form>
<script>
function secureGeneratedUserPassword(length = 10) {
    const lower = 'abcdefghijkmnopqrstuvwxyz';
    const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const numbers = '23456789';
    const special = '!@#$%^&*';
    const all = lower + upper + numbers + special;
    const pick = set => set[Math.floor(Math.random() * set.length)];
    const chars = [pick(lower), pick(upper), pick(special), pick(numbers)];
    while (chars.length < Math.max(8, length)) chars.push(pick(all));
    for (let i = chars.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }
    return chars.join('');
}
function fillGeneratedUserPassword(id) {
    document.getElementById(id).value = secureGeneratedUserPassword();
}

document.addEventListener('DOMContentLoaded', () => {
    const role = document.getElementById('editUserRole');
    const province = document.getElementById('editUserProvince');
    const sync = () => {
        const isProvincial = role.options[role.selectedIndex]?.dataset.scope === 'province';
        province.required = isProvincial;
        province.disabled = !isProvincial;
        if (!isProvincial) province.value = '';
    };
    role?.addEventListener('change', sync);
    sync();
});
</script>
@endsection
