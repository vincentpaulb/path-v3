@extends('layouts.app')
@section('title', 'Rental Records · '.$proposal->project_title)
@section('page-title', 'Rental Records')
@section('top-back')
    <a class="back topbar-back" href="{{ route('proposals.show', $proposal) }}">← Back to proposal</a>
@endsection

@section('top-actions')
    @if($schedule['ready'])
        <a class="btn" href="{{ route('rental-records.export', $proposal) }}" title="Export saved rental records"><i class="fa-solid fa-file-excel" aria-hidden="true"></i> Export Excel</a>
    @endif
    @if(auth()->user()->hasPermission('rental_records.bulk_import'))
        <a class="btn" href="{{ route('rental-records.bulk-import.template') }}"><i class="fa-solid fa-file-excel"></i> Download Rental Excel Template</a>
        <a class="btn" href="{{ route('rental-records.bulk-import.index') }}"><i class="fa-solid fa-file-import"></i> Bulk Upload</a>
    @endif
@endsection

@section('content')
@php
    $canManage = auth()->user()->hasPermission('rental_records.manage');
    $formatMoney = fn ($value) => '₱'.number_format((float) $value, 2);
    $hasSavedValue = fn ($value) => $value !== null && (! is_string($value) || trim($value) !== '');
@endphp


<div class="rental-page-head">
    <div>
        <h1>Rental Records</h1>
        <p>{{ $proposal->project_title }} · {{ $proposal->proponent }}</p>
    </div>
    <div class="rental-page-head-meta">
        <span class="sector-badge {{ strtolower($proposal->sector) }}">{{ $proposal->sector }}</span>
        <span>{{ $proposal->province }}</span>
    </div>
</div>

@if(! $schedule['ready'])
    <section class="panel rental-setup-panel">
        <div class="rental-setup-icon"><i class="fa-regular fa-calendar"></i></div>
        <div>
            <span class="eyebrow">SCHEDULE NOT READY</span>
            <h2>Funding information is required</h2>
            <p>PATH generates the 36-month rental schedule automatically from the Proposal Amount and Funding Date. The first rental month begins one year after the Funding Date.</p>
            <div class="rental-setup-missing">
                <span class="{{ $proposal->amount > 0 ? 'ok' : 'missing' }}">Proposal Amount: {{ $proposal->amount > 0 ? $formatMoney($proposal->amount) : 'Required' }}</span>
                <span class="{{ $proposal->date_funded ? 'ok' : 'missing' }}">Funding Date: {{ $proposal->date_funded?->format('F d, Y') ?: 'Required' }}</span>
            </div>
            @if(auth()->user()->hasPermission('proposals.edit'))
                <a class="btn primary" href="{{ route('proposals.edit', $proposal) }}">Update proposal funding information</a>
            @endif
        </div>
    </section>
@else
    @if($schedule['locked_warning'])
        <div class="rental-warning">
            <i class="fa-solid fa-lock"></i>
            <div>
                <b>Existing payment schedule preserved</b>
                <span>The Proposal Amount or Funding Date has changed after rental activity was recorded. PATH preserved the historical 36-month schedule to protect payment and restructuring records.</span>
            </div>
        </div>
    @endif

    <section class="rental-summary-grid">
        <article class="rental-summary-card">
            <small>Proposal amount</small>
            <b>{{ $formatMoney($metrics['project_amount']) }}</b>
            <span>Original outstanding balance</span>
        </article>
        <article class="rental-summary-card">
            <small>Funding date</small>
            <b>{{ $proposal->date_funded?->format('M d, Y') }}</b>
            <span>First payment: {{ $metrics['first_payment_month']?->format('M Y') }}</span>
        </article>
        <article class="rental-summary-card">
            <small>Current amount due</small>
            <b>{{ $formatMoney($metrics['current_monthly_due']) }}</b>
            <span>{{ $metrics['remaining_payment_count'] }} remaining payment{{ $metrics['remaining_payment_count'] === 1 ? '' : 's' }}</span>
        </article>
        <article class="rental-summary-card money">
            <small>Total refund</small>
            <b id="rentalTotalPaid">{{ $formatMoney($metrics['total_paid']) }}</b>
            <span>Actual rental payments received</span>
        </article>
        <article class="rental-summary-card balance">
            <small>Outstanding balance</small>
            <b id="rentalOutstanding">{{ $formatMoney($metrics['outstanding_balance']) }}</b>
            <span>Less payments and unexpended balance</span>
        </article>
        <article class="rental-summary-card rate">
            <small>Refund rate</small>
            <b id="rentalRefundRate">{{ number_format($metrics['refund_rate'], 2) }}%</b>
            <span>Total refund ÷ proposal amount</span>
        </article>
    </section>

    <section class="panel rental-records-panel notification-target" id="rental-records">
        <div class="panel-head rental-records-head">
            <div>
                <span class="eyebrow">PAYMENT SCHEDULE</span>
                <h2>Rental / refund monitoring</h2>
                <p>Adjust monthly dues for a date range, pause payments, or deduct an unexpended balance and recalculate the term.</p>
            </div>
            <div class="rental-head-actions">
                @if($canManage && $eligibleAdjustmentStarts->isNotEmpty())
                    <button class="btn rental-adjust-btn" type="button" data-open-rental-adjustment>
                        <i class="fa-solid fa-arrows-rotate"></i> Restructure / Revise Schedule
                    </button>
                @endif
                @if($canManage && $eligibleAdjustmentStarts->isNotEmpty())
                    <button class="btn" type="button" data-open-unexpended>Input Unexpended Balance</button>
                @endif
                @if($canManage)
                    <form method="POST" action="{{ route('rental-records.otp', $proposal) }}" class="rental-otp-toggle-form">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="enabled" value="{{ $proposal->rental_otp_enabled ? 0 : 1 }}">
                        <button class="btn rental-otp-toggle-btn {{ $proposal->rental_otp_enabled ? 'enabled' : '' }}" type="submit">
                            @if($proposal->rental_otp_enabled)
                                <i class="fa-solid fa-eye-slash"></i> Hide OTP
                            @else
                                <i class="fa-solid fa-plus"></i> Add OTP
                            @endif
                        </button>
                    </form>
                @endif
                @if($canManage)
                    <span class="rental-edit-note"><i class="fa-solid fa-lock"></i> Saved values are protected</span>
                @else
                    <span class="rental-edit-note readonly"><i class="fa-solid fa-eye"></i> Read only</span>
                @endif
            </div>
        </div>

        @if($adjustments->isNotEmpty())
            <div class="rental-adjustment-history">
                <div class="rental-adjustment-history-head">
                    <div>
                        <span class="eyebrow">SCHEDULE ADJUSTMENTS</span>
                        <b>{{ $adjustments->count() }} recorded {{ $adjustments->count() === 1 ? 'adjustment' : 'adjustments' }}</b>
                    </div>
                    <span>Original term: 36 months · a revision may change the remaining term</span>
                </div>
                <div class="rental-adjustment-list">
                    @foreach($adjustments as $adjustment)
                        <article class="rental-adjustment-item">
                            <div class="rental-adjustment-type">{{ $adjustment->adjustment_type }}</div>
                            <div>
                                <b>{{ $adjustment->effective_month?->format('M Y') }}
                                    @if($adjustment->mode !== 'credit')
                                        &ndash; {{ ($adjustment->mode === 'range' ? $adjustment->resume_month?->copy()->subMonth() : $adjustment->resume_month)?->format('M Y') }}
                                    @endif
                                </b>
                                @if($adjustment->mode === 'credit')
                                    <span>Deduction: <b>{{ $formatMoney($adjustment->unexpended_amount) }}</b> &middot; {{ $adjustment->repayment_option === '36_months' ? 'Recalculate over 36 months' : 'Keep regular monthly due; shorten term' }}</span>
                                @elseif($adjustment->mode === 'range')
                                    <span>Monthly due: <b>{{ $formatMoney($adjustment->revised_amount_due) }}</b> during this range; regular monthly due resumes afterward.</span>
                                @else
                                    <span>{{ $adjustment->suspension_months }} months deferred; payments resume {{ $adjustment->resume_month?->format('M Y') }}.</span>
                                    @if($adjustment->revised_amount_due !== null)<span>New regular monthly due: {{ $formatMoney($adjustment->revised_amount_due) }}</span>@endif
                                @endif
                                <span>{{ $adjustment->revised_remaining_installments }} scheduled months &middot; Final month: {{ $adjustment->revised_final_payment_month?->format('M Y') ?? 'Fully covered' }}</span>
                                @if($adjustment->remarks)
                                    <small>{{ $adjustment->remarks }}</small>
                                @endif
                            </div>
                            <div class="rental-adjustment-balance">
                                <small>Balance before adjustment</small>
                                <b>{{ $formatMoney($adjustment->outstanding_balance) }}</b>
                            </div>
                            @if($canManage)
                                @include('rental-records.adjustment-actions')
                            @endif
                        </article>
                    @endforeach
                </div>
            </div>
        @endif

        @if($canManage)
            <form method="POST" action="{{ route('rental-records.update', $proposal) }}" id="rentalRecordsForm">
                @csrf
                @method('PUT')
        @endif

        <div class="rental-table-wrap">
            <table class="rental-table">
                <thead>
                    <tr>
                        <th class="installment-col">#</th>
                        <th>Month</th>
                        <th class="money-col">Amount Due</th>
                        <th class="money-col">Rental Payment</th>
                        <th class="money-col">Outstanding Balance</th>
                        <th class="money-col">Accumulated Penalty</th>
                        <th>OR Number</th>
                        <th>OR Date</th>
                        <th class="money-col">Amount in OR</th>
                        <th class="remarks-col">Remarks</th>
                        @if($canManage)
                            <th class="rental-action-col">Action</th>
                        @endif
                    </tr>
                </thead>
                <tbody id="rentalScheduleBody">
                    @foreach($timeline as $item)
                        @if($item['kind'] === 'credit')
                            <tr class="rental-paused-row" data-timeline-kind="credit" data-credit="{{ $item['adjustment']->unexpended_amount }}">
                                <td>&mdash;</td><td>{{ $item['date']->format('M-y') }}</td>
                                <td colspan="2">Unexpended balance deduction: <b>{{ $formatMoney($item['adjustment']->unexpended_amount) }}</b></td>
                                <td class="money-cell" data-outstanding>{{ $formatMoney($item['outstanding']) }}</td>
                                <td class="money-cell">&mdash;</td>
                                <td colspan="{{ $canManage ? 5 : 4 }}">{{ $item['adjustment']->remarks }}</td>
                            </tr>
                        @elseif($item['kind'] === 'pause')
                            @php
                                $adjustment = $item['adjustment'];
                                $adjustmentLabel = match($adjustment->adjustment_type) {
                                    'Reconstruction' => 'Reconstructed',
                                    'Revision' => 'Revised',
                                    'Moratorium' => 'Moratorium',
                                    default => $adjustment->adjustment_type,
                                };
                            @endphp
                            <tr class="rental-paused-row" data-timeline-kind="pause">
                                <td class="installment-col">—</td>
                                <td class="month-cell"><b>{{ $item['date']->format('M-y') }}</b></td>
                                <td class="money-cell amount-due">—</td>
                                @if($item['is_first'])
                                    <td class="rental-adjustment-span" rowspan="{{ $adjustment->suspension_months }}">
                                        <div class="rental-adjustment-span-content">
                                            <b>{{ $adjustmentLabel }}{{ $adjustment->approved_date ? ' '.$adjustment->approved_date->format('M d, Y') : '' }}</b>
                                            <span>{{ $adjustment->suspension_months }} month{{ $adjustment->suspension_months === 1 ? '' : 's' }} deferred</span>
                                            <span>Payments resume {{ $adjustment->resume_month?->format('M Y') }}</span>
                                            @if($adjustment->revised_amount_due !== null)
                                                <span class="revised-due">New Amount Due {{ $formatMoney($adjustment->revised_amount_due) }}</span>
                                                <span>{{ $adjustment->revised_remaining_installments }} remaining payment{{ $adjustment->revised_remaining_installments === 1 ? '' : 's' }} · through {{ $adjustment->revised_final_payment_month?->format('M Y') }}</span>
                                            @endif
                                            @if($adjustment->remarks)
                                                <small>{{ $adjustment->remarks }}</small>
                                            @endif
                                        </div>
                                    </td>
                                @endif
                                <td class="money-cell outstanding-cell" data-outstanding>{{ $formatMoney($item['outstanding']) }}</td>
                                <td class="money-cell">&mdash;</td>
                                <td>—</td>
                                <td>—</td>
                                <td class="money-cell">—</td>
                                <td class="rental-pause-remarks">
                                    @if($item['is_first'])
                                        <span>Deferred schedule period</span>
                                    @else
                                        <span>—</span>
                                    @endif
                                </td>
                                @if($canManage)<td class="rental-action-cell">—</td>@endif
                            </tr>
                        @else
                            @php
                                $record = $item['record'];
                                $payment = old("records.{$record->id}.rental_payment", $record->rental_payment);
                                $orNumber = old("records.{$record->id}.or_number", $record->or_number);
                                $orDate = old("records.{$record->id}.or_date", $record->or_date?->format('Y-m-d'));
                                $amountInOr = old("records.{$record->id}.amount_in_or", $record->amount_in_or);
                                $remarks = old("records.{$record->id}.remarks", $record->remarks);
                                $forceEditOld = old("records.{$record->id}.force_edit") == '1';
                                $saved = [
                                    'rental_payment' => $hasSavedValue($record->rental_payment),
                                    'or_number' => $hasSavedValue($record->or_number),
                                    'or_date' => $hasSavedValue($record->or_date),
                                    'amount_in_or' => $hasSavedValue($record->amount_in_or),
                                    'remarks' => $hasSavedValue($record->remarks),
                                ];
                                $rowHasSavedData = in_array(true, $saved, true);
                            @endphp
                            <tr data-record-id="{{ $record->id }}" data-timeline-kind="record" @class(['rental-row-locked' => $rowHasSavedData && ! $forceEditOld, 'force-editing' => $forceEditOld])>
                                <td class="installment-col">{{ $record->installment_no }}</td>
                                <td class="month-cell"><b>{{ $record->due_month?->format('M-y') }}</b></td>
                                <td class="money-cell amount-due">{{ $formatMoney($record->amount_due) }}</td>
                                <td class="money-cell payment-cell">
                                    @if($canManage)
                                        <div class="money-input-wrap">
                                            <span>₱</span>
                                            <input type="number" min="0" step="0.01"
                                                   name="records[{{ $record->id }}][rental_payment]"
                                                   value="{{ $payment }}"
                                                   data-rental-payment
                                                   @if($saved['rental_payment']) data-saved-field @endif
                                                   @disabled($saved['rental_payment'] && ! $forceEditOld)
                                                   placeholder="0.00">
                                        </div>
                                    @else
                                        {{ $record->rental_payment !== null ? $formatMoney($record->rental_payment) : '—' }}
                                    @endif
                                </td>
                                <td class="money-cell outstanding-cell" data-outstanding>{{ $formatMoney($metrics['row_outstanding'][$record->id] ?? $metrics['outstanding_balance']) }}</td>
                                <td class="money-cell" title="Accumulated through this month; this month's charge: {{ $formatMoney($metrics['penalties'][$record->id]['monthly_charge']) }}">{{ $formatMoney($metrics['penalties'][$record->id]['amount']) }}</td>
                                <td>
                                    @if($canManage)
                                        <input class="rental-text-input" name="records[{{ $record->id }}][or_number]" value="{{ $orNumber }}"
                                               @if($saved['or_number']) data-saved-field @endif
                                               @disabled($saved['or_number'] && ! $forceEditOld)
                                               placeholder="OR number">
                                    @else
                                        {{ $record->or_number ?: '—' }}
                                    @endif
                                </td>
                                <td>
                                    @if($canManage)
                                        <input class="rental-date-input" type="date" name="records[{{ $record->id }}][or_date]" value="{{ $orDate }}"
                                               @if($saved['or_date']) data-saved-field @endif
                                               @disabled($saved['or_date'] && ! $forceEditOld)>
                                    @else
                                        {{ $record->or_date?->format('m.d.y') ?: '—' }}
                                    @endif
                                </td>
                                <td class="money-cell">
                                    @if($canManage)
                                        <div class="money-input-wrap">
                                            <span>₱</span>
                                            <input type="number" min="0" step="0.01"
                                                   name="records[{{ $record->id }}][amount_in_or]"
                                                   value="{{ $amountInOr }}"
                                                   data-amount-in-or
                                                   @if($saved['amount_in_or']) data-saved-field @endif
                                                   @disabled($saved['amount_in_or'] && ! $forceEditOld)
                                                   placeholder="0.00">
                                        </div>
                                    @else
                                        {{ $record->amount_in_or !== null ? $formatMoney($record->amount_in_or) : '—' }}
                                    @endif
                                </td>
                                <td>
                                    @if($canManage)
                                        <input class="rental-remarks-input" name="records[{{ $record->id }}][remarks]" value="{{ $remarks }}"
                                               @if($saved['remarks']) data-saved-field @endif
                                               @disabled($saved['remarks'] && ! $forceEditOld)
                                               placeholder="Optional remarks">
                                    @else
                                        {{ $record->remarks ?: '—' }}
                                    @endif
                                </td>
                                @if($canManage)
                                    <td class="rental-action-cell">
                                        <input type="hidden" name="records[{{ $record->id }}][force_edit]" value="{{ $forceEditOld ? '1' : '0' }}" data-force-edit-flag>
                                        @if($rowHasSavedData)
                                            <button type="button" class="rental-force-edit-btn {{ $forceEditOld ? 'active' : '' }}" data-force-edit-row @disabled($forceEditOld)>
                                                <i class="fa-solid {{ $forceEditOld ? 'fa-pen' : 'fa-lock-open' }}"></i>
                                                <span>{{ $forceEditOld ? 'Editing enabled' : 'Force Edit' }}</span>
                                            </button>
                                        @else
                                            <span class="rental-new-row-label">New</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endif
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2"><b>TOTAL</b></td>
                        <td class="money-cell"><b>{{ $formatMoney($records->sum('amount_due')) }}</b></td>
                        <td class="money-cell"><b id="rentalTableTotalPaid">{{ $formatMoney($metrics['total_paid']) }}</b></td>
                        <td class="money-cell"><b id="rentalTableOutstanding">{{ $formatMoney($metrics['outstanding_balance']) }}</b></td>
                        <td class="money-cell"><b>{{ $formatMoney($metrics['total_penalty']) }}</b></td>
                        <td colspan="{{ $canManage ? 5 : 4 }}"></td>
                    </tr>
                    @if($proposal->rental_otp_enabled)
                    @php($otpAmount = round(((float) $metrics['project_amount']) * 0.005, 2))
                    <tr class="rental-otp-row">
                        <td class="installment-col">—</td>
                        <td class="rental-otp-label">
                            <b>OTP</b>
                            <small>Option to Purchase</small>
                        </td>
                        <td class="money-cell rental-otp-amount">
                            <b>{{ $formatMoney($otpAmount) }}</b>
                            <small>0.5% of proposal amount</small>
                        </td>
                        <td colspan="{{ $canManage ? 8 : 7 }}" class="rental-otp-note">
                            Optional only — this amount is not included in the rental obligation, Total Refund, Outstanding Balance, or Refund Rate.
                        </td>
                    </tr>
                    @endif
                </tfoot>
            </table>
        </div>

        @if($canManage)
            <div class="rental-form-actions">
                <p><i class="fa-solid fa-circle-info"></i> Saved values are locked. Use <b>Force Edit</b> on a row to change an existing saved value. Adjustments rebuild only unsaved periods. Temporary dues return to the regular amount after the selected end month. Unexpended balance deductions reduce the outstanding balance and are tracked separately from payments.</p>
                <button class="btn primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save rental records</button>
            </div>
            </form>
        @endif
    </section>

    @if($canManage)
        @include('rental-records.adjustment-dialog')
    @endif
@endif
@endsection

@if($schedule['ready'] && $canManage)
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const projectAmount = Number(@json((float) $metrics['project_amount']));
    const peso = value => new Intl.NumberFormat('en-PH', {style:'currency', currency:'PHP', minimumFractionDigits:2}).format(value || 0);

    function recalculate() {
        let running = projectAmount;
        let totalPaid = 0;
        const timelineRows = [...document.querySelectorAll('#rentalScheduleBody tr[data-timeline-kind]')];

        timelineRows.forEach(row => {
            if (row.dataset.timelineKind === 'credit') running -= Number(row.dataset.credit || 0);
            if (row.dataset.timelineKind === 'record') {
                const input = row.querySelector('[data-rental-payment]');
                const payment = Math.max(0, Number(input?.value || 0));
                totalPaid += payment;
                running -= payment;
            }

            const outstanding = row.querySelector('[data-outstanding]');
            if (outstanding) outstanding.textContent = peso(running);
        });

        const rate = projectAmount > 0 ? (totalPaid / projectAmount) * 100 : 0;
        ['rentalTotalPaid','rentalTableTotalPaid'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.textContent = peso(totalPaid);
        });
        ['rentalOutstanding','rentalTableOutstanding'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.textContent = peso(running);
        });
        const rateEl = document.getElementById('rentalRefundRate');
        if (rateEl) rateEl.textContent = `${rate.toFixed(2)}%`;
    }

    document.querySelectorAll('[data-rental-payment]').forEach(input => {
        input.addEventListener('input', () => {
            const row = input.closest('tr');
            const amountInOr = row?.querySelector('[data-amount-in-or]');
            if (amountInOr && !amountInOr.disabled && !amountInOr.value) amountInOr.value = input.value;
            recalculate();
        });
    });

    document.querySelectorAll('[data-force-edit-row]').forEach(button => {
        button.addEventListener('click', () => {
            const row = button.closest('tr');
            if (!row) return;

            row.querySelectorAll('[data-saved-field]').forEach(input => input.disabled = false);
            const flag = row.querySelector('[data-force-edit-flag]');
            if (flag) flag.value = '1';

            row.classList.remove('rental-row-locked');
            row.classList.add('force-editing');
            button.classList.add('active');
            button.disabled = true;
            const icon = button.querySelector('i');
            const label = button.querySelector('span');
            if (icon) icon.className = 'fa-solid fa-pen';
            if (label) label.textContent = 'Editing enabled';
        });
    });

    recalculate();
});
</script>
@endpush
@endif
