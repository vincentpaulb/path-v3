<?php

namespace App\Http\Controllers;

use App\Models\Evaluator;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EvaluatorController extends Controller
{
    public function index(Request $request)
    {
        $evaluators = Evaluator::query()
            ->with('account:id,username,email,role')
            ->withCount([
                'proposals as currently_evaluating_count' => fn ($query) => $query->whereIn('evaluator_proposal.evaluation_status', ['Assigned', 'Started', 'Ongoing']),
                'proposals as evaluated_count' => fn ($query) => $query->where('evaluator_proposal.evaluation_status', 'Approved'),
            ])
            ->orderBy('last_name')
            ->paginate(8);

        return view('evaluators.index', compact('evaluators'));
    }

    public function store(Request $request)
    {
        if (!$request->filled('password')) {
            $request->merge(['password' => $this->generatePassword()]);
        }

        $data = $this->validateEvaluator($request);
        $account = $this->validateAccount($request);

        DB::transaction(function () use ($data, $account) {
            Evaluator::create($data);
            $evaluatorRole = Role::query()->where('slug', 'evaluator')->firstOrFail();

            User::create([
                'username' => $account['username'],
                'password' => $account['password'],
                'first_name' => $data['first_name'],
                'middle_initial' => $data['middle_initial'] ?? null,
                'last_name' => $data['last_name'],
                'company' => $data['organization'],
                'mobile_number' => $data['contact_number'] ?? null,
                'email' => $data['email'],
                'job_desc' => $data['designation'],
                'role' => 'Evaluator',
                'role_id' => $evaluatorRole->id,
                'province' => null,
            ]);
        });

        return back()->with('success', 'Evaluator and portal account created successfully.');
    }

    public function show(Evaluator $evaluator)
    {
        $evaluator->load('account');
        return view('evaluators.show', compact('evaluator'));
    }

    public function edit(Evaluator $evaluator)
    {
        $evaluator->load('account');
        return view('evaluators.edit', compact('evaluator'));
    }

    public function update(Request $request, Evaluator $evaluator)
    {
        $evaluator->load('account');
        $data = $this->validateEvaluator($request, $evaluator);
        $account = $this->validateAccount($request, $evaluator->account, false);

        DB::transaction(function () use ($evaluator, $data, $account) {
            $oldAccount = $evaluator->account;
            $evaluatorRole = Role::query()->where('slug', 'evaluator')->firstOrFail();
            $evaluator->update($data);

            $accountData = [
                'username' => $account['username'],
                'first_name' => $data['first_name'],
                'middle_initial' => $data['middle_initial'] ?? null,
                'last_name' => $data['last_name'],
                'company' => $data['organization'],
                'mobile_number' => $data['contact_number'] ?? null,
                'email' => $data['email'],
                'job_desc' => $data['designation'],
                'role' => 'Evaluator',
                'role_id' => $evaluatorRole->id,
                'province' => null,
            ];

            if (!empty($account['password'])) {
                $accountData['password'] = $account['password'];
            }

            if ($oldAccount) {
                $oldAccount->update($accountData);
            } else {
                if (empty($accountData['password'])) {
                    $accountData['password'] = $this->generatePassword();
                }
                User::create($accountData);
            }
        });

        return redirect()->route('evaluators.show', $evaluator)->with('success', 'Evaluator and portal account updated successfully.');
    }

    public function destroy(Request $request, Evaluator $evaluator)
    {
        $evaluator->load('account');

        DB::transaction(function () use ($request, $evaluator) {
            $evaluator->forceFill(['deleted_by' => $request->user()->id])->save();
            $evaluator->delete();

            if ($evaluator->account && ! $evaluator->account->trashed()) {
                $evaluator->account->forceFill(['deleted_by' => $request->user()->id])->save();
                $evaluator->account->delete();
            }
        });

        return redirect()->route('evaluators.index')->with('success', 'Evaluator moved to Archives. Portal access has been suspended and will be restored with the evaluator.');
    }

    private function validateEvaluator(Request $request, ?Evaluator $evaluator = null): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:50'],
            'middle_initial' => ['nullable', 'string', 'max:2'],
            'last_name' => ['required', 'string', 'max:50'],
            'sex' => ['required', Rule::in(['Female', 'Male', 'Prefer not to say'])],
            'classification' => ['required', Rule::in(['Internal', 'External'])],
            'email' => [
                'required', 'email', 'max:100',
                $evaluator ? Rule::unique('evaluators', 'email')->ignore($evaluator) : Rule::unique('evaluators', 'email'),
            ],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'designation' => ['required', 'string', 'max:150'],
            'organization' => ['required', 'string', 'max:200'],
        ]);
    }

    private function validateAccount(Request $request, ?User $account = null, bool $passwordRequired = true): array
    {
        $passwordRules = $passwordRequired
            ? ['required', 'string', 'min:8', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[^A-Za-z0-9]/']
            : ['nullable', 'string', 'min:8', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[^A-Za-z0-9]/'];

        return $request->validate([
            'username' => [
                'required', 'string', 'max:100',
                $account ? Rule::unique('users', 'username')->ignore($account) : Rule::unique('users', 'username'),
            ],
            'password' => $passwordRules,
            'email' => [
                'required', 'email', 'max:100',
                $account ? Rule::unique('users', 'email')->ignore($account) : Rule::unique('users', 'email'),
            ],
        ], [
            'password.regex' => 'The password must contain at least one uppercase letter, one lowercase letter, and one special character.',
        ]);
    }

    private function generatePassword(int $length = 10): string
    {
        $length = max(8, $length);
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $numbers = '23456789';
        $special = '!@#$%^&*';
        $all = $lower . $upper . $numbers . $special;

        $chars = [
            $lower[random_int(0, strlen($lower) - 1)],
            $upper[random_int(0, strlen($upper) - 1)],
            $special[random_int(0, strlen($special) - 1)],
            $numbers[random_int(0, strlen($numbers) - 1)],
        ];

        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }

        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
