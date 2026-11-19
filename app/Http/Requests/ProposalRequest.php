<?php

namespace App\Http\Requests;

use App\Enums\{DocumentStatus, ProposalStatus, Sector};
use App\Models\Province;
use App\Models\IndustrySector;
use App\Models\Proposal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        return $this->routeIs('proposals.store')
            ? auth()->user()->hasPermission('proposals.create')
            : auth()->user()->hasPermission('proposals.edit');
    }

    public function rules(): array
    {
        $proposal = $this->route('proposal');
        $currentProvince = $proposal?->province;
        if (auth()->check() && auth()->user()->dataScope() === 'province') {
            $currentProvince = auth()->user()->province ?: $currentProvince;
        }
        $allowedProvinces = Province::selectionValues($currentProvince);
        $currentIndustrySectorId = $proposal?->industry_sector_id;
        $allowedIndustrySectorIds = IndustrySector::query()
            ->where('is_active', true)
            ->when($currentIndustrySectorId, fn ($query) => $query->orWhere('id', $currentIndustrySectorId))
            ->pluck('id');

        $industrySectorRules = ['nullable', 'integer', Rule::in($allowedIndustrySectorIds->all())];
        $approvalDateRules = ['nullable', 'date'];
        $statusRules = ['required', Rule::in(ProposalStatus::values())];
        if ($proposal?->proposal_code) {
            $allowedProvinces = [$proposal->province];
            $industrySectorRules = ['required', 'integer', Rule::in([(int) $proposal->industry_sector_id])];
            $approvalDateRules[] = Rule::in([$proposal->date_approved?->format('Y-m-d')]);
            $statusRules = ['required', Rule::in(['Approved'])];
        }

        return [
            'owner_contact_sex' => ['nullable', Rule::in(['Male', 'Female', 'Prefer not to say'])],
            'project_code' => ['nullable', 'string', 'max:100'],
            'project_title' => ['required', 'string', 'max:500'],
            'proponent' => ['required', 'string', 'max:500'],
            'address' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'location_source' => ['nullable', 'required_with:latitude,longitude', Rule::in(['geocoded', 'manual'])],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:100'],
            'contact_email' => ['nullable', 'email:rfc', 'max:255'],
            'province' => ['required', Rule::in($allowedProvinces)],
            'sector' => ['required', Rule::in(Sector::values())],
            'industry_sector_id' => $industrySectorRules,
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'proposal_link' => ['nullable', 'url', 'max:1000'],
            'status' => $statusRules,
            'funded_project_status' => ['nullable', Rule::in(\App\Enums\FundedProjectStatus::values()), 'prohibited_without:date_funded'],
            'document_status' => ['required', Rule::in(DocumentStatus::values())],
            'quarter_submitted' => ['required', 'integer', 'between:1,4'],
            'date_submitted' => ['required', 'date'],
            'date_updated' => ['nullable', 'date'],
            'quarter_approved' => ['nullable', 'integer', 'between:1,4'],
            'quarter_funded' => ['nullable', 'integer', 'between:1,4'],
            'date_approved' => $approvalDateRules,
            'date_funded' => ['nullable', 'date'],
            'evaluators' => ['nullable', 'array'],
            'evaluators.*' => ['integer', 'distinct', 'exists:evaluators,id'],
            'required_documents' => ['nullable', 'array'],
            'required_documents.*' => ['integer', 'distinct', 'exists:required_documents,id'],
        ];
    }

    public function messages(): array
    {
        $isCodedProposal = $this->route('proposal') instanceof Proposal
            && filled($this->route('proposal')->proposal_code);

        return [
            'province.in' => $isCodedProposal
                ? 'The province cannot be changed after a Proposal Code has been generated.'
                : 'Select a valid active province.',
            'industry_sector_id.in' => $isCodedProposal
                ? 'The industry sector cannot be changed after a Proposal Code has been generated.'
                : 'Select a valid active industry sector.',
            'date_approved.in' => 'The approval date cannot be changed after a Proposal Code has been generated.',
            'status.in' => $isCodedProposal
                ? 'A coded proposal must remain Approved.'
                : 'Select a valid proposal status.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $latitude = $this->input('latitude');
        $longitude = $this->input('longitude');

        $this->merge([
            'latitude' => $latitude === '' ? null : $latitude,
            'longitude' => $longitude === '' ? null : $longitude,
            'location_source' => ($latitude === '' || $latitude === null || $longitude === '' || $longitude === null)
                ? null
                : $this->input('location_source'),
        ]);
    }
}
