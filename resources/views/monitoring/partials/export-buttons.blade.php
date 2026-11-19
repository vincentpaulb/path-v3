<div class="monitoring-export-actions" aria-label="Export monitoring report">
    <span class="monitoring-export-label"><i class="fa-solid fa-file-export" aria-hidden="true"></i> Export</span>
    <a class="monitoring-export-btn xlsx" href="{{ route($routeName, array_merge(request()->query(), ['format' => 'xlsx'])) }}">
        <i class="fa-solid fa-file-excel" aria-hidden="true"></i> Excel
    </a>
    <a class="monitoring-export-btn pdf" href="{{ route($routeName, array_merge(request()->query(), ['format' => 'pdf'])) }}">
        <i class="fa-solid fa-file-pdf" aria-hidden="true"></i> PDF
    </a>
</div>
