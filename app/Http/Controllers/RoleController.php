<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $roles = Role::query()
            ->withCount('users')
            ->orderByDesc('is_protected')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        $selected = null;
        if ($request->filled('role')) {
            $selected = $roles->firstWhere('id', (int) $request->integer('role'));
        }
        $selected ??= $roles->first();
        $selected?->load('permissions');

        return view('roles.index', [
            'roles' => $roles,
            'selectedRole' => $selected,
            'modules' => PermissionCatalog::modules(),
            'permissions' => Permission::query()->orderBy('sort_order')->get()->keyBy('key'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validateWithBag('createRole', [
            // users.role is kept as a legacy compatibility field and is 30 chars.
            'name' => ['required', 'string', 'max:30', 'unique:roles,name'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $name = trim($data['name']);
        $slug = Str::slug($name);
        if ($slug === '' || Role::where('slug', $slug)->exists()) {
            return back()->withErrors(['name' => 'Please use a unique role name.'], 'createRole')->withInput();
        }

        $role = Role::create([
            'name' => $name,
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'data_scope' => 'global',
            'is_system' => false,
            'is_protected' => false,
        ]);

        return redirect()->route('roles.index', ['role' => $role->id])
            ->with('success', 'Role created. Assign the modules and actions this role may access.');
    }

    public function update(Request $request, Role $role)
    {
        if ($role->slug === 'admin' || $role->is_protected) {
            return back()->with('error', 'The Admin role is protected and always has full system access.');
        }

        $rules = [
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'key')],
        ];

        if (! $role->is_system) {
            $rules['name'] = ['required', 'string', 'max:30', Rule::unique('roles', 'name')->ignore($role)];
        }

        $data = $request->validate($rules);
        $oldName = $role->name;

        $newName = $role->name;
        $newSlug = $role->slug;
        if (! $role->is_system) {
            $newName = trim($data['name']);
            $newSlug = Str::slug($newName);

            if ($newSlug === '' || Role::where('slug', $newSlug)->whereKeyNot($role->id)->exists()) {
                return back()->withErrors(['name' => 'Please use a unique role name.'])->withInput();
            }
        }

        DB::transaction(function () use ($role, $data, $oldName, $newName, $newSlug) {
            if (! $role->is_system) {
                $role->name = $newName;
                $role->slug = $newSlug;
            }

            $role->description = $data['description'] ?? null;
            $role->save();

            $permissionKeys = $data['permissions'] ?? [];

            if ($role->slug === 'psto') {
                $permissionKeys = array_values(array_unique(array_merge(
                    array_diff($permissionKeys, PermissionCatalog::pstoRestrictedPermissions()),
                    PermissionCatalog::pstoRequiredPermissions(),
                )));
            }

            $permissionIds = Permission::query()
                ->whereIn('key', $permissionKeys)
                ->pluck('id')
                ->all();

            $role->permissions()->sync($permissionIds);

            // Keep the legacy users.role text synchronized for existing parts of
            // PATH and for compatibility with older database/report snapshots.
            if ($oldName !== $role->name) {
                $role->users()->withTrashed()->update(['role' => $role->name]);
            }
        });

        return redirect()->route('roles.index', ['role' => $role->id])
            ->with('success', "Permissions updated for {$role->name}.");
    }

    public function destroy(Role $role)
    {
        if ($role->is_system || $role->is_protected) {
            return back()->with('error', 'System roles cannot be deleted. You can adjust their permissions instead.');
        }

        if ($role->users()->withTrashed()->exists()) {
            return back()->with('error', 'This role is still assigned to one or more user accounts. Reassign those users before deleting the role.');
        }

        $role->delete();

        return redirect()->route('roles.index')->with('success', 'Custom role deleted.');
    }
}
