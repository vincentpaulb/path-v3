<?php

namespace App\Http\Controllers;

use App\Models\{Proposal, RentalRecordImportBatch};
use App\Services\RentalRecordBulkImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class RentalRecordBulkImportController extends Controller
{
    public function __construct(private RentalRecordBulkImportService $importer) {}

    public function index(Request $request)
    {
        $preview = null;
        $token = $request->session()->get('rental_record_bulk_import_token');
        if ($token) {
            $payload = $this->loadPreviewPayload($token, $request->user()->id);
            if ($payload) {
                $preview = $payload['preview'] ?? null;
            } else {
                $request->session()->forget('rental_record_bulk_import_token');
                $token = null;
            }
        }

        $history = RentalRecordImportBatch::query()
            ->with('creator')
            ->latest('id')
            ->limit(12)
            ->get();

        return view('rental-records.bulk-import', [
            'preview' => $preview,
            'previewToken' => $token,
            'history' => $history,
        ]);
    }

    public function template(Request $request)
    {
        $templatePath = resource_path('templates/PATH-Rental-Records-Generic-Template.xlsx');
        if (! is_file($templatePath)) {
            abort(404, 'The Rental Monitoring Excel template is unavailable.');
        }

        return response()->download(
            $templatePath,
            'PATH-Rental-Monitoring-Current-Format-Template.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function preview(Request $request)
    {
        // Accept either one spreadsheet or one ZIP batch. Keep support for the
        // older import_zip field name so an already-open browser tab still works.
        $inputName = $request->hasFile('import_file') ? 'import_file' : ($request->hasFile('import_zip') ? 'import_zip' : 'import_file');
        $request->validate([
            $inputName => ['required', 'file', 'max:102400'],
        ]);

        $file = $request->file($inputName);
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['zip', 'xlsx', 'csv', 'txt'], true)) {
            return back()->with('error', 'Upload a ZIP batch or one Rental Monitoring XLSX/CSV file.');
        }

        $directory = storage_path('app/private/rental-imports');
        File::ensureDirectoryExists($directory);
        $token = (string) Str::uuid();
        $uploadedPath = $directory.DIRECTORY_SEPARATOR.$token.'.'.$extension;
        $file->move($directory, basename($uploadedPath));

        try {
            $preview = $extension === 'zip'
                ? $this->importer->parseZip($uploadedPath, $originalName, $request->user())
                : $this->importer->parseSpreadsheet($uploadedPath, $originalName, $request->user());
        } catch (\Throwable $e) {
            @unlink($uploadedPath);
            $kind = $extension === 'zip' ? 'ZIP batch' : 'spreadsheet';
            return back()->with('error', $e instanceof RuntimeException ? $e->getMessage() : 'PATH could not validate the '.$kind.': '.$e->getMessage());
        }

        @unlink($uploadedPath);

        file_put_contents($this->previewPath($token), json_encode([
            'user_id' => $request->user()->id,
            'created_at' => now()->toIso8601String(),
            'preview' => $preview,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $request->session()->put('rental_record_bulk_import_token', $token);

        $specialActions = (int) ($preview['ready_special_actions'] ?? 0);
        $message = "Validation completed: {$preview['importable_files']} file(s) contain {$preview['ready_rows']} rental row(s)"
            .($specialActions > 0 ? " and {$specialActions} special section(s)" : '')
            .' ready to import.';
        if ($preview['error_files'] > 0 || $preview['error_rows'] > 0) {
            return redirect()->route('rental-records.bulk-import.index')->with('warning', $message.' Review the files/rows marked with errors before importing.');
        }

        return redirect()->route('rental-records.bulk-import.index')->with('success', $message);
    }

    public function commit(Request $request)
    {
        $data = $request->validate(['preview_token' => ['required', 'string', 'max:80']]);
        $payload = $this->loadPreviewPayload($data['preview_token'], $request->user()->id);
        if (! $payload || empty($payload['preview'])) {
            return redirect()->route('rental-records.bulk-import.index')->with('error', 'The rental import preview expired. Upload the ZIP/XLSX/CSV again and revalidate it.');
        }

        $preview = $payload['preview'];
        $readyRows = (int) ($preview['ready_rows'] ?? 0);
        $readySpecial = (int) ($preview['ready_special_actions'] ?? 0);
        if (($readyRows + $readySpecial) < 1 || ($preview['importable_files'] ?? 0) < 1) {
            return redirect()->route('rental-records.bulk-import.index')->with('error', 'There are no importable rental rows, schedule adjustments, or OTP sections in this upload. Correct the file(s) and upload again.');
        }

        $result = $this->importer->importPreview($preview, $request->user());
        @unlink($this->previewPath($data['preview_token']));
        $request->session()->forget('rental_record_bulk_import_token');

        $batch = $result['batch'];
        $summary = "Rental import {$batch->batch_ref}: {$result['imported_rows']} payment row(s) imported, {$result['skipped_rows']} skipped, {$result['failed_rows']} failed/conflicted";
        if (($result['special_imported'] ?? 0) > 0 || ($result['special_skipped'] ?? 0) > 0 || ($result['special_failed'] ?? 0) > 0) {
            $summary .= "; {$result['special_imported']} special section(s) imported, {$result['special_skipped']} already recorded/skipped, {$result['special_failed']} failed";
        }
        $summary .= '.';

        return redirect()->route('rental-records.bulk-import.index')->with(
            ($result['failed_rows'] > 0 || ($result['special_failed'] ?? 0) > 0) ? 'warning' : 'success',
            $summary
        );
    }

    public function discard(Request $request)
    {
        $token = (string) $request->input('preview_token', $request->session()->get('rental_record_bulk_import_token', ''));
        if ($token !== '') {
            $payload = $this->loadPreviewPayload($token, $request->user()->id);
            if ($payload) @unlink($this->previewPath($token));
        }
        $request->session()->forget('rental_record_bulk_import_token');

        return redirect()->route('rental-records.bulk-import.index')->with('info', 'Rental import preview discarded. No rental records were changed.');
    }

    public function historyData(Request $request, RentalRecordImportBatch $batch)
    {
        $search = trim((string) $request->query('search', ''));
        $query = $batch->files()->with('proposal');

        (new \App\Support\DateRangeFilter($request))->apply($query, 'rental_record_import_files.created_at');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('original_filename', 'like', $like)
                    ->orWhere('project_title_snapshot', 'like', $like)
                    ->orWhere('proponent_snapshot', 'like', $like)
                    ->orWhere('status', 'like', $like)
                    ->orWhereHas('proposal', fn ($proposal) => $proposal
                        ->where('province', 'like', $like)
                        ->orWhere('sector', 'like', $like));
            });
        }

        $files = $query->orderBy('id')->paginate(8)->withQueryString();

        return view('rental-records.bulk-import-history-data', [
            'batch' => $batch->load('creator'),
            'files' => $files,
            'search' => $search,
        ]);
    }

    private function previewPath(string $token): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9-]/', '', $token);
        return storage_path('app/private/rental-imports/'.$safe.'.json');
    }

    private function loadPreviewPayload(string $token, int $userId): ?array
    {
        $path = $this->previewPath($token);
        if (! is_file($path)) return null;

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload) || (int) ($payload['user_id'] ?? 0) !== $userId) return null;

        $created = strtotime((string) ($payload['created_at'] ?? ''));
        if (! $created || $created < now()->subHours(4)->timestamp) {
            @unlink($path);
            return null;
        }

        return $payload;
    }
}
