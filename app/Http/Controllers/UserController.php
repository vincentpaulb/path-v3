<?php

namespace App\Http\Controllers;

use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {

        $range = new \App\Support\DateRangeFilter($request);        $query = User::query()->with('accessRole');

        $range->apply($query, 'users.created_at');

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            foreach (preg_split('/\s+/', $search) ?: [] as $term) {
                $query->where(function ($subQuery) use ($term) {
                    $like = '%'.$term.'%';
                    $subQuery
                        ->where('first_name', 'like', $like)
                        ->orWhere('middle_initial', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('username', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('mobile_number', 'like', $like)
                        ->orWhere('company', 'like', $like)
                        ->orWhere('job_desc', 'like', $like)
                        ->orWhere('province', 'like', $like)
                        ->orWhere('role', 'like', $like);
                });
            }
        }

        if ($request->filled('role_id')) {
            $query->where('role_id', (int) $request->input('role_id'));
        }

        if ($request->filled('province')) {
            $query->where('province', (string) $request->input('province'));
        }

        if ($request->filled('organization')) {
            $query->where('company', (string) $request->input('organization'));
        }

        $sort = (string) $request->input('sort', 'name_asc');
        match ($sort) {
            'name_desc' => $query->orderByDesc('last_name')->orderByDesc('first_name'),
            'username_asc' => $query->orderBy('username')->orderBy('last_name'),
            'username_desc' => $query->orderByDesc('username')->orderBy('last_name'),
            'role_asc' => $query->orderBy('role')->orderBy('last_name')->orderBy('first_name'),
            'newest' => $query->orderByDesc('created_at'),
            'oldest' => $query->orderBy('created_at'),
            default => $query->orderBy('last_name')->orderBy('first_name'),
        };

        return view('users.index', [
            'users' => $query->paginate(8)->withQueryString(),
            'roles' => $this->assignableRoles($request->user()),
            'filterRoles' => Role::query()->orderBy('name')->get(['id', 'name']),
            'provinces' => Province::activeValues(),
            'filterProvinces' => Province::allValues(),
            'organizations' => User::query()
                ->whereNotNull('company')
                ->where('company', '<>', '')
                ->distinct()
                ->orderBy('company')
                ->pluck('company'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string|max:100|unique:users',
            'first_name' => 'required|string|max:50',
            'middle_initial' => 'nullable|string|max:2',
            'last_name' => 'required|string|max:50',
            'company' => 'nullable|string|max:100',
            'mobile_number' => 'nullable|string|max:20',
            'email' => 'required|email|unique:users',
            'job_desc' => 'nullable|string|max:100',
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'province' => ['nullable', Rule::in(Province::activeValues())],
            'password' => 'required|string|min:10|confirmed',
        ]);

        $role = Role::findOrFail($data['role_id']);
        $this->guardAssignableRole($request->user(), $role);
        abort_if($role->slug === 'evaluator', 422, 'Evaluator accounts are created from Evaluator Management so the directory and portal account remain synchronized.');

        if ($role->data_scope === 'province' && blank($data['province'] ?? null)) {
            return back()->withErrors(['province' => 'Province is required for roles with provincial data scope.'])->withInput();
        }

        if ($role->data_scope !== 'province') {
            $data['province'] = null;
        }

        $data['role'] = $role->name;
        User::create($data);

        return back()->with('success', 'User account created.');
    }

    public function show(User $user)
    {
        $user->load('accessRole.permissions');
        return view('users.show', compact('user'));
    }

    public function edit(Request $request, User $user)
    {
        $this->guardProtectedAccount($request->user(), $user);

        if ($user->isEvaluator()) {
            return redirect()->route('evaluators.index')->with('error', 'Evaluator accounts are edited from Evaluator Management so the directory and portal account remain synchronized.');
        }

        return view('users.edit', [
            'user' => $user->load('accessRole'),
            'roles' => $this->assignableRoles($request->user()),
            'provinces' => Province::selectionValues($user->province),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $this->guardProtectedAccount($request->user(), $user);
        abort_if($user->isEvaluator(), 422, 'Evaluator accounts are edited from Evaluator Management.');

        $data = $request->validate([
            'username' => ['required', 'string', 'max:100', Rule::unique('users', 'username')->ignore($user)],
            'first_name' => 'required|string|max:50',
            'middle_initial' => 'nullable|string|max:2',
            'last_name' => 'required|string|max:50',
            'company' => 'nullable|string|max:100',
            'mobile_number' => 'nullable|string|max:20',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user)],
            'job_desc' => 'nullable|string|max:100',
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'province' => ['nullable', Rule::in(Province::selectionValues($user->province))],
            'password' => ['nullable', 'string', 'min:8', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[^A-Za-z0-9]/'],
        ], [
            'password.regex' => 'The password must contain at least one uppercase letter, one lowercase letter, and one special character.',
        ]);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $role = Role::findOrFail($data['role_id']);
        $this->guardAssignableRole($request->user(), $role);
        abort_if($role->slug === 'evaluator', 422, 'Evaluator accounts are managed from Evaluator Management.');

        if ($user->is($request->user()) && $user->isAdmin() && $role->slug !== 'admin') {
            return back()->withErrors(['role_id' => 'You cannot remove your own protected Admin role.']);
        }

        if ($role->data_scope === 'province' && blank($data['province'] ?? null)) {
            return back()->withErrors(['province' => 'Province is required for roles with provincial data scope.'])->withInput();
        }

        if ($role->data_scope !== 'province') {
            $data['province'] = null;
        }

        $data['role'] = $role->name;
        $user->update($data);

        return redirect()->route('users.show', $user)->with('success', 'User updated successfully.');
    }

    public function destroy(Request $request, User $user)
    {
        $this->guardProtectedAccount($request->user(), $user);
        abort_if($request->user()->is($user), 422, 'You cannot archive your own account.');
        abort_if($user->isEvaluator(), 422, 'Evaluator portal accounts are archived from Evaluator Management so the directory and login account remain synchronized.');

        $user->forceFill(['deleted_by' => $request->user()->id])->save();
        $user->delete();

        return redirect()->route('users.index')->with('success', 'User account moved to Archives.');
    }

    private function assignableRoles(User $actor)
    {
        return Role::query()
            ->where('slug', '!=', 'evaluator')
            ->when(! $actor->isAdmin(), fn ($query) => $query->where('is_protected', false))
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();
    }

    private function guardAssignableRole(User $actor, Role $role): void
    {
        abort_if($role->is_protected && ! $actor->isAdmin(), 403, 'Only an Admin account can assign a protected system role.');
    }

    private function guardProtectedAccount(User $actor, User $target): void
    {
        if (! $actor->isAdmin() && $target->isAdmin()) {
            abort(403, 'Only an Admin account can modify another protected Admin account.');
        }
    }
}
