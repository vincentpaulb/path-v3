<?php

use App\Http\Controllers\{
    AnalyticsController,
    ArchiveController,
    AuthController,
    ComplianceController,
    DashboardController,
    EvaluatorController,
    EvaluatorPortalController,
    FundedProposalController,
    GeocodingController,
    InteractionController,
    IndustrySectorController,
    MonitoringController,
    MonitoringExportController,
    NotificationController,
    LiquidationMonitoringController,
    ProfileController,
    ProposalController,
    ProposalLiquidationController,
    ProposalBulkImportController,
    ProvinceController,
    RequiredDocumentController,
    RentalRecordController,
    RentalRecordBulkImportController,
    RoleController,
    UserController
};
use Illuminate\Support\Facades\Route;

Route::view('/privacy-policy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms-and-conditions', 'legal.terms')->name('legal.terms');
Route::view('/cookie-policy', 'legal.cookies')->name('legal.cookies');
Route::view('/accessibility', 'legal.accessibility')->name('legal.accessibility');
Route::get('/', \App\Http\Controllers\PublicProposalController::class)
    ->middleware('throttle:60,1')->name('public.proposals');
Route::post('/public/projects/{key}/report', [\App\Http\Controllers\PublicDirectoryContentController::class, 'report'])
    ->middleware('throttle:5,10')->name('public.projects.report');
Route::get('/public/projects/{key}/photo', [\App\Http\Controllers\PublicDirectoryContentController::class, 'photo'])
    ->middleware('throttle:120,1')->name('public.projects.photo');
Route::middleware(['auth', 'role:Admin'])->group(function () {
    Route::get('/public-directory/review', [\App\Http\Controllers\PublicDirectoryContentController::class, 'index'])->name('public-directory.review');
    Route::get('/public-directory/profiles/{proposal}/photo', [\App\Http\Controllers\PublicDirectoryContentController::class, 'preview'])->name('public-directory.preview');
    Route::put('/public-directory/profiles/{proposal}', [\App\Http\Controllers\PublicDirectoryContentController::class, 'save'])->name('public-directory.save');
    Route::patch('/public-directory/reports/{report}', [\App\Http\Controllers\PublicDirectoryContentController::class, 'review'])->name('public-directory.report-review');
});
Route::get('/public/proposals', function (\Illuminate\Http\Request $request) {
    return redirect()->to(route('public.proposals').($request->getQueryString() ? '?'.$request->getQueryString() : ''), 301);
});

Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => redirect()->route('public.proposals', ['login' => 1]))->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
    Route::post('/forgot-password', [\App\Http\Controllers\PasswordResetController::class, 'sendLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [\App\Http\Controllers\PasswordResetController::class, 'show'])->name('password.reset');
    Route::post('/reset-password', [\App\Http\Controllers\PasswordResetController::class, 'reset'])->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/compliance', [ComplianceController::class, 'index'])->name('compliance.index');
    Route::get('/compliance/risk-register', [ComplianceController::class, 'risks'])->middleware('role:Admin')->name('compliance.risks');
    Route::middleware('role:Admin')->group(function () {
        Route::put('/compliance/requirements/{requirement}', [ComplianceController::class, 'update'])->name('compliance.requirements.update');
        Route::get('/compliance/evidence/{evidence}', [ComplianceController::class, 'download'])->name('compliance.evidence.download');
    });

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/status', [NotificationController::class, 'status'])->name('notifications.status');
    Route::get('/notifications/push', [\App\Http\Controllers\WebPushController::class, 'status'])->name('notifications.push.status');
    Route::post('/notifications/push', [\App\Http\Controllers\WebPushController::class, 'store'])->middleware('throttle:12,1')->name('notifications.push.store');
    Route::delete('/notifications/push', [\App\Http\Controllers\WebPushController::class, 'destroy'])->name('notifications.push.destroy');
    Route::post('/notifications/push/test', [\App\Http\Controllers\WebPushController::class, 'test'])->middleware('throttle:3,1')->name('notifications.push.test');
    Route::get('/notifications/push/test/{delivery}', [\App\Http\Controllers\WebPushController::class, 'testStatus'])->middleware('throttle:90,1')->name('notifications.push.test-status');
    Route::post('/notifications/stream-ticket', [NotificationController::class, 'streamTicket'])->middleware('throttle:30,1')->name('notifications.stream-ticket');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');

    Route::get('/dashboard', DashboardController::class)->middleware('permission:dashboard.view')->name('dashboard');

    Route::get('/proposals', [ProposalController::class, 'index'])->middleware('permission:proposals.view')->name('proposals.index');
    Route::get('/geocoding/search', [GeocodingController::class, 'search'])->middleware('throttle:30,1')->name('geocoding.search');
    Route::get('/proposals/create', [ProposalController::class, 'create'])->middleware('permission:proposals.create')->name('proposals.create');
    Route::get('/proposals/bulk-import', [ProposalBulkImportController::class, 'index'])->middleware('permission:proposals.bulk_import')->name('proposals.bulk-import.index');
    Route::get('/proposals/bulk-import/template', [ProposalBulkImportController::class, 'template'])->middleware('permission:proposals.bulk_import')->name('proposals.bulk-import.template');
    Route::get('/proposals/bulk-import/history/{batch}', [ProposalBulkImportController::class, 'historyData'])->middleware('permission:proposals.bulk_import')->name('proposals.bulk-import.history-data');
    Route::post('/proposals/bulk-import/preview', [ProposalBulkImportController::class, 'preview'])->middleware('permission:proposals.bulk_import')->name('proposals.bulk-import.preview');
    Route::post('/proposals/bulk-import/commit', [ProposalBulkImportController::class, 'commit'])->middleware('permission:proposals.bulk_import')->name('proposals.bulk-import.commit');
    Route::post('/proposals/bulk-import/discard', [ProposalBulkImportController::class, 'discard'])->middleware('permission:proposals.bulk_import')->name('proposals.bulk-import.discard');
    Route::post('/proposals', [ProposalController::class, 'store'])->middleware('permission:proposals.create')->name('proposals.store');
    Route::get('/proposals/{proposal}/edit', [ProposalController::class, 'edit'])->middleware('permission:proposals.edit')->name('proposals.edit');
    Route::put('/proposals/{proposal}', [ProposalController::class, 'update'])->middleware('permission:proposals.edit')->name('proposals.update');
    Route::patch('/proposals/{proposal}/summary', [ProposalController::class, 'updateSummary'])->middleware('permission:proposals.edit')->name('proposals.summary');
    Route::patch('/proposals/{proposal}/evaluators/{evaluator}/status', [ProposalController::class, 'updateEvaluatorStatus'])
        ->middleware('permission:proposals.manage_evaluator_status')
        ->name('proposals.evaluators.status');
    Route::get('/proposals/{proposal}/required-documents/checklist', [ProposalController::class, 'documentChecklist'])
        ->name('proposals.required-documents.checklist');
    // Proposal detail is also used as a read-only detail page from Funded Proposals.
    // The controller authorizes either proposals.view or funded.view and still applies data scope.
    Route::get('/proposals/{proposal}', [ProposalController::class, 'show'])->name('proposals.show');
    Route::delete('/proposals/{proposal}', [ProposalController::class, 'destroy'])->middleware('permission:proposals.archive')->name('proposals.destroy');

    Route::get('/proposals/{proposal}/acquisition-liquidation', [ProposalLiquidationController::class, 'index'])
        ->middleware('permission:liquidation.view')->name('proposals.liquidation.index');
    Route::get('/proposals/{proposal}/acquisition-liquidation/export', [ProposalLiquidationController::class, 'export'])
        ->middleware('permission:liquidation.view')->name('proposals.liquidation.export');
    Route::post('/proposals/{proposal}/acquisition-liquidation/budget-lines', [ProposalLiquidationController::class, 'storeBudgetLine'])
        ->middleware('permission:liquidation.manage')->name('proposals.liquidation.budget-lines.store');
    Route::put('/proposals/{proposal}/acquisition-liquidation/budget-lines/{budgetLine}', [ProposalLiquidationController::class, 'updateBudgetLine'])
        ->middleware('permission:liquidation.manage')->name('proposals.liquidation.budget-lines.update');
    Route::delete('/proposals/{proposal}/acquisition-liquidation/budget-lines/{budgetLine}', [ProposalLiquidationController::class, 'destroyBudgetLine'])
        ->middleware('permission:liquidation.manage')->name('proposals.liquidation.budget-lines.destroy');
    Route::post('/proposals/{proposal}/acquisition-liquidation/acquisitions', [ProposalLiquidationController::class, 'storeAcquisition'])
        ->middleware('permission:liquidation.manage')->name('proposals.liquidation.acquisitions.store');
    Route::put('/proposals/{proposal}/acquisition-liquidation/acquisitions/{acquisition}', [ProposalLiquidationController::class, 'updateAcquisition'])
        ->middleware('permission:liquidation.manage')->name('proposals.liquidation.acquisitions.update');
    Route::delete('/proposals/{proposal}/acquisition-liquidation/acquisitions/{acquisition}', [ProposalLiquidationController::class, 'destroyAcquisition'])
        ->middleware('permission:liquidation.manage')->name('proposals.liquidation.acquisitions.destroy');
    Route::post('/proposals/{proposal}/acquisition-liquidation/transactions', [ProposalLiquidationController::class, 'storeTransaction'])
        ->middleware('permission:liquidation.manage')->name('proposals.liquidation.transactions.store');
    Route::put('/proposals/{proposal}/acquisition-liquidation/transactions/{transaction}', [ProposalLiquidationController::class, 'updateTransaction'])
        ->middleware('permission:liquidation.manage')->name('proposals.liquidation.transactions.update');
    Route::delete('/proposals/{proposal}/acquisition-liquidation/transactions/{transaction}', [ProposalLiquidationController::class, 'destroyTransaction'])
        ->middleware('permission:liquidation.manage')->name('proposals.liquidation.transactions.destroy');
    Route::post('/proposals/{proposal}/acquisition-liquidation/documents', [ProposalLiquidationController::class, 'storeDocument'])
        ->middleware('permission:liquidation.manage')->name('proposals.liquidation.documents.store');
    Route::get('/proposals/{proposal}/acquisition-liquidation/documents/{document}/download', [ProposalLiquidationController::class, 'downloadDocument'])
        ->middleware('permission:liquidation.view')->name('proposals.liquidation.documents.download');
    Route::delete('/proposals/{proposal}/acquisition-liquidation/documents/{document}', [ProposalLiquidationController::class, 'destroyDocument'])
        ->middleware('permission:liquidation.manage')->name('proposals.liquidation.documents.destroy');
    Route::post('/proposals/{proposal}/acquisition-liquidation/findings', [ProposalLiquidationController::class, 'storeFinding'])
        ->middleware('permission:liquidation.review')->name('proposals.liquidation.findings.store');
    Route::patch('/proposals/{proposal}/acquisition-liquidation/findings/{finding}/resolve', [ProposalLiquidationController::class, 'resolveFinding'])
        ->middleware('permission:liquidation.review')->name('proposals.liquidation.findings.resolve');
    Route::patch('/proposals/{proposal}/acquisition-liquidation/workflow', [ProposalLiquidationController::class, 'workflow'])
        ->middleware('permission:liquidation.view')->name('proposals.liquidation.workflow');

    Route::get('/rental-records/bulk-import', [RentalRecordBulkImportController::class, 'index'])
        ->middleware('permission:rental_records.bulk_import')
        ->name('rental-records.bulk-import.index');
    Route::post('/rental-records/bulk-import/preview', [RentalRecordBulkImportController::class, 'preview'])
        ->middleware('permission:rental_records.bulk_import')
        ->name('rental-records.bulk-import.preview');
    Route::post('/rental-records/bulk-import/commit', [RentalRecordBulkImportController::class, 'commit'])
        ->middleware('permission:rental_records.bulk_import')
        ->name('rental-records.bulk-import.commit');
    Route::post('/rental-records/bulk-import/discard', [RentalRecordBulkImportController::class, 'discard'])
        ->middleware('permission:rental_records.bulk_import')
        ->name('rental-records.bulk-import.discard');
    Route::get('/rental-records/bulk-import/history/{batch}', [RentalRecordBulkImportController::class, 'historyData'])
        ->middleware('permission:rental_records.bulk_import')
        ->name('rental-records.bulk-import.history-data');
    Route::get('/rental-records/bulk-import/template', [RentalRecordBulkImportController::class, 'template'])
        ->middleware('permission:rental_records.bulk_import')
        ->name('rental-records.bulk-import.template');

    Route::get('/proposals/{proposal}/rental-records', [RentalRecordController::class, 'index'])
        ->middleware('permission:rental_records.view')
        ->name('rental-records.index');
    Route::get('/proposals/{proposal}/rental-records/export', [RentalRecordController::class, 'export'])
        ->middleware('permission:rental_records.view')->name('rental-records.export');
    Route::put('/proposals/{proposal}/rental-records', [RentalRecordController::class, 'update'])
        ->middleware('permission:rental_records.manage')
        ->name('rental-records.update');
    Route::post('/proposals/{proposal}/rental-records/restructure', [RentalRecordController::class, 'restructure'])
        ->middleware('permission:rental_records.manage')
        ->name('rental-records.restructure');
    Route::put('/proposals/{proposal}/rental-records/adjustments/{adjustment}', [RentalRecordController::class, 'updateAdjustment'])
        ->middleware('permission:rental_records.manage')->name('rental-records.adjustments.update');
    Route::delete('/proposals/{proposal}/rental-records/adjustments/{adjustment}', [RentalRecordController::class, 'destroyAdjustment'])
        ->middleware('permission:rental_records.manage')->name('rental-records.adjustments.destroy');
    Route::patch('/proposals/{proposal}/rental-records/otp', [RentalRecordController::class, 'toggleOtp'])
        ->middleware('permission:rental_records.manage')
        ->name('rental-records.otp');

    Route::get('/funded', [FundedProposalController::class, 'index'])->middleware('permission:funded.view')->name('funded.index');
    Route::patch('/funded/{proposal}/status', [FundedProposalController::class, 'updateStatus'])->middleware('permission:funded.update_status')->name('funded.status');

    Route::post('/proposals/{proposal}/missing-documents', [InteractionController::class, 'missing'])->middleware('permission:proposals.missing_evaluation')->name('missing.store');
    Route::post('/proposals/{proposal}/complete-documents', [InteractionController::class, 'completeDocuments'])->middleware('permission:proposals.missing_evaluation')->name('documents.complete');
    Route::post('/proposals/{proposal}/comments', [InteractionController::class, 'comment'])->middleware('permission:proposals.comment')->name('comments.store');
    Route::delete('/comments/{comment}', [InteractionController::class, 'destroyComment'])->middleware('permission:proposals.comment')->name('comments.destroy');
    Route::post('/comments/{comment}/replies', [InteractionController::class, 'reply'])->middleware('permission:proposals.comment')->name('replies.store');
    Route::delete('/replies/{reply}', [InteractionController::class, 'destroyReply'])->middleware('permission:proposals.comment')->name('replies.destroy');
    Route::post('/proposals/{proposal}/files', [InteractionController::class, 'upload'])->middleware('permission:proposals.upload_files')->name('files.store');
    Route::get('/files/{file}/download', [InteractionController::class, 'download'])->middleware('permission:proposals.download_files')->name('files.download');

    Route::get('/annual-monitoring', \App\Http\Controllers\AnnualMonitoringController::class)->middleware('permission:dashboard.view')->name('monitoring.annual');
    Route::get('/annual-monitoring/export', [\App\Http\Controllers\AnnualMonitoringController::class, 'export'])->middleware('permission:dashboard.view')->name('monitoring.annual.export');
    Route::get('/analytics', [AnalyticsController::class, 'index'])->middleware('permission:analytics.view')->name('analytics.index');

    Route::get('/monitoring/rtec', [MonitoringController::class, 'rtec'])
        ->middleware('permission:monitoring.rtec.view')
        ->name('monitoring.rtec');
    Route::get('/monitoring/rental', [MonitoringController::class, 'rental'])
        ->middleware('permission:monitoring.rental.view')
        ->name('monitoring.rental');
    Route::get('/monitoring/refund-rate', [MonitoringController::class, 'refundRate'])
        ->middleware('permission:monitoring.refund_rate.view')
        ->name('monitoring.refund-rate');
    Route::get('/monitoring/rtec/export/{format}', [MonitoringExportController::class, 'rtec'])
        ->whereIn('format', ['xlsx', 'pdf'])
        ->middleware('permission:monitoring.rtec.view')
        ->name('monitoring.rtec.export');
    Route::get('/monitoring/rental/export/{format}', [MonitoringExportController::class, 'rental'])
        ->whereIn('format', ['xlsx', 'pdf'])
        ->middleware('permission:monitoring.rental.view')
        ->name('monitoring.rental.export');
    Route::get('/monitoring/refund-rate/export/{format}', [MonitoringExportController::class, 'refundRate'])
        ->whereIn('format', ['xlsx', 'pdf'])
        ->middleware('permission:monitoring.refund_rate.view')
        ->name('monitoring.refund-rate.export');
    Route::get('/monitoring/liquidation/export/{format}', [MonitoringExportController::class, 'liquidation'])
        ->whereIn('format', ['xlsx', 'pdf'])
        ->middleware('permission:monitoring.liquidation.view')
        ->name('monitoring.liquidation.export');

    Route::get('/monitoring/liquidation', [LiquidationMonitoringController::class, 'index'])
        ->middleware('permission:monitoring.liquidation.view')
        ->name('monitoring.liquidation');
    Route::patch('/monitoring/liquidation/{proposal}', [LiquidationMonitoringController::class, 'update'])
        ->middleware('permission:monitoring.liquidation.manage')
        ->name('monitoring.liquidation.update');

    Route::get('/provinces', [ProvinceController::class, 'index'])->middleware('permission:provinces.view')->name('provinces.index');
    Route::post('/provinces', [ProvinceController::class, 'store'])->middleware('permission:provinces.create')->name('provinces.store');
    Route::put('/provinces/{province}', [ProvinceController::class, 'update'])->middleware('permission:provinces.edit')->name('provinces.update');
    Route::patch('/provinces/{province}/status', [ProvinceController::class, 'toggle'])->middleware('permission:provinces.toggle')->name('provinces.toggle');
    Route::delete('/provinces/{province}', [ProvinceController::class, 'destroy'])->middleware('permission:provinces.delete')->name('provinces.destroy');

    Route::get('/industry-sectors', [IndustrySectorController::class, 'index'])->middleware('permission:industry_sectors.view')->name('industry-sectors.index');
    Route::post('/industry-sectors', [IndustrySectorController::class, 'store'])->middleware('permission:industry_sectors.create')->name('industry-sectors.store');
    Route::put('/industry-sectors/{industrySector}', [IndustrySectorController::class, 'update'])->middleware('permission:industry_sectors.edit')->name('industry-sectors.update');
    Route::patch('/industry-sectors/{industrySector}/status', [IndustrySectorController::class, 'toggle'])->middleware('permission:industry_sectors.toggle')->name('industry-sectors.toggle');
    Route::delete('/industry-sectors/{industrySector}', [IndustrySectorController::class, 'destroy'])->middleware('permission:industry_sectors.delete')->name('industry-sectors.destroy');

    Route::get('/required-documents', [RequiredDocumentController::class, 'index'])->middleware('permission:required_documents.view')->name('required-documents.index');
    Route::post('/required-documents', [RequiredDocumentController::class, 'store'])->middleware('permission:required_documents.create')->name('required-documents.store');
    Route::put('/required-documents/{requiredDocument}', [RequiredDocumentController::class, 'update'])->middleware('permission:required_documents.edit')->name('required-documents.update');
    Route::delete('/required-documents/{requiredDocument}', [RequiredDocumentController::class, 'destroy'])->middleware('permission:required_documents.archive')->name('required-documents.destroy');

    Route::get('/archives/{section?}', [ArchiveController::class, 'index'])->middleware('permission:archives.view')->name('archives.index');
    Route::post('/archives/proposals/{id}/restore', [ArchiveController::class, 'restoreProposal'])->middleware('permission:proposals.restore')->name('archives.proposals.restore');
    Route::post('/archives/evaluators/{id}/restore', [ArchiveController::class, 'restoreEvaluator'])->middleware('permission:evaluators.restore')->name('archives.evaluators.restore');
    Route::post('/archives/users/{id}/restore', [ArchiveController::class, 'restoreUser'])->middleware('permission:users.restore')->name('archives.users.restore');
    Route::post('/archives/required-documents/{id}/restore', [ArchiveController::class, 'restoreRequiredDocument'])->middleware('permission:required_documents.restore')->name('archives.required-documents.restore');

    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view')->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create')->name('users.store');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:users.view')->name('users.show');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->middleware('permission:users.edit')->name('users.edit');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.edit')->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.archive')->name('users.destroy');

    Route::get('/evaluators', [EvaluatorController::class, 'index'])->middleware('permission:evaluators.view')->name('evaluators.index');
    Route::post('/evaluators', [EvaluatorController::class, 'store'])->middleware('permission:evaluators.create')->name('evaluators.store');
    Route::get('/evaluators/{evaluator}', [EvaluatorController::class, 'show'])->middleware('permission:evaluators.view')->name('evaluators.show');
    Route::get('/evaluators/{evaluator}/edit', [EvaluatorController::class, 'edit'])->middleware('permission:evaluators.edit')->name('evaluators.edit');
    Route::put('/evaluators/{evaluator}', [EvaluatorController::class, 'update'])->middleware('permission:evaluators.edit')->name('evaluators.update');
    Route::delete('/evaluators/{evaluator}', [EvaluatorController::class, 'destroy'])->middleware('permission:evaluators.archive')->name('evaluators.destroy');

    // Role & Permissions is intentionally Admin-only and cannot be delegated from
    // the permission matrix, preventing another role from granting itself access.
    Route::middleware('role:Admin')->group(function () {
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

        // Permanent deletion remains an Admin-only system safeguard regardless
        // of configurable role permissions.
        Route::delete('/archives/proposals/{id}/permanent', [ArchiveController::class, 'forceDeleteProposal'])->name('archives.proposals.force-delete');
        Route::delete('/archives/evaluators/{id}/permanent', [ArchiveController::class, 'forceDeleteEvaluator'])->name('archives.evaluators.force-delete');
        Route::delete('/archives/users/{id}/permanent', [ArchiveController::class, 'forceDeleteUser'])->name('archives.users.force-delete');
        Route::delete('/archives/required-documents/{id}/permanent', [ArchiveController::class, 'forceDeleteRequiredDocument'])->name('archives.required-documents.force-delete');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'password'])->name('profile.password');

    Route::patch('/proposals/{proposal}/moa-status', [ProposalController::class, 'updateMoaStatus'])->middleware('permission:proposals.workflow')->name('proposals.moa-status');
    Route::patch('/proposals/{proposal}/check-status', [ProposalController::class, 'updateCheckStatus'])->middleware('permission:proposals.workflow')->name('proposals.check-status');

    Route::get('/my-proposals', [EvaluatorPortalController::class, 'index'])->middleware('permission:evaluator_portal.view')->name('evaluator.proposals.index');
    Route::get('/my-proposals/{proposal}', [EvaluatorPortalController::class, 'show'])->middleware('permission:evaluator_portal.view')->name('evaluator.proposals.show');
    Route::patch('/my-proposals/{proposal}/status', [EvaluatorPortalController::class, 'updateStatus'])->middleware('permission:evaluator_portal.update_status')->name('evaluator.proposals.status');
    Route::post('/my-proposals/{proposal}/remarks', [EvaluatorPortalController::class, 'remark'])->middleware('permission:evaluator_portal.remarks')->name('evaluator.proposals.remarks');
});
