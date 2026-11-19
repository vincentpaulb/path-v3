<div class="date-range-filter" aria-label="{{ $dateBasis ?? 'Record date' }} range">
    <label><span>Date from</span><input type="date" name="date_from" value="{{ request('date_from') }}" aria-label="Date from ({{ $dateBasis ?? 'Record date' }})" title="{{ $dateBasis ?? 'Record date' }}"></label>
    <label><span>Date to</span><input type="date" name="date_to" value="{{ request('date_to') }}" min="{{ request('date_from') }}" aria-label="Date to ({{ $dateBasis ?? 'Record date' }})" title="{{ $dateBasis ?? 'Record date' }}"></label>
    @if(request()->filled('date_from') || request()->filled('date_to'))
        <a href="{{ request()->fullUrlWithoutQuery(array_merge(['date_from', 'date_to', 'page'], array_values(preg_grep('/^year_page_/', array_keys(request()->query()))))) }}">Clear dates</a>
    @endif
</div>
