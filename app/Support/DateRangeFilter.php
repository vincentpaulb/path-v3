<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;

/** Inclusive calendar dates; a custom range supersedes a quick year filter. */
class DateRangeFilter
{
    public readonly ?string $from;
    public readonly ?string $to;

    public function __construct(Request $request)
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => array_filter(['nullable', 'date_format:Y-m-d', $request->filled('date_from') ? 'after_or_equal:date_from' : null]),
        ]);
        $this->from = $data['date_from'] ?? null;
        $this->to = $data['date_to'] ?? null;
    }

    public function active(): bool { return $this->from !== null || $this->to !== null; }

    public function apply($query, string $column, ?int $year = null)
    {
        if ($this->active()) {
            if ($this->from) $query->whereDate($column, '>=', $this->from);
            if ($this->to) $query->whereDate($column, '<=', $this->to);
        } elseif ($year !== null) {
            $query->whereYear($column, $year);
        }
        return $query;
    }

    public function includes($date, ?int $year = null): bool
    {
        if (! $date) return false;
        $day = Carbon::parse($date)->format('Y-m-d');
        if (! $this->active()) return $year === null || (int) substr($day, 0, 4) === $year;
        return (! $this->from || $day >= $this->from) && (! $this->to || $day <= $this->to);
    }

    public function label(?int $year = null): string
    {
        return $this->active() ? ($this->from ?? 'Beginning').' to '.($this->to ?? 'present') : (string) $year;
    }
}
