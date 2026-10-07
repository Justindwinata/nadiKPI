<?php

use App\Http\Controllers\ActionItemController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CertificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataImportController;
use App\Http\Controllers\DecisionController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\GovernanceController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\ItController;
use App\Http\Controllers\KpiCatalogController;
use App\Http\Controllers\KpiController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::get('/demo-access', [AuthController::class, 'demoAccess'])->middleware('guest');
    Route::post('/login', [AuthController::class, 'login'])->middleware('guest')->name('login');

    Route::middleware('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->middleware('active.user');
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/account/password', [AuthController::class, 'changePassword'])->middleware('active.user');

        Route::middleware(['active.user', 'password.changed'])->group(function () {
            Route::get('/dashboard', DashboardController::class)->middleware('permission:overview.view');

            Route::prefix('reports')->middleware(['module:reports', 'permission:reports.view'])->group(function () {
                Route::get('/', [ReportController::class, 'index']);
                Route::post('/', [ReportController::class, 'store']);
                Route::get('/{report}', [ReportController::class, 'show']);
                Route::get('/{report}/print', [ReportController::class, 'print'])->middleware('permission:reports.export');
                Route::get('/{report}/export/{format}', [ReportController::class, 'export'])->middleware('permission:reports.export');
            });

            Route::prefix('notifications')->group(function () {
                Route::get('/', [NotificationController::class, 'index']);
                Route::get('/stream', [NotificationController::class, 'stream']);
                Route::post('/read-all', [NotificationController::class, 'readAll']);
                Route::post('/{notification}/read', [NotificationController::class, 'read']);
                Route::post('/{notification}/acknowledge', [NotificationController::class, 'acknowledge']);
            });

            Route::get('/certification', [CertificationController::class, 'index'])->middleware('module:certification');
            Route::post('/certification/batches', [CertificationController::class, 'storeBatch'])->middleware(['module:certification', 'permission:certification.manage']);
            Route::patch('/certification/batches/{batch}', [CertificationController::class, 'updateBatch'])->middleware(['module:certification', 'permission:certification.manage']);
            Route::post('/certification/batches/{batch}/issuances', [CertificationController::class, 'storeIssuance'])->middleware(['module:certification', 'permission:certification.issue']);

            Route::get('/finance', [FinanceController::class, 'index'])->middleware('module:finance');
            Route::post('/finance/records', [FinanceController::class, 'store'])->middleware(['module:finance', 'permission:finance.ledger.manage']);
            Route::post('/finance/records/{record}/reverse', [FinanceController::class, 'reverseRecord'])->middleware(['module:finance', 'permission:finance.reverse']);
            Route::post('/finance/invoices', [FinanceController::class, 'storeInvoice'])->middleware(['module:finance', 'permission:finance.invoices.manage']);
            Route::post('/finance/invoices/{invoice}/payments', [FinanceController::class, 'storePayment'])->middleware(['module:finance', 'permission:finance.payments.manage']);
            Route::post('/finance/invoices/{invoice}/void', [FinanceController::class, 'voidInvoice'])->middleware(['module:finance', 'permission:finance.reverse']);
            Route::post('/finance/payments/{payment}/reverse', [FinanceController::class, 'reversePayment'])->middleware(['module:finance', 'permission:finance.reverse']);

            Route::get('/it', [ItController::class, 'index'])->middleware('module:it');
            Route::post('/it/incidents', [ItController::class, 'storeIncident'])->middleware(['module:it', 'permission:it.incidents.manage']);
            Route::patch('/it/incidents/{incident}/investigate', [ItController::class, 'investigate'])->middleware(['module:it', 'permission:it.incidents.manage']);
            Route::patch('/it/incidents/{incident}/resolve', [ItController::class, 'resolve'])->middleware(['module:it', 'permission:it.incidents.manage']);
            Route::post('/it/data-quality-runs', [ItController::class, 'storeDataQualityRun'])->middleware(['module:it', 'permission:it.data_quality.manage']);

            Route::get('/governance', [GovernanceController::class, 'index'])->middleware('module:governance');
            Route::post('/governance/findings', [GovernanceController::class, 'storeFinding'])->middleware(['module:governance', 'permission:governance.findings.manage']);
            Route::patch('/governance/findings/{finding}', [GovernanceController::class, 'updateFinding'])->middleware(['module:governance', 'permission:governance.findings.manage']);
            Route::post('/governance/findings/{finding}/actions', [GovernanceController::class, 'storeCorrectiveAction'])->middleware(['module:governance', 'permission:governance.findings.manage']);
            Route::patch('/governance/actions/{action}', [GovernanceController::class, 'updateCorrectiveAction'])->middleware(['module:governance', 'permission:governance.findings.manage']);
            Route::post('/governance/appeals', [GovernanceController::class, 'storeAppeal'])->middleware(['module:governance', 'permission:governance.appeals.manage']);
            Route::patch('/governance/appeals/{appeal}', [GovernanceController::class, 'updateAppeal'])->middleware(['module:governance', 'permission:governance.appeals.manage']);
            Route::post('/governance/obligations', [GovernanceController::class, 'storeObligation'])->middleware(['module:governance', 'permission:governance.registry.manage']);
            Route::post('/governance/data-sources', [GovernanceController::class, 'storeDataSource'])->middleware(['module:governance', 'permission:governance.provenance.manage']);
            Route::patch('/governance/import-batches/{batch}/reconcile', [GovernanceController::class, 'reconcileImport'])->middleware(['module:governance', 'permission:governance.provenance.manage']);

            Route::prefix('kpi-catalog')->middleware('permission:kpi.catalog.view')->group(function () {
                Route::get('/', [KpiCatalogController::class, 'index']);
                Route::post('/', [KpiCatalogController::class, 'store'])->middleware('permission:kpi.catalog.manage');
                Route::patch('/{kpi}', [KpiCatalogController::class, 'update'])->middleware('permission:kpi.catalog.manage');
                Route::post('/{kpi}/configurations', [KpiCatalogController::class, 'configure'])->middleware('permission:kpi.catalog.manage');
            });

            Route::post('/kpis/{kpi}/measurements', [KpiController::class, 'storeMeasurement'])->middleware('permission:kpi.custom.manage');
            Route::post('/actions', [ActionItemController::class, 'store'])->middleware('permission:actions.manage');
            Route::patch('/actions/{action}', [ActionItemController::class, 'update'])->middleware('permission:actions.manage');

            Route::prefix('decisions')->middleware(['module:decisions', 'permission:decisions.view'])->group(function () {
                Route::get('/', [DecisionController::class, 'index']);
                Route::post('/signals/{signal}/acknowledge', [DecisionController::class, 'acknowledge'])->middleware('permission:decisions.manage');
                Route::post('/signals/{signal}/escalate', [DecisionController::class, 'escalate'])->middleware('permission:decisions.manage');
                Route::post('/signals/{signal}/resolve', [DecisionController::class, 'resolve'])->middleware('permission:decisions.manage');
                Route::post('/signals/{signal}/actions', [DecisionController::class, 'createAction'])->middleware('permission:decisions.manage');
                Route::post('/reviews', [DecisionController::class, 'storeReview'])->middleware('permission:decisions.review');
                Route::patch('/reviews/{review}', [DecisionController::class, 'updateReview'])->middleware('permission:decisions.review');
                Route::patch('/reviews/{review}/items/{item}', [DecisionController::class, 'updateReviewItem'])->middleware('permission:decisions.review');
            });
            Route::get('/data/template', [DataImportController::class, 'template'])->middleware('permission:data.import.manage');
            Route::post('/data/import', [DataImportController::class, 'import'])->middleware('permission:data.import.manage');

            Route::prefix('integrations')->middleware('permission:integrations.view')->group(function () {
                Route::get('/', [IntegrationController::class, 'index']);
                Route::get('/template/{dataset}', [IntegrationController::class, 'template'])->middleware('permission:integrations.manage');
                Route::post('/stage', [IntegrationController::class, 'stage'])->middleware('permission:integrations.manage');
                Route::get('/batches/{batch}', [IntegrationController::class, 'show']);
                Route::patch('/batches/{batch}/mapping', [IntegrationController::class, 'updateMapping'])->middleware('permission:integrations.manage');
                Route::post('/batches/{batch}/publish', [IntegrationController::class, 'publish'])->middleware('permission:integrations.manage');
                Route::post('/profiles', [IntegrationController::class, 'storeProfile'])->middleware('permission:integrations.manage');
                Route::patch('/profiles/{profile}', [IntegrationController::class, 'updateProfile'])->middleware('permission:integrations.manage');
            });

            Route::get('/master-data', [MasterDataController::class, 'index']);
            Route::post('/master-data/{type}', [MasterDataController::class, 'store'])
                ->where('type', 'certification-schemes|tuks|assessors|it-services');
            Route::patch('/master-data/{type}/{id}', [MasterDataController::class, 'update'])
                ->where('type', 'certification-schemes|tuks|assessors|it-services');
            Route::post('/master-data/{type}/{id}/archive', [MasterDataController::class, 'archive'])
                ->where('type', 'certification-schemes|tuks|assessors|it-services');
            Route::post('/master-data/{type}/{id}/restore', [MasterDataController::class, 'restore'])
                ->where('type', 'certification-schemes|tuks|assessors|it-services');

            Route::prefix('admin')->middleware(['module:admin', 'permission:users.manage'])->group(function () {
                Route::get('/users', [UserAdminController::class, 'index']);
                Route::post('/users', [UserAdminController::class, 'store']);
                Route::patch('/users/{user}', [UserAdminController::class, 'update']);
                Route::patch('/users/{user}/status', [UserAdminController::class, 'updateStatus']);
                Route::put('/users/{user}/permissions', [UserAdminController::class, 'updatePermissions']);
                Route::post('/users/{user}/reset-password', [UserAdminController::class, 'resetPassword']);
            });
        });
    });
});

Route::view('/{path?}', 'app')->where('path', '^(?!api).*$');
