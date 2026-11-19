@extends('layouts.app')
@php
    $edit = $proposal->exists;
    $proposalCodeLocked = filled($proposal->proposal_code);
    $selectedEvaluators = old('evaluators', $proposal->evaluators->pluck('id')->all());
    $selectedRequiredDocuments = session()->hasOldInput('required_documents_present')
        ? collect(old('required_documents', []))->map(fn ($id) => (int) $id)->all()
        : ($selectedRequiredDocumentIds ?? []);
    $requiredDocumentCount = $requiredDocuments->count();
    $storedRequiredDocumentStatuses = $storedRequiredDocumentStatuses ?? [];
    $submittedDocumentCount = count($selectedRequiredDocuments);
    $derivedDocumentStatus = $requiredDocumentCount
        ? ($submittedDocumentCount === $requiredDocumentCount ? 'Complete' : 'Incomplete')
        : old('document_status', $proposal->document_status ?: 'Incomplete');
@endphp
@push('styles')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
@endpush
@section('title', $edit ? 'Edit Proposal' : 'New Proposal')
@section('page-title', $edit ? 'Edit Proposal' : 'New Proposal')
@section('top-back')
    <a href="{{ $edit ? route('proposals.show', $proposal) : route('proposals.index') }}" class="back topbar-back">← {{ $edit ? 'Back to proposal' : 'Back to proposals' }}</a>
@endsection

@section('content')

<div class="form-heading">
    <div>
        <h1>{{ $edit ? 'Update proposal record' : 'Create proposal record' }}</h1>
    </div>
</div>

@if ($errors->any())
    <div class="alert danger" role="alert">Please correct the highlighted fields before saving the proposal.</div>
@endif

<form method="POST" action="{{ $edit ? route('proposals.update', $proposal) : route('proposals.store') }}" class="form-layout proposal-form" id="proposalRecordForm">
    @csrf
    @if ($edit) @method('PUT') @endif

    <section class="panel proposal-project-panel">
        <div class="panel-head"><div><span class="step">01</span><h2>Proposal information</h2></div></div>
        <div class="form-grid">
            <label class="span-2">Project Code
                <input name="project_code" value="{{ old('project_code', $proposal->project_code) }}" maxlength="100">
                @error('project_code')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <label class="span-2">Proposal title
                <input name="project_title" value="{{ old('project_title', $proposal->project_title) }}" required maxlength="500" aria-invalid="@error('project_title') true @else false @enderror">
                @error('project_title')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <label class="span-2">Proponent
                <input name="proponent" value="{{ old('proponent', $proposal->proponent) }}" required maxlength="500" aria-invalid="@error('proponent') true @else false @enderror">
                @error('proponent')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <div class="proposal-location-field span-2">
                <div class="proposal-location-controls">
                    <label for="proposalAddress">Address</label>
                    <textarea id="proposalAddress" name="address" rows="3" maxlength="1000" autocomplete="street-address" placeholder="Complete physical address (street/barangay, city or municipality, province)" aria-invalid="@error('address') true @else false @enderror">{{ old('address', $proposal->address) }}</textarea>
                    <div class="proposal-map-toolbar">
                        <button class="btn proposal-map-search" id="proposalMapSearch" type="button"><i class="fa-solid fa-magnifying-glass-location"></i> Find on map</button>
                        <button class="btn ghost proposal-map-clear" id="proposalMapClear" type="button"><i class="fa-solid fa-location-crosshairs"></i> Clear pin</button>
                    </div>
                    <span class="proposal-map-status" id="proposalMapStatus" role="status" aria-live="polite">Search the address or click the map to place the proposal pin.</span>
                    <div class="proposal-map-results" id="proposalMapResults" hidden></div>
                    <details class="proposal-coordinate-entry">
                        <summary>Use verified coordinates instead</summary>
                        <label for="proposalVerifiedCoordinates">Latitude, longitude</label>
                        <input id="proposalVerifiedCoordinates" type="text" placeholder="e.g. 10.3157000, 123.8854000" autocomplete="off">
                        <button class="btn ghost" id="proposalUseCoordinates" type="button">Place pin at coordinates</button>
                        <small>Enter coordinates verified for the project site. This works without address search and keeps the full address unchanged.</small>
                    </details>
                    <div class="proposal-map-coordinates">
                        <i class="fa-solid fa-map-pin" aria-hidden="true"></i>
                        <span id="proposalCoordinateText">No map location selected</span>
                    </div>
                    <input type="hidden" id="proposalLatitude" name="latitude" value="{{ old('latitude', $proposal->latitude) }}">
                    <input type="hidden" id="proposalLongitude" name="longitude" value="{{ old('longitude', $proposal->longitude) }}">
                    <input type="hidden" id="proposalLocationSource" name="location_source" value="{{ old('location_source', $proposal->location_source) }}">
                    <small>Free OpenStreetMap selector. Searches are user-triggered, rate-limited, and cached.</small>
                    @error('address')<span class="field-error">{{ $message }}</span>@enderror
                    @error('latitude')<span class="field-error">{{ $message }}</span>@enderror
                    @error('longitude')<span class="field-error">{{ $message }}</span>@enderror
                    @error('location_source')<span class="field-error">{{ $message }}</span>@enderror
                </div>
                <div class="proposal-location-map-column">
                    <div
                        id="proposalLocationMap"
                        class="proposal-location-map"
                        data-search-url="{{ route('geocoding.search') }}"
                        data-tile-url="{{ config('geocoding.tile_url') }}"
                        data-tile-attribution="{{ config('geocoding.tile_attribution') }}"
                        data-default-latitude="{{ config('geocoding.default_latitude') }}"
                        data-default-longitude="{{ config('geocoding.default_longitude') }}"
                        data-default-zoom="{{ config('geocoding.default_zoom') }}"
                        aria-label="Select the proposal location on the map"
                    ></div>
                    <small><i class="fa-solid fa-hand-pointer" aria-hidden="true"></i> Click the map or drag the pin to refine the location.</small>
                </div>
            </div>
            <label>Project owner / contact person sex
                <select name="owner_contact_sex">
                    <option value="">Not recorded</option>
                    @foreach(['Male', 'Female', 'Prefer not to say'] as $sex)
                        <option value="{{ $sex }}" @selected(old('owner_contact_sex', $proposal->owner_contact_sex) === $sex)>{{ $sex }}</option>
                    @endforeach
                </select>
                @error('owner_contact_sex')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <label class="span-2">Contact person
                <input name="contact_person" value="{{ old('contact_person', $proposal->contact_person) }}" maxlength="255" autocomplete="name" aria-invalid="@error('contact_person') true @else false @enderror">
                @error('contact_person')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <label>Phone number
                <input type="tel" name="contact_phone" value="{{ old('contact_phone', $proposal->contact_phone) }}" maxlength="100" inputmode="tel" autocomplete="tel" placeholder="e.g. +63 917 123 4567" aria-invalid="@error('contact_phone') true @else false @enderror">
                @error('contact_phone')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <label>Email address
                <input type="email" name="contact_email" value="{{ old('contact_email', $proposal->contact_email) }}" maxlength="255" autocomplete="email" placeholder="e.g. contact@example.com" aria-invalid="@error('contact_email') true @else false @enderror">
                @error('contact_email')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <label>Program sector
                <select name="sector" required>
                    @foreach ($sectors as $sector)
                        <option value="{{ $sector }}" @selected(old('sector', $proposal->sector) === $sector)>{{ $sector }}</option>
                    @endforeach
                </select>
                @error('sector')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <label>Industry sector
                <select name="industry_sector_id" {{ $proposalCodeLocked ? 'disabled' : '' }}>
                    <option value="">Select before approval</option>
                    @foreach($industrySectors as $industrySector)
                        <option value="{{ $industrySector->id }}" @selected((string) old('industry_sector_id', $proposal->industry_sector_id) === (string) $industrySector->id)>{{ $industrySector->code }} — {{ $industrySector->name }}</option>
                    @endforeach
                </select>
                @if($proposalCodeLocked)<input type="hidden" name="industry_sector_id" value="{{ $proposal->industry_sector_id }}">@endif
                <small>{{ $proposalCodeLocked ? 'Locked because the Proposal Code has already been generated.' : 'Used in the Proposal Code. Once a code is generated, it remains permanent.' }}</small>
                @error('industry_sector_id')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <label>Province
                <select name="province" required {{ auth()->user()->dataScope() === 'province' || $proposalCodeLocked ? 'disabled' : '' }}>
                    @foreach ($provinces as $province)
                        <option value="{{ $province }}" @selected(old('province', $proposal->province ?: auth()->user()->province) === $province)>{{ $province }}</option>
                    @endforeach
                </select>
                @if (auth()->user()->dataScope() === 'province' || $proposalCodeLocked)
                    <input type="hidden" name="province" value="{{ $proposalCodeLocked ? $proposal->province : auth()->user()->province }}">
                @endif
                @if($proposalCodeLocked)<small>Locked because the Proposal Code has already been generated.</small>@endif
                @error('province')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <label>Requested amount (PHP)
                <input type="number" step="0.01" min="0" max="9999999999.99" inputmode="decimal" name="amount" value="{{ old('amount', $proposal->amount) }}" required>
                @error('amount')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <label>Google Drive / proposal link
                <input type="url" name="proposal_link" value="{{ old('proposal_link', $proposal->proposal_link) }}" placeholder="https://drive.google.com/...">
                @error('proposal_link')<span class="field-error">{{ $message }}</span>@enderror
            </label>
        </div>
    </section>

    <section class="panel proposal-submission-panel">
        <div class="panel-head"><div><span class="step">02</span><h2>Submission & evaluation</h2></div></div>
        <div class="form-grid">
            <label>Date submitted
                <input id="date_submitted" type="date" name="date_submitted" value="{{ old('date_submitted', $proposal->date_submitted?->format('Y-m-d')) }}" required>
                @error('date_submitted')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <label>Submission quarter
                <select id="quarter_submitted" name="quarter_submitted" required>
                    @for ($quarter = 1; $quarter <= 4; $quarter++)
                        <option value="{{ $quarter }}" @selected(old('quarter_submitted', $proposal->quarter_submitted ?: 1) == $quarter)>Quarter {{ $quarter }}</option>
                    @endfor
                </select>
                @error('quarter_submitted')<span class="field-error">{{ $message }}</span>@enderror
            </label>

            @if($requiredDocumentCount)
                @php $lockedDocumentStatus = $edit ? ($proposal->document_status ?: 'Incomplete') : 'Incomplete'; @endphp
                <label>Document status
                    <select id="document_status_display" disabled aria-label="Document status calculated from Required Documents checklist">
                        @foreach ($documentStatuses as $documentStatus)
                            <option value="{{ $documentStatus }}" @selected(($canManageChecklist ? $derivedDocumentStatus : $lockedDocumentStatus) === $documentStatus)>{{ $documentStatus }}</option>
                        @endforeach
                    </select>
                    <input type="hidden" id="document_status" name="document_status" value="{{ $canManageChecklist ? $derivedDocumentStatus : $lockedDocumentStatus }}">
                    <small>{{ $canManageChecklist ? 'Automatically calculated from the Required Documents checklist.' : 'Read-only for your role. Document readiness is managed by authorized reviewers.' }}</small>
                    @error('document_status')<span class="field-error">{{ $message }}</span>@enderror
                </label>
            @else
                <label>Document status
                    <select id="document_status" name="document_status" required>
                        @foreach ($documentStatuses as $documentStatus)
                            <option value="{{ $documentStatus }}" @selected(old('document_status', $proposal->document_status ?: 'Incomplete') === $documentStatus)>{{ $documentStatus }}</option>
                        @endforeach
                    </select>
                    @error('document_status')<span class="field-error">{{ $message }}</span>@enderror
                </label>
            @endif

            <label>Proposal status
                <select name="status" id="proposalStatus" required>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(old('status', $proposal->status ?: 'Ongoing Evaluation') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
                @error('status')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <label>Funded Project Status
                <select name="funded_project_status">
                    <option value="">Automatic: New when Date Funded is recorded</option>
                    @foreach(\App\Enums\FundedProjectStatus::values() as $status)<option @selected(old('funded_project_status', $proposal->funded_project_status) === $status)>{{ $status }}</option>@endforeach
                </select>
                @error('funded_project_status')<span class="field-error">{{ $message }}</span>@enderror
            </label>
        </div>
    </section>

    @if($canManageChecklist)
    <section class="panel proposal-required-panel">
        <div class="panel-head required-form-head">
            <div><span class="step">04</span><h2>Required Documents checklist</h2></div>
            @if($requiredDocumentCount)
                <span class="required-form-progress" id="requiredFormProgress">{{ $submittedDocumentCount }}/{{ $requiredDocumentCount }} submitted</span>
            @endif
        </div>

        @if($requiredDocumentCount)
            <input type="hidden" name="required_documents_present" value="1">
            <div class="required-form-toolbar">
                <div>
                    <b>Document readiness</b>
                    <p>Check every document already submitted or received. Unchecked documents will be saved as lacking.</p>
                </div>
                <div class="required-form-actions">
                    <button class="btn ghost small" type="button" id="markAllRequired">Mark all submitted</button>
                    <button class="btn ghost small" type="button" id="clearAllRequired">Clear all</button>
                </div>
            </div>

            <div class="required-form-checklist" id="requiredFormChecklist" role="group" aria-label="Required Documents checklist">
                @foreach($requiredDocuments as $requiredDocument)
                    @php
                        $requiredId = (int) $requiredDocument->id;
                        $isSelected = in_array($requiredId, $selectedRequiredDocuments, true);
                        $storedStatus = $storedRequiredDocumentStatuses[$requiredId] ?? null;
                        $specialStatus = in_array($storedStatus, ['Not Applicable', 'Pending'], true) ? $storedStatus : null;
                        $stateLabel = $specialStatus === 'Not Applicable'
                            ? 'Not applicable'
                            : ($specialStatus === 'Pending' ? 'Pending review' : ($isSelected ? 'Submitted' : 'Lacking'));
                    @endphp
                    <label class="required-form-item {{ $specialStatus === 'Not Applicable' ? 'is-na' : ($specialStatus === 'Pending' ? 'is-pending' : '') }}">
                        <input
                            type="checkbox"
                            name="required_documents[]"
                            value="{{ $requiredDocument->id }}"
                            @checked($isSelected)
                            data-original-checked="{{ $isSelected ? '1' : '0' }}"
                            @if($specialStatus) data-special-status="{{ $specialStatus }}" @endif
                        >
                        <span class="required-form-checkmark"><i class="fa-solid fa-check"></i></span>
                        <span class="required-form-copy">
                            <b>{{ $requiredDocument->name }}</b>
                            <small class="required-form-state">{{ $stateLabel }}</small>
                        </span>
                    </label>
                @endforeach
            </div>

            <div class="required-form-summary">
                <div class="submitted"><span>Submitted</span><b id="requiredSubmittedCount">{{ $submittedDocumentCount }}</b></div>
                <div class="lacking"><span>Lacking</span><b id="requiredLackingCount">{{ $requiredDocumentCount - $submittedDocumentCount }}</b></div>
                <div class="status"><span>Document status</span><b id="requiredStatusText">{{ $derivedDocumentStatus }}</b></div>
            </div>
            @error('required_documents')<span class="field-error">{{ $message }}</span>@enderror
            @error('required_documents.*')<span class="field-error">{{ $message }}</span>@enderror
        @else
            <div class="required-form-empty">
                <i class="fa-regular fa-folder-open"></i>
                <div>
                    <b>No Required Documents are configured.</b>
                    <p>Add the master checklist under Document Control → Required Documents. Until then, Document Status remains manually selectable.</p>
                </div>
                @if(auth()->user()->hasPermission('required_documents.view'))
                    <a class="btn ghost small" href="{{ route('required-documents.index') }}">Manage Required Documents</a>
                @endif
            </div>
        @endif
    </section>
    @endif

    @if($canAssignEvaluators)
    <section class="panel proposal-evaluator-panel">
        <div class="panel-head"><div><span class="step">05</span><h2>Assigned evaluators</h2></div></div>
        <div class="evaluator-groups">
            @forelse(['Internal','External'] as $classification)
                <div class="evaluator-group">
                    <span class="evaluator-group-title">{{ $classification }} evaluators</span>
                    <div class="evaluator-checklist">
                        @forelse($evaluators->where('classification',$classification) as $evaluator)
                            <label class="evaluator-check">
                                <input type="checkbox" name="evaluators[]" value="{{ $evaluator->id }}" @checked(in_array($evaluator->id, $selectedEvaluators))>
                                <span class="evaluator-check-copy">
                                    <b>{{ $evaluator->full_name }}</b>
                                    <small>{{ $evaluator->designation }}{{ $evaluator->organization ? ' · '.$evaluator->organization : '' }}</small>
                                </span>
                            </label>
                        @empty
                            <p class="evaluator-empty">No {{ strtolower($classification) }} evaluators available.</p>
                        @endforelse
                    </div>
                </div>
            @empty
                <p class="empty">No evaluators are available. Add one in Evaluator Management.</p>
            @endforelse
        </div>
        @error('evaluators')<span class="field-error">{{ $message }}</span>@enderror
        @error('evaluators.*')<span class="field-error">{{ $message }}</span>@enderror
    </section>
    @endif

    <section class="panel proposal-milestones-panel">
        <div class="panel-head"><div><span class="step">03</span><h2>Approval & funding milestones</h2></div></div>
        <div class="form-grid">
            <label>Date approved
                <input id="date_approved" type="date" name="date_approved" value="{{ old('date_approved', $proposal->date_approved?->format('Y-m-d')) }}" {{ $proposalCodeLocked ? 'disabled' : '' }}>
                @if($proposalCodeLocked)
                    <input type="hidden" name="date_approved" value="{{ $proposal->date_approved?->format('Y-m-d') }}">
                    <small>Locked because its year is part of the Proposal Code.</small>
                @endif
                @error('date_approved')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <label>Approval quarter
                <select id="quarter_approved" name="quarter_approved">
                    <option value="">Not yet approved</option>
                    @for ($quarter = 1; $quarter <= 4; $quarter++)
                        <option value="{{ $quarter }}" @selected(old('quarter_approved', $proposal->quarter_approved) == $quarter)>Quarter {{ $quarter }}</option>
                    @endfor
                </select>
                @error('quarter_approved')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <label>Date funded
                <input id="date_funded" type="date" name="date_funded" value="{{ old('date_funded', $proposal->date_funded?->format('Y-m-d')) }}">
                @error('date_funded')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <label>Funding quarter
                <select id="quarter_funded" name="quarter_funded">
                    <option value="">Not yet funded</option>
                    @for ($quarter = 1; $quarter <= 4; $quarter++)
                        <option value="{{ $quarter }}" @selected(old('quarter_funded', $proposal->quarter_funded) == $quarter)>Quarter {{ $quarter }}</option>
                    @endfor
                </select>
                @error('quarter_funded')<span class="field-error">{{ $message }}</span>@enderror
            </label>
        </div>
    </section>

    <div class="form-actions">
        <a class="btn ghost" href="{{ $edit ? route('proposals.show', $proposal) : route('proposals.index') }}">Cancel</a>
        <button class="btn primary" type="submit">{{ $edit ? 'Save changes' : 'Create proposal' }}</button>
    </div>
</form>
@endsection

@push('scripts')
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const dateQuarterPairs = [
            ['date_submitted', 'quarter_submitted'],
            ['date_approved', 'quarter_approved'],
            ['date_funded', 'quarter_funded'],
        ];

        dateQuarterPairs.forEach(([dateId, quarterId]) => {
            const dateInput = document.getElementById(dateId);
            const quarterSelect = document.getElementById(quarterId);
            dateInput?.addEventListener('change', () => {
                if (!dateInput.value) {
                    if (dateId !== 'date_submitted') quarterSelect.value = '';
                    return;
                }
                const month = Number(dateInput.value.slice(5, 7));
                quarterSelect.value = String(Math.ceil(month / 3));
            });
        });

        const mapElement = document.getElementById('proposalLocationMap');
        const addressInput = document.getElementById('proposalAddress');
        const latitudeInput = document.getElementById('proposalLatitude');
        const longitudeInput = document.getElementById('proposalLongitude');
        const sourceInput = document.getElementById('proposalLocationSource');
        const coordinateText = document.getElementById('proposalCoordinateText');
        const mapStatusText = document.getElementById('proposalMapStatus');
        const searchButton = document.getElementById('proposalMapSearch');
        const clearButton = document.getElementById('proposalMapClear');
        const resultsElement = document.getElementById('proposalMapResults');
        let proposalMap = null;
        let proposalMarker = null;
        let searchPreview = null;

        const defaultLatitude = Number(mapElement?.dataset.defaultLatitude || 10.3157);
        const defaultLongitude = Number(mapElement?.dataset.defaultLongitude || 123.8854);
        const defaultZoom = Number(mapElement?.dataset.defaultZoom || 8);

        const refreshCoordinateText = () => {
            const latitude = Number(latitudeInput?.value);
            const longitude = Number(longitudeInput?.value);
            const hasCoordinates = latitudeInput?.value !== '' && longitudeInput?.value !== '' && Number.isFinite(latitude) && Number.isFinite(longitude);
            if (coordinateText) coordinateText.textContent = hasCoordinates
                ? `${latitude.toFixed(7)}, ${longitude.toFixed(7)} · ${sourceInput?.value === 'geocoded' ? 'Address search' : 'Manual pin'}`
                : 'No map location selected';
            clearButton?.classList.toggle('is-hidden', !hasCoordinates);
        };

        const setLocation = (latitude, longitude, source = 'manual', zoom = 16) => {
            if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || Math.abs(latitude) > 90 || Math.abs(longitude) > 180) return;
            searchPreview?.clearLayers();
            latitudeInput.value = latitude.toFixed(7);
            longitudeInput.value = longitude.toFixed(7);
            sourceInput.value = source;

            if (proposalMap && window.L) {
                if (!proposalMarker) {
                    proposalMarker = L.marker([latitude, longitude], { draggable: true, autoPan: true, title: 'Proposal location' }).addTo(proposalMap);
                    proposalMarker.on('dragend', event => {
                        const point = event.target.getLatLng();
                        setLocation(point.lat, point.lng, 'manual', proposalMap.getZoom());
                        mapStatusText.textContent = 'Pin moved manually. The updated coordinates will be saved with the proposal.';
                    });
                } else {
                    proposalMarker.setLatLng([latitude, longitude]);
                }
                proposalMap.setView([latitude, longitude], Math.max(proposalMap.getZoom(), zoom));
            }

            refreshCoordinateText();
        };

        const clearLocation = (message = 'Map location cleared. Search the address or click the map to place a new pin.') => {
            searchPreview?.clearLayers();
            if (latitudeInput) latitudeInput.value = '';
            if (longitudeInput) longitudeInput.value = '';
            if (sourceInput) sourceInput.value = '';
            if (proposalMap && proposalMarker) {
                proposalMap.removeLayer(proposalMarker);
                proposalMarker = null;
            }
            if (mapStatusText) mapStatusText.textContent = message;
            refreshCoordinateText();
        };

        if (mapElement && window.L) {
            proposalMap = L.map(mapElement, { scrollWheelZoom: false }).setView([defaultLatitude, defaultLongitude], defaultZoom);
            searchPreview = L.layerGroup().addTo(proposalMap);
            L.tileLayer(mapElement.dataset.tileUrl, {
                maxZoom: 19,
                attribution: mapElement.dataset.tileAttribution,
            }).addTo(proposalMap);
            proposalMap.on('click', event => {
                setLocation(event.latlng.lat, event.latlng.lng, 'manual', proposalMap.getZoom());
                mapStatusText.textContent = 'Location selected manually. Drag the pin if you need to fine-tune it.';
            });

            const initialLatitude = Number(latitudeInput?.value);
            const initialLongitude = Number(longitudeInput?.value);
            if (latitudeInput?.value !== '' && longitudeInput?.value !== '' && Number.isFinite(initialLatitude) && Number.isFinite(initialLongitude)) {
                setLocation(initialLatitude, initialLongitude, sourceInput?.value || 'manual', 16);
                mapStatusText.textContent = 'Saved proposal location loaded. Drag the pin to update it.';
            }
            window.setTimeout(() => proposalMap.invalidateSize(), 50);
        } else if (mapStatusText) {
            mapStatusText.textContent = 'The interactive map could not load. The address can still be saved.';
        }

        const renderSearchResults = (results, suggestions = []) => {
            resultsElement.replaceChildren();
            searchPreview?.clearLayers();
            if (!results.length) {
                const empty = document.createElement('p');
                empty.textContent = 'OpenStreetMap could not match this address. Google place names may differ. Try a simpler search below, or place the pin manually. Street matches may be approximate.';
                resultsElement.appendChild(empty);
                suggestions.forEach(query => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'proposal-map-result';
                    button.textContent = `Search: ${query}`;
                    button.addEventListener('click', () => searchAddress(query));
                    resultsElement.appendChild(button);
                });
            } else {
                const bounds = [];
                results.forEach((result, index) => {
                    const selectResult = () => {
                        setLocation(Number(result.latitude), Number(result.longitude), 'geocoded', 16);
                        mapStatusText.textContent = `${result.match_note || 'Verify the site before saving.'} Drag the pin to refine it, then save the proposal.`;
                        resultsElement.hidden = true;
                    };
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'proposal-map-result';
                    const title = document.createElement('b');
                    title.textContent = `${index + 1}. ${result.label}`;
                    const coordinates = document.createElement('small');
                    coordinates.textContent = `${Number(result.latitude).toFixed(7)}, ${Number(result.longitude).toFixed(7)}`;
                    button.append(title, coordinates);
                    const matchNote = document.createElement('small');
                    matchNote.textContent = result.match_note || 'Verify this location before saving.';
                    button.appendChild(matchNote);
                    button.addEventListener('click', selectResult);
                    resultsElement.appendChild(button);
                    if (proposalMap && searchPreview) {
                        const point = [Number(result.latitude), Number(result.longitude)];
                        const tooltip = document.createElement('span');
                        tooltip.textContent = `${index + 1}. ${result.label}`;
                        L.marker(point, { title: `Select result ${index + 1}: ${result.label}` })
                            .bindTooltip(tooltip).on('click', selectResult).addTo(searchPreview);
                        bounds.push(point);
                    }
                });
                if (bounds.length) {
                    proposalMap.invalidateSize();
                    proposalMap.fitBounds(bounds, { padding: [35, 35], maxZoom: 16 });
                }
            }
            resultsElement.hidden = false;
        };

        const searchAddress = async (address) => {
            if (searchButton.disabled) return;
            if (!proposalMap) {
                mapStatusText.textContent = 'The map has not loaded. Check your connection and reload the page before searching.';
                return;
            }
            if (!address) {
                mapStatusText.textContent = 'Enter a complete address before searching.';
                addressInput?.focus();
                return;
            }

            searchButton.disabled = true;
            searchButton.classList.add('is-loading');
            mapStatusText.textContent = 'Searching the free OpenStreetMap address service…';
            resultsElement.hidden = true;
            searchPreview?.clearLayers();

            try {
                const url = new URL(mapElement.dataset.searchUrl, window.location.origin);
                url.searchParams.set('q', address);
                const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.message || 'Address search failed.');
                renderSearchResults(payload.results || [], payload.suggestions || []);
                mapStatusText.textContent = payload.results?.length
                    ? 'Matches are previewed on the map. Click a marker or a result below to select the location to save.'
                    : 'No match found. Try a simpler search below or place the pin manually.';
            } catch (error) {
                mapStatusText.textContent = error.message || 'Address search failed. Select the location manually or try again later.';
            } finally {
                searchButton.disabled = false;
                searchButton.classList.remove('is-loading');
            }
        };
        searchButton?.addEventListener('click', () => searchAddress(addressInput?.value.trim()));
        document.getElementById('proposalUseCoordinates')?.addEventListener('click', () => {
            const input = document.getElementById('proposalVerifiedCoordinates');
            const match = input.value.trim().match(/^([+-]?\d+(?:\.\d+)?)\s*,\s*([+-]?\d+(?:\.\d+)?)$/);
            if (!match || Math.abs(Number(match[1])) > 90 || Math.abs(Number(match[2])) > 180) {
                mapStatusText.textContent = 'Enter latitude (-90 to 90), then longitude (-180 to 180), separated by a comma.';
                input.focus();
                return;
            }
            setLocation(Number(match[1]), Number(match[2]), 'manual', 18);
            resultsElement.hidden = true;
            mapStatusText.textContent = proposalMap
                ? 'Pin placed at the supplied coordinates. Verify the site, then save the proposal.'
                : 'Coordinates entered. The map is unavailable; verify the coordinates before saving.';
        });

        clearButton?.addEventListener('click', () => clearLocation());
        addressInput?.addEventListener('input', () => {
            searchPreview?.clearLayers();
            resultsElement.hidden = true;
            if (!latitudeInput?.value || !longitudeInput?.value) return;
            clearLocation('The address changed. Search again or place a new pin so the saved coordinates stay accurate.');
        });
        refreshCoordinateText();

        const checklist = document.getElementById('requiredFormChecklist');
        if (!checklist) return;

        const boxes = [...checklist.querySelectorAll('input[type="checkbox"]')];
        const submittedCount = document.getElementById('requiredSubmittedCount');
        const lackingCount = document.getElementById('requiredLackingCount');
        const progress = document.getElementById('requiredFormProgress');
        const statusText = document.getElementById('requiredStatusText');
        const statusInput = document.getElementById('document_status');
        const statusDisplay = document.getElementById('document_status_display');
        const proposalForm = document.getElementById('proposalRecordForm');
        const proposalStatus = document.getElementById('proposalStatus');

        const refreshChecklist = () => {
            const submitted = boxes.filter(box => box.checked).length;
            const lacking = boxes.length - submitted;
            const status = boxes.length && lacking === 0 ? 'Complete' : 'Incomplete';

            if (submittedCount) submittedCount.textContent = String(submitted);
            if (lackingCount) lackingCount.textContent = String(lacking);
            if (progress) progress.textContent = `${submitted}/${boxes.length} submitted`;
            if (statusText) statusText.textContent = status;
            if (statusInput) statusInput.value = status;
            if (statusDisplay) statusDisplay.value = status;

            boxes.forEach(box => {
                const item = box.closest('.required-form-item');
                const state = item?.querySelector('.required-form-state');
                const special = box.dataset.specialStatus || '';
                const unchanged = (box.dataset.originalChecked === '1') === box.checked;

                item?.classList.toggle('is-submitted', box.checked && !(special === 'Not Applicable' && unchanged));
                item?.classList.toggle('is-lacking', !box.checked && !(special === 'Pending' && unchanged));
                item?.classList.toggle('is-na', special === 'Not Applicable' && unchanged);
                item?.classList.toggle('is-pending', special === 'Pending' && unchanged);

                if (state) {
                    state.textContent = special === 'Not Applicable' && unchanged
                        ? 'Not applicable'
                        : (special === 'Pending' && unchanged ? 'Pending review' : (box.checked ? 'Submitted' : 'Lacking'));
                }
            });
        };

        proposalForm?.addEventListener('submit', () => {
            const lacking = boxes.filter(box => !box.checked && box.dataset.specialStatus !== 'Not Applicable').length;
            const approving = ['Approved', 'Funded'].includes(proposalStatus?.value);
            if (approving && lacking > 0) {
                proposalForm.dataset.confirmTitle = 'Approve with lacking documents?';
                proposalForm.dataset.confirmMessage = `There ${lacking === 1 ? 'is' : 'are'} still ${lacking} required document${lacking === 1 ? '' : 's'} lacking for this proposal. Are you sure you want to continue?`;
                proposalForm.dataset.confirmButton = 'Continue approval';
                proposalForm.dataset.confirmVariant = 'success';
            } else {
                delete proposalForm.dataset.confirmTitle; delete proposalForm.dataset.confirmMessage;
                delete proposalForm.dataset.confirmButton; delete proposalForm.dataset.confirmVariant;
            }
        });

        boxes.forEach(box => box.addEventListener('change', () => { delete box.dataset.specialStatus; refreshChecklist(); }));
        document.getElementById('markAllRequired')?.addEventListener('click', () => {
            boxes.forEach(box => { box.checked = true; delete box.dataset.specialStatus; });
            refreshChecklist();
        });
        document.getElementById('clearAllRequired')?.addEventListener('click', () => {
            boxes.forEach(box => { box.checked = false; delete box.dataset.specialStatus; });
            refreshChecklist();
        });

        refreshChecklist();
    });
</script>
@endpush
