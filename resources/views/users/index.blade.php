@extends('layouts.app')
@section('title','Users')
@section('page-title','User Management')

@section('content')
<div class="page-intro">
    <div>
        <h1>Access & accounts</h1>
    </div>
    @if(auth()->user()->hasPermission('users.create'))
        <button class="btn primary" type="button" id="openNewUserButton">
            <i class="fa-solid fa-user-plus" aria-hidden="true"></i> Add user
        </button>
    @endif
</div>

<section class="panel table-panel user-directory-panel">
    <form method="GET" action="{{ route('users.index') }}" class="user-directory-toolbar">
        <div class="user-directory-filter-row">
            <label class="user-directory-control user-directory-search-control">
                <span>Search</span>
                <div class="user-directory-search">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, username, email, organization…" aria-label="Search users">
                </div>
            </label>

            <label class="user-directory-control">
                <span>Role</span>
                <select name="role_id">
                    <option value="">All roles</option>
                    @foreach($filterRoles as $filterRole)
                        <option value="{{ $filterRole->id }}" @selected((string) request('role_id') === (string) $filterRole->id)>{{ $filterRole->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="user-directory-control">
                <span>Province</span>
                <select name="province">
                    <option value="">All provinces</option>
                    @foreach($filterProvinces as $province)
                        <option value="{{ $province }}" @selected(request('province') === $province)>{{ $province }}</option>
                    @endforeach
                </select>
            </label>

            <label class="user-directory-control">
                <span>Organization</span>
                <select name="organization">
                    <option value="">All organizations</option>
                    @foreach($organizations as $organization)
                        <option value="{{ $organization }}" @selected(request('organization') === $organization)>{{ $organization }}</option>
                    @endforeach
                </select>
            </label>

            <label class="user-directory-control">
                <span>Sort by</span>
                <select name="sort">
                    <option value="name_asc" @selected(request('sort', 'name_asc') === 'name_asc')>Name A–Z</option>
                    <option value="name_desc" @selected(request('sort') === 'name_desc')>Name Z–A</option>
                    <option value="username_asc" @selected(request('sort') === 'username_asc')>Username A–Z</option>
                    <option value="username_desc" @selected(request('sort') === 'username_desc')>Username Z–A</option>
                    <option value="role_asc" @selected(request('sort') === 'role_asc')>Role A–Z</option>
                    <option value="newest" @selected(request('sort') === 'newest')>Newest accounts</option>
                    <option value="oldest" @selected(request('sort') === 'oldest')>Oldest accounts</option>
                </select>
            </label>

            @include('partials.date-range-filter', ['dateBasis' => 'Account created date', 'overridesYear' => false])
            <div class="user-directory-filter-actions">
                
        
        <button class="btn primary small" type="submit"><i class="fa-solid fa-sliders" aria-hidden="true"></i> Apply</button>
                @if(request()->hasAny(['q', 'role_id', 'province', 'organization', 'sort']))
                    <a class="btn ghost small" href="{{ route('users.index') }}" title="Reset filters" aria-label="Reset filters"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i></a>
                @endif
            </div>
        </div>

        <div class="user-directory-toolbar-meta">
            <div class="user-directory-result-count"><b>{{ number_format($users->total()) }}</b> {{ $users->total() === 1 ? 'user' : 'users' }} found</div>
        </div>
    </form>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Province</th>
                    <th>Organization</th>
                    <th>Contact</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($users as $u)
                <tr>
                    <td><b>{{ $u->full_name }}</b><small>{{ $u->job_desc ?: '—' }}</small></td>
                    <td>{{ $u->username }}</td>
                    <td><span class="role-chip">{{ $u->accessRole?->name ?? $u->role }}</span></td>
                    <td>{{ $u->province ?: 'All' }}</td>
                    <td>{{ $u->company ?: '—' }}</td>
                    <td>{{ $u->email }}<small>{{ $u->mobile_number }}</small></td>
                    <td><a class="row-action" href="{{ route('users.show',$u) }}">Open →</a></td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="user-directory-empty">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <b>No users matched your filters</b>
                        <span>Try a different search term or reset the filters.</span>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $users->links() }}
</section>

@if(auth()->user()->hasPermission('users.create'))
<dialog id="newUser" class="modal user-create-modal" aria-labelledby="newUserTitle">
    <form method="POST" action="{{ route('users.store') }}" class="modal-card user-create-card" id="newUserForm">
        @csrf

        <div class="panel-head user-create-head">
            <div>
                <span class="eyebrow">ACCOUNT ACCESS</span>
                <h2 id="newUserTitle">Add PATH user</h2>
                <p>Create a login account and assign a role configured under Roles & Permissions.</p>
            </div>
            <button class="modal-close-button" type="button" id="closeNewUserButton" aria-label="Close Add User form">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </div>

        @if($errors->any())
            <div class="form-error" role="alert">
                <b>Please correct the following:</b>
                <ul>
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <div class="user-create-section">
            <div class="user-create-section-head">
                <span class="step">01</span>
                <div><b>Personal information</b><small>Basic identity and contact details.</small></div>
            </div>
            <div class="form-grid">
                <label>First name
                    <input name="first_name" value="{{ old('first_name') }}" maxlength="50" required autocomplete="given-name">
                    @error('first_name')<span class="field-error">{{ $message }}</span>@enderror
                </label>
                <label>Middle initial
                    <input name="middle_initial" value="{{ old('middle_initial') }}" maxlength="2">
                    @error('middle_initial')<span class="field-error">{{ $message }}</span>@enderror
                </label>
                <label>Last name
                    <input name="last_name" value="{{ old('last_name') }}" maxlength="50" required autocomplete="family-name">
                    @error('last_name')<span class="field-error">{{ $message }}</span>@enderror
                </label>
                <label>Email
                    <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
                    @error('email')<span class="field-error">{{ $message }}</span>@enderror
                </label>
                <label>Contact number
                    <input name="mobile_number" value="{{ old('mobile_number') }}" maxlength="20" autocomplete="tel">
                    @error('mobile_number')<span class="field-error">{{ $message }}</span>@enderror
                </label>
                <label>Organization
                    <input name="company" value="{{ old('company') }}" maxlength="100" autocomplete="organization">
                    @error('company')<span class="field-error">{{ $message }}</span>@enderror
                </label>
                <label class="span-2">Designation
                    <input name="job_desc" value="{{ old('job_desc') }}" maxlength="100" autocomplete="organization-title">
                    @error('job_desc')<span class="field-error">{{ $message }}</span>@enderror
                </label>
            </div>
        </div>

        <div class="user-create-section">
            <div class="user-create-section-head">
                <span class="step">02</span>
                <div><b>Access assignment</b><small>Choose the user's PATH role and applicable data scope.</small></div>
            </div>
            <div class="form-grid">
                <label>Role
                    <select name="role_id" id="newUserRole" required>
                        <option value="">Select role</option>
                        @foreach($roles as $role)
                            <option value="{{ $role->id }}" data-scope="{{ $role->data_scope }}" @selected((int) old('role_id') === $role->id)>{{ $role->name }}</option>
                        @endforeach
                    </select>
                    @error('role_id')<span class="field-error">{{ $message }}</span>@enderror
                    <small class="field-help">Module permissions are managed centrally under Roles & Permissions. Evaluator accounts remain managed from Evaluator Management.</small>
                </label>
                <label>Province
                    <select name="province" id="newUserProvince">
                        <option value="">All / Not applicable</option>
                        @foreach($provinces as $province)
                            <option value="{{ $province }}" @selected(old('province') === $province)>{{ $province }}</option>
                        @endforeach
                    </select>
                    @error('province')<span class="field-error">{{ $message }}</span>@enderror
                    <small class="field-help" id="newUserProvinceHelp">Province is required only for roles with provincial data scope.</small>
                </label>
            </div>
        </div>

        <div class="user-create-section credential-section">
            <div class="user-create-section-head">
                <span class="step">03</span>
                <div><b>Login credentials</b><small>Create the username and initial password.</small></div>
            </div>
            <div class="form-grid">
                <label>Username
                    <input name="username" value="{{ old('username') }}" maxlength="100" required autocomplete="off">
                    @error('username')<span class="field-error">{{ $message }}</span>@enderror
                </label>
                <label>Initial password
                    <div class="password-generator">
                        <input id="newUserPassword" type="password" name="password" minlength="10" required autocomplete="new-password">
                        <button class="btn ghost small" type="button" id="generateNewUserPassword"><i class="fa-solid fa-rotate" aria-hidden="true"></i> Generate</button>
                    </div>
                    @error('password')<span class="field-error">{{ $message }}</span>@enderror
                    <small class="field-help">A secure password is generated automatically. Minimum 10 characters.</small>
                </label>
                <label class="span-2">Confirm password
                    <input id="newUserPasswordConfirmation" type="password" name="password_confirmation" minlength="10" required autocomplete="new-password">
                </label>
            </div>
        </div>

        <div class="form-actions user-create-actions">
            <button type="button" class="btn ghost" id="cancelNewUserButton">Cancel</button>
            <button type="submit" class="btn primary"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Create user account</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    const modal = document.getElementById('newUser');
    const openButton = document.getElementById('openNewUserButton');
    const closeButton = document.getElementById('closeNewUserButton');
    const cancelButton = document.getElementById('cancelNewUserButton');
    const role = document.getElementById('newUserRole');
    const province = document.getElementById('newUserProvince');
    const provinceHelp = document.getElementById('newUserProvinceHelp');
    const password = document.getElementById('newUserPassword');
    const passwordConfirmation = document.getElementById('newUserPasswordConfirmation');
    const generateButton = document.getElementById('generateNewUserPassword');

    function secureGeneratedPassword(length = 12) {
        const lower = 'abcdefghijkmnopqrstuvwxyz';
        const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        const numbers = '23456789';
        const special = '!@#$%^&*';
        const all = lower + upper + numbers + special;
        const pick = set => set[Math.floor(Math.random() * set.length)];
        const chars = [pick(lower), pick(upper), pick(numbers), pick(special)];

        while (chars.length < Math.max(10, length)) chars.push(pick(all));
        for (let i = chars.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [chars[i], chars[j]] = [chars[j], chars[i]];
        }
        return chars.join('');
    }

    function fillGeneratedPassword() {
        const generated = secureGeneratedPassword();
        password.value = generated;
        passwordConfirmation.value = generated;
    }

    function syncProvinceRequirement() {
        const option = role.options[role.selectedIndex];
        const isProvincial = option?.dataset.scope === 'province';
        province.required = isProvincial;
        province.disabled = !isProvincial;
        if (!isProvincial) province.value = '';
        provinceHelp.textContent = isProvincial
            ? 'Required: this role is restricted to proposals from the selected province.'
            : 'Not applicable: this role uses global module access rather than provincial scoping.';
    }

    function openModal() {
        if (!password.value) fillGeneratedPassword();
        syncProvinceRequirement();
        if (!modal.open) modal.showModal();
        window.setTimeout(() => modal.querySelector('input[name="first_name"]')?.focus(), 0);
    }

    function closeModal() {
        if (modal.open) modal.close();
        openButton?.focus();
    }

    openButton?.addEventListener('click', openModal);
    closeButton?.addEventListener('click', closeModal);
    cancelButton?.addEventListener('click', closeModal);
    generateButton?.addEventListener('click', fillGeneratedPassword);
    role?.addEventListener('change', syncProvinceRequirement);

    modal?.addEventListener('click', event => {
        if (event.target === modal) closeModal();
    });

    @if($errors->any())
        document.addEventListener('DOMContentLoaded', openModal);
    @endif
})();
</script>
@endif
@endsection
