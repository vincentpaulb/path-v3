@extends('layouts.app')
@section('title','Roles & Permissions')
@section('page-title','Roles & Permissions')

@section('top-actions')
    <button class="btn primary" type="button" onclick="document.getElementById('newRoleModal').showModal()">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> New Role
    </button>
@endsection

@section('content')
@php
    $scopeLabels = [
        'global' => ['label' => 'Global data', 'icon' => 'fa-earth-asia'],
        'province' => ['label' => 'Province only', 'icon' => 'fa-location-dot'],
        'assigned' => ['label' => 'Assigned proposals', 'icon' => 'fa-user-check'],
    ];
    $selectedKeys = $selectedRole?->slug === 'admin'
        ? collect($permissions)->keys()->all()
        : ($selectedRole?->permissions?->pluck('key')->all() ?? []);
    $pstoRequiredKeys = \App\Support\PermissionCatalog::pstoRequiredPermissions();
    $pstoRestrictedKeys = \App\Support\PermissionCatalog::pstoRestrictedPermissions();
@endphp

<div class="roles-page">
    <div class="page-intro roles-intro">
        <div>
            <h1>Roles & Permissions</h1>
        </div>
        <div class="roles-security-note">
            <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
            <div><b>Admin protected</b><small>Full access and permanent deletion cannot be delegated.</small></div>
        </div>
    </div>

    @if($errors->any() && !$errors->createRole->any())
        <div class="path-flash danger"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span>{{ $errors->first() }}</span></div>
    @endif

    <div class="roles-layout">
        <aside class="role-directory panel">
            <div class="role-directory-head">
                <div>
                    <span class="eyebrow">USER ROLES</span>
                    <h2>Access profiles</h2>
                </div>
                <span class="summary-count">{{ $roles->count() }}</span>
            </div>

            <div class="role-list">
                @foreach($roles as $role)
                    @php $scope = $scopeLabels[$role->data_scope] ?? $scopeLabels['global']; @endphp
                    <a href="{{ route('roles.index',['role'=>$role->id]) }}" class="role-card {{ $selectedRole?->id === $role->id ? 'active' : '' }}">
                        <span class="role-card-icon"><i class="fa-solid {{ $role->is_protected ? 'fa-lock' : ($role->is_system ? 'fa-shield' : 'fa-user-tag') }}" aria-hidden="true"></i></span>
                        <span class="role-card-copy">
                            <span class="role-card-title">
                                <b>{{ $role->name }}</b>
                                @if($role->is_protected)<em>Protected</em>@elseif(!$role->is_system)<em>Custom</em>@endif
                            </span>
                            <small><i class="fa-solid {{ $scope['icon'] }}" aria-hidden="true"></i> {{ $scope['label'] }} · {{ $role->users_count }} user{{ $role->users_count === 1 ? '' : 's' }}</small>
                        </span>
                        <i class="fa-solid fa-chevron-right role-chevron" aria-hidden="true"></i>
                    </a>
                @endforeach
            </div>

            <button class="btn role-new-button" type="button" onclick="document.getElementById('newRoleModal').showModal()">
                <i class="fa-solid fa-plus" aria-hidden="true"></i> Create custom role
            </button>
        </aside>

        <section class="role-permissions panel">
            @if($selectedRole)
                @php $selectedScope = $scopeLabels[$selectedRole->data_scope] ?? $scopeLabels['global']; @endphp
                <div class="role-permissions-head">
                    <div>
                        <div class="role-title-line">
                            <span class="role-title-icon"><i class="fa-solid {{ $selectedRole->is_protected ? 'fa-lock' : 'fa-shield-halved' }}" aria-hidden="true"></i></span>
                            <div>
                                <span class="eyebrow">{{ $selectedRole->is_system ? 'SYSTEM ROLE' : 'CUSTOM ROLE' }}</span>
                                <h2>{{ $selectedRole->name }}</h2>
                            </div>
                        </div>
                        <p>{{ $selectedRole->description ?: 'No role description has been provided yet.' }}</p>
                    </div>
                    <div class="role-head-meta">
                        <span class="role-scope"><i class="fa-solid {{ $selectedScope['icon'] }}" aria-hidden="true"></i>{{ $selectedScope['label'] }}</span>
                        <span class="role-user-count"><b>{{ $selectedRole->users_count }}</b> assigned user{{ $selectedRole->users_count === 1 ? '' : 's' }}</span>
                    </div>
                </div>

                @if($selectedRole->is_protected)
                    <div class="protected-role-banner">
                        <i class="fa-solid fa-lock" aria-hidden="true"></i>
                        <div>
                            <b>Protected administrator role</b>
                            <p>Admin always has full PATH access. Its permissions, role name, and security safeguards cannot be reduced from this screen.</p>
                        </div>
                    </div>
                @else
                    <div class="scope-policy-banner {{ $selectedRole->data_scope }}">
                        <i class="fa-solid {{ $selectedScope['icon'] }}" aria-hidden="true"></i>
                        <div>
                            <b>Data scope: {{ $selectedScope['label'] }}</b>
                            @if($selectedRole->data_scope === 'province')
                                <p>PSTO users can view proposals and monitoring data for their assigned province and participate in discussions. Proposal and monitoring records remain read-only.</p>
                            @elseif($selectedRole->data_scope === 'assigned')
                                <p>Evaluator access remains limited to proposals explicitly assigned to that evaluator. Other users' audit activity remains hidden from the evaluator portal.</p>
                            @else
                                <p>This role is not restricted by province or evaluator assignment. Module permissions below still control every available action.</p>
                            @endif
                        </div>
                    </div>
                @endif

                <form method="POST" action="{{ route('roles.update',$selectedRole) }}" id="rolePermissionForm">
                    @csrf
                    @method('PUT')

                    @unless($selectedRole->is_protected)
                        <div class="role-settings-grid">
                            @if(!$selectedRole->is_system)
                                <label>
                                    Role name
                                    <input name="name" maxlength="30" value="{{ old('name',$selectedRole->name) }}" required>
                                    <small>Custom role names can be changed while no system role can be renamed.</small>
                                </label>
                            @else
                                <label>
                                    Role name
                                    <input value="{{ $selectedRole->name }}" disabled>
                                    <small>System role names are fixed to preserve existing PATH workflow rules.</small>
                                </label>
                            @endif
                            <label>
                                Description
                                <textarea name="description" rows="3" maxlength="500" placeholder="Describe the responsibility of this role…">{{ old('description',$selectedRole->description) }}</textarea>
                                <small>This appears only in access management and does not affect permissions.</small>
                            </label>
                        </div>
                    @endunless

                    <div class="permission-toolbar">
                        <div>
                            <span class="eyebrow">MODULE ACCESS</span>
                            <h3>Permission matrix</h3>
                            <p>Enable only the actions this role needs. Changes take effect the next time each protected route or page is requested.</p>
                        </div>
                        @unless($selectedRole->is_protected)
                            <div class="permission-toolbar-actions">
                                <button class="btn ghost" type="button" data-permission-all="on">Select all</button>
                                <button class="btn ghost" type="button" data-permission-all="off">Clear all</button>
                            </div>
                        @endunless
                    </div>

                    <div class="permission-modules">
                        @foreach($modules as $moduleKey => $module)
                            <article class="permission-module" data-permission-module>
                                <div class="permission-module-head">
                                    <div class="permission-module-title">
                                        <span><i class="fa-solid {{ $module['icon'] }}" aria-hidden="true"></i></span>
                                        <div><h4>{{ $module['label'] }}</h4><p>{{ $module['description'] }}</p></div>
                                    </div>
                                    @unless($selectedRole->is_protected)
                                        <button type="button" class="module-toggle" data-module-toggle>Select module</button>
                                    @endunless
                                </div>
                                <div class="permission-grid">
                                    @foreach($module['permissions'] as $item)
                                        @php
                                            $permission = $permissions->get($item['key']);
                                            $checked = in_array($item['key'],$selectedKeys,true);
                                            $pstoLocked = $selectedRole->slug === 'psto' && (in_array($item['key'], $pstoRequiredKeys, true) || in_array($item['key'], $pstoRestrictedKeys, true));
                                            if ($selectedRole->slug === 'psto' && in_array($item['key'], $pstoRequiredKeys, true)) $checked = true;
                                            if ($selectedRole->slug === 'psto' && in_array($item['key'], $pstoRestrictedKeys, true)) $checked = false;
                                        @endphp
                                        <label class="permission-option {{ $checked ? 'is-enabled' : '' }}">
                                            <input
                                                type="checkbox"
                                                name="permissions[]"
                                                value="{{ $item['key'] }}"
                                                @checked($checked)
                                                @disabled($selectedRole->is_protected || $pstoLocked)
                                            >
                                            @if($selectedRole->slug === 'psto' && in_array($item['key'], $pstoRequiredKeys, true))
                                                <input type="hidden" name="permissions[]" value="{{ $item['key'] }}">
                                            @endif
                                            <span class="permission-check"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
                                            <span class="permission-copy">
                                                <b>{{ $item['action'] }}</b>
                                                <small>{{ $item['label'] }}</small>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </article>
                        @endforeach
                    </div>

                    <div class="role-form-actions">
                        <div class="role-security-footnote">
                            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                            <span>Permanent Delete and Roles & Permissions administration remain Admin-only regardless of this matrix.</span>
                        </div>
                        @unless($selectedRole->is_protected)
                            <button class="btn primary" type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save permissions</button>
                        @endunless
                    </div>
                </form>

                @if(!$selectedRole->is_system && !$selectedRole->is_protected)
                    <div class="custom-role-danger">
                        <div><b>Delete custom role</b><p>A custom role can only be deleted after every active or archived user has been reassigned to another role.</p></div>
                        <form method="POST" action="{{ route('roles.destroy',$selectedRole) }}"
                              data-confirm-title="Delete custom role?"
                              data-confirm-message="{{ $selectedRole->name }} will be permanently removed from Role Management. This is only allowed when no user accounts are assigned to it."
                              data-confirm-button="Delete role">
                            @csrf
                            @method('DELETE')
                            <button class="btn archive-permanent" type="submit">Delete role</button>
                        </form>
                    </div>
                @endif
            @else
                <div class="archive-empty"><i class="fa-solid fa-shield-halved"></i><h3>No roles configured</h3><p>Run the RBAC migration to create the PATH system roles.</p></div>
            @endif
        </section>
    </div>
</div>

<dialog id="newRoleModal" class="modal role-create-modal">
    <form method="POST" action="{{ route('roles.store') }}" class="modal-card role-create-card">
        @csrf
        <div class="role-create-head">
            <div><span class="eyebrow">CUSTOM ACCESS PROFILE</span><h2>Create role</h2><p>Create a reusable role, then choose its module permissions.</p></div>
            <button class="modal-close-button" type="button" onclick="document.getElementById('newRoleModal').close()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>

        @if($errors->createRole->any())
            <div class="form-error"><b>We could not create the role.</b><ul>@foreach($errors->createRole->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <div class="role-create-body">
            <label>
                Role name
                <input name="name" maxlength="30" value="{{ old('name') }}" placeholder="e.g. Planning Officer" required autofocus>
                <small>Up to 30 characters. The role becomes available in User Management immediately.</small>
            </label>
            <label>
                Description <span>Optional</span>
                <textarea name="description" maxlength="500" rows="4" placeholder="What is this role responsible for?">{{ old('description') }}</textarea>
            </label>
            <div class="role-create-scope">
                <i class="fa-solid fa-earth-asia" aria-hidden="true"></i>
                <div><b>Global data scope</b><p>Custom roles start with global scope. PSTO and Evaluator keep their protected province/assignment scoping rules.</p></div>
            </div>
        </div>

        <div class="role-create-actions">
            <button class="btn ghost" type="button" onclick="document.getElementById('newRoleModal').close()">Cancel</button>
            <button class="btn primary" type="submit"><i class="fa-solid fa-plus"></i> Create role</button>
        </div>
    </form>
</dialog>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const permissionForm = document.getElementById('rolePermissionForm');
    if (permissionForm) {
        const updateOption = (checkbox) => checkbox.closest('.permission-option')?.classList.toggle('is-enabled', checkbox.checked);
        permissionForm.querySelectorAll('input[name="permissions[]"]').forEach((checkbox) => {
            checkbox.addEventListener('change', () => updateOption(checkbox));
            updateOption(checkbox);
        });

        permissionForm.querySelectorAll('[data-module-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const module = button.closest('[data-permission-module]');
                const boxes = [...module.querySelectorAll('input[name="permissions[]"]:not(:disabled)')];
                const enable = boxes.some(box => !box.checked);
                boxes.forEach(box => { box.checked = enable; updateOption(box); });
                button.textContent = enable ? 'Clear module' : 'Select module';
            });
        });

        document.querySelectorAll('[data-permission-all]').forEach((button) => {
            button.addEventListener('click', () => {
                const checked = button.dataset.permissionAll === 'on';
                permissionForm.querySelectorAll('input[name="permissions[]"]:not(:disabled)').forEach(box => {
                    box.checked = checked;
                    updateOption(box);
                });
            });
        });
    }

    @if($errors->createRole->any())
        document.getElementById('newRoleModal')?.showModal();
    @endif
});
</script>
@endsection
