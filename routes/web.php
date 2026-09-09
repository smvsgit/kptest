<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChunkUploadController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\FileDeliveryController;
use App\Http\Controllers\FontController;
use App\Http\Controllers\AppearanceSettingsController;
use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SearchSettingsController;
use App\Http\Controllers\SearchDiscoveryController;
use App\Http\Controllers\MediaFileController;
use App\Http\Controllers\MediaLifecycleController;
use App\Http\Controllers\VersionChunkUploadController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\IntegrationHealthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\MediaSourceController;
use App\Http\Controllers\UploadSettingsController;
use App\Http\Controllers\BulkMediaController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\MetadataSettingsController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ApprovalDelegationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PermissionSetController;
use App\Http\Controllers\SecuritySettingsController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\ReadinessController;
use App\Http\Controllers\FeatureCompletionSettingsController;
use App\Http\Controllers\ContentOrganizationController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\LifecycleWorkflowController;
use App\Http\Controllers\ImportExportController;
use App\Http\Controllers\ScheduledReportController;
use App\Http\Controllers\UserGuideController;
use Illuminate\Support\Facades\Route;

// Guest-only auth routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/forgot-password', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'send'])->name('password.email')->middleware('throttle:5,1');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.update')->middleware('throttle:5,1');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Font assets are public application assets; upload/configuration remains Super Admin-only.
Route::get('/fonts/files/{fontFile}', [FontController::class, 'asset'])->name('fonts.asset');

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/user-guide', [UserGuideController::class, 'index'])->name('user-guide.index');
    Route::get('/user-guide/html', [UserGuideController::class, 'html'])->name('user-guide.html');
    Route::get('/user-guide/document', [UserGuideController::class, 'document'])->name('user-guide.document');
    Route::get('/two-factor-challenge', fn () => \Inertia\Inertia::render('Auth/TwoFactorChallenge'))->name('security.2fa.challenge-page');
    Route::post('/two-factor-challenge', [TwoFactorController::class, 'challenge'])->name('security.2fa.challenge');
    Route::post('/profile/two-factor/setup', [TwoFactorController::class, 'setup'])->name('security.2fa.setup');
    Route::post('/profile/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('security.2fa.confirm');
    Route::delete('/profile/two-factor', [TwoFactorController::class, 'disable'])->name('security.2fa.disable');


    // Super Admin role simulation is a UI/testing aid only. Real backend
    // permissions always use the authenticated user's persisted role.
    Route::post('/simulate-role', [DashboardController::class, 'simulateRole'])
        ->name('simulate-role')
        ->middleware('role:super-admin');

    // Upload permissions: Super Admin, Department Admin, Department Operator.
    Route::post('/files/chunk', [ChunkUploadController::class, 'store'])
        ->name('files.chunk')->middleware(['role:super-admin,department-admin,department-operator','permission:upload']);
    Route::get('/files/chunk/{uploadId}/status', [ChunkUploadController::class, 'status'])
        ->name('files.chunk-status')->middleware(['role:super-admin,department-admin,department-operator','permission:upload']);
    Route::delete('/files/chunk/{uploadId}', [ChunkUploadController::class, 'cancel'])
        ->name('files.chunk-cancel')->middleware(['role:super-admin,department-admin,department-operator','permission:upload']);
    Route::post('/files', [MediaFileController::class, 'store'])
        ->name('files.store')->middleware(['role:super-admin,department-admin,department-operator','permission:upload']);
    Route::post('/files/upload-preflight', [UploadSettingsController::class, 'preflight'])
        ->name('files.upload-preflight')->middleware(['role:super-admin,department-admin,department-operator','permission:upload']);
    Route::patch('/files/bulk-edit', [BulkMediaController::class, 'update'])
        ->name('files.bulk-edit')->middleware(['role:super-admin,department-admin,department-operator','permission:manage_lifecycle']);

    // All local media delivery flows through Laravel so v03.00 access policies can be enforced centrally.
    Route::get('/files/{mediaFile}/preview', [FileDeliveryController::class, 'preview'])->name('files.preview');
    Route::get('/files/{mediaFile}/thumbnail', [FileDeliveryController::class, 'thumbnail'])->name('files.thumbnail');
    Route::get('/files/{mediaFile}/download', [FileDeliveryController::class, 'download'])->name('files.download');
    Route::get('/files/{mediaFile}/approved-download', [FileDeliveryController::class, 'approvedDownload'])->name('files.approved-download')->middleware('signed');
    Route::post('/files/bulk-download', [FileDeliveryController::class, 'bulkDownload'])->name('files.bulk-download');


    // v03.00 Public / Protected / Private access workflow.
    Route::post('/files/{mediaFile}/access-request', [AccessRequestController::class, 'store'])->name('files.access-request');
    Route::post('/access-requests/bulk', [AccessRequestController::class, 'storeBulk'])->name('access-requests.bulk');
    Route::patch('/access-requests/{accessRequest}', [AccessRequestController::class, 'update'])->name('access-requests.update');
    Route::post('/access-requests/{accessRequest}/resubmit', [AccessRequestController::class, 'resubmit'])->name('access-requests.resubmit');
    Route::patch('/files/{mediaFile}/access-policy', [MediaFileController::class, 'updateAccessPolicy'])
        ->name('files.access-policy')->middleware(['role:super-admin,department-admin,department-operator','permission:manage_policy']);


    // v05.00 immutable version history, archive and Recycle Bin lifecycle.
    Route::get('/files/{mediaFile}/versions', [MediaLifecycleController::class, 'versions'])->name('files.versions');
    Route::post('/files/{mediaFile}/versions', [MediaLifecycleController::class, 'storeVersion'])
        ->name('files.versions.store')->middleware(['role:super-admin,department-admin,department-operator','permission:manage_lifecycle']);
    Route::post('/files/{mediaFile}/version-chunks', [VersionChunkUploadController::class, 'store'])
        ->name('files.version-chunks.store')->middleware(['role:super-admin,department-admin,department-operator','permission:manage_lifecycle']);
    Route::get('/files/{mediaFile}/version-chunks/{uploadId}/status', [VersionChunkUploadController::class, 'status'])
        ->name('files.version-chunks.status')->middleware(['role:super-admin,department-admin,department-operator','permission:manage_lifecycle']);
    Route::delete('/files/{mediaFile}/version-chunks/{uploadId}', [VersionChunkUploadController::class, 'cancel'])
        ->name('files.version-chunks.cancel')->middleware(['role:super-admin,department-admin,department-operator','permission:manage_lifecycle']);
    Route::post('/files/{mediaFile}/versions/{version}/restore', [MediaLifecycleController::class, 'restoreVersion'])
        ->name('files.versions.restore')->middleware('role:super-admin,department-admin');
    Route::get('/files/{mediaFile}/versions/{version}/download', [MediaLifecycleController::class, 'downloadVersion'])
        ->name('files.versions.download')->middleware('role:super-admin,department-admin,department-operator');
    Route::patch('/files/{mediaFile}/archive', [MediaLifecycleController::class, 'archive'])
        ->name('files.archive')->middleware('role:super-admin,department-admin');
    Route::get('/files/recycle-bin/items', [MediaLifecycleController::class, 'recycleBin'])
        ->name('files.recycle-bin')->middleware('role:super-admin,department-admin');
    Route::post('/files/recycle-bin/{mediaFile}/restore', [MediaLifecycleController::class, 'restoreDeleted'])
        ->name('files.recycle-bin.restore')->middleware('role:super-admin,department-admin');
    Route::delete('/files/recycle-bin/{mediaFile}/permanent', [MediaLifecycleController::class, 'forceDelete'])
        ->name('files.recycle-bin.force-delete')->middleware('role:super-admin');

    // v10.00 integrations, source health and repair workflow.
    Route::get('/integrations/health', [IntegrationHealthController::class, 'index'])->name('integrations.health')->middleware('role:super-admin,department-admin');
    Route::post('/integrations/health/run', [IntegrationHealthController::class, 'run'])->name('integrations.health.run')->middleware('role:super-admin');
    Route::post('/reference-assets', [MediaSourceController::class, 'createReference'])->name('reference-assets.store')->middleware(['role:super-admin,department-admin,department-operator','permission:upload']);
    Route::post('/files/{mediaFile}/sources', [MediaSourceController::class, 'store'])->name('files.sources.store')->middleware(['role:super-admin,department-admin,department-operator','permission:manage_lifecycle']);
    Route::patch('/files/{mediaFile}/sources/{source}', [MediaSourceController::class, 'update'])->name('files.sources.update')->middleware(['role:super-admin,department-admin,department-operator','permission:manage_lifecycle']);
    Route::delete('/files/{mediaFile}/sources/{source}', [MediaSourceController::class, 'destroy'])->name('files.sources.destroy')->middleware(['role:super-admin,department-admin,department-operator','permission:manage_lifecycle']);
    Route::post('/files/{mediaFile}/sources/{source}/check', [MediaSourceController::class, 'check'])->name('files.sources.check');
    Route::get('/files/{mediaFile}/sources/{source}/open', [MediaSourceController::class, 'deliver'])->name('files.sources.open');

    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::patch('/notifications/preferences', [NotificationController::class, 'preferences'])->name('notifications.preferences');

    // v09.00 organization, user lifecycle, access maturity and session controls.
    Route::post('/approval-delegations', [ApprovalDelegationController::class, 'store'])->name('approval-delegations.store')->middleware('role:department-admin');
    Route::delete('/approval-delegations/{approvalDelegation}', [ApprovalDelegationController::class, 'destroy'])->name('approval-delegations.destroy')->middleware('role:super-admin,department-admin');
    Route::get('/sessions', [SessionController::class, 'index'])->name('sessions.index');
    Route::delete('/sessions/others', [SessionController::class, 'destroyOthers'])->name('sessions.others.destroy');
    Route::delete('/sessions/{sessionId}', [SessionController::class, 'destroy'])->name('sessions.destroy');
    Route::delete('/users/{user}/sessions', [SessionController::class, 'destroyUser'])->name('users.sessions.destroy')->middleware('role:super-admin');
    Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status')->middleware('role:super-admin');
    Route::patch('/users/{user}/permission-set', [UserController::class, 'updatePermissionSet'])->name('users.permission-set')->middleware('role:super-admin');
    Route::post('/users/{user}/temporary-password', [UserController::class, 'temporaryPassword'])->name('users.temporary-password')->middleware('role:super-admin');
    Route::get('/users/export/csv', [UserController::class, 'exportCsv'])->name('users.export')->middleware('role:super-admin');
    Route::post('/users/import/csv', [UserController::class, 'importCsv'])->name('users.import')->middleware('role:super-admin');

    // v08.00 role-scoped dashboards/reports and auditable exports.
    Route::get('/reports/summary', [ReportController::class, 'summary'])->name('reports.summary');
    Route::get('/reports/access', [ReportController::class, 'access'])->name('reports.access');
    Route::get('/reports/activity', [ReportController::class, 'activity'])->name('reports.activity');
    Route::get('/reports/notifications', [ReportController::class, 'notifications'])->name('reports.notifications')->middleware('role:super-admin');
    Route::get('/reports/export/{report}', [ReportController::class, 'export'])->name('reports.export');
    Route::post('/reports/audit/verify', [ReportController::class, 'verifyAudit'])->name('reports.audit.verify')->middleware('role:super-admin');


    // v06.00 Search & Discovery: user-owned saved searches, favorites and recent views.
    Route::post('/searches/saved', [SearchDiscoveryController::class, 'save'])->name('searches.saved.store');
    Route::delete('/searches/saved/{savedSearch}', [SearchDiscoveryController::class, 'destroy'])->name('searches.saved.destroy');
    Route::post('/files/{mediaFile}/favorite', [SearchDiscoveryController::class, 'favorite'])->name('files.favorite');
    Route::post('/files/{mediaFile}/recent', [SearchDiscoveryController::class, 'recent'])->name('files.recent');

    // v13.00 information architecture and governed lifecycle.
    Route::post('/folders', [ContentOrganizationController::class, 'folderStore'])->name('folders.store')->middleware('role:super-admin,department-admin');
    Route::patch('/folders/{folder}', [ContentOrganizationController::class, 'folderUpdate'])->name('folders.update')->middleware('role:super-admin,department-admin');
    Route::delete('/folders/{folder}', [ContentOrganizationController::class, 'folderDelete'])->name('folders.destroy')->middleware('role:super-admin,department-admin');
    Route::post('/collections', [ContentOrganizationController::class, 'collectionStore'])->name('collections.store')->middleware('role:super-admin,department-admin');
    Route::patch('/collections/{collection}', [ContentOrganizationController::class, 'collectionUpdate'])->name('collections.update')->middleware('role:super-admin,department-admin');
    Route::delete('/collections/{collection}', [ContentOrganizationController::class, 'collectionDelete'])->name('collections.destroy')->middleware('role:super-admin,department-admin');
    Route::put('/collections/{collection}/items', [ContentOrganizationController::class, 'membership'])->name('collections.items')->middleware('role:super-admin,department-admin');
    Route::put('/files/{mediaFile}/related-assets', [ContentOrganizationController::class, 'related'])->name('files.related-assets')->middleware(['role:super-admin,department-admin,department-operator','permission:manage_lifecycle']);
    Route::patch('/files/{mediaFile}/lifecycle', [LifecycleWorkflowController::class, 'transition'])->name('files.lifecycle.transition')->middleware(['role:super-admin,department-admin,department-operator','permission:manage_lifecycle']);

    // Destructive file action is limited to admins.
    Route::delete('/files/bulk-delete', [MediaFileController::class, 'bulkDelete'])
        ->name('files.bulk-delete')->middleware(['role:super-admin,department-admin','permission:delete']);

    // Category administration is limited to Super Admin and Department Admin.
    Route::post('/categories', [CategoryController::class, 'store'])
        ->name('categories.store')->middleware(['role:super-admin,department-admin','permission:manage_categories']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])
        ->name('categories.destroy')->middleware(['role:super-admin,department-admin','permission:manage_categories']);
    Route::post('/categories/{category}/subcategories', [CategoryController::class, 'storeSubcategory'])
        ->name('subcategories.store')->middleware(['role:super-admin,department-admin','permission:manage_categories']);
    Route::delete('/subcategories/{subcategory}', [CategoryController::class, 'destroySubcategory'])
        ->name('subcategories.destroy')->middleware(['role:super-admin,department-admin','permission:manage_categories']);

    Route::get('/reports/export/{report}.xlsx', [ImportExportController::class, 'reportXlsx'])->name('reports.export.xlsx')->middleware('role:super-admin,department-admin');
    Route::get('/scheduled-report-runs/{run}/download', [ScheduledReportController::class, 'download'])->name('scheduled-report-runs.download');

    // Profile
    Route::post('/organization-units', [OrganizationController::class, 'store'])->name('organization-units.store')->middleware('role:super-admin');
    Route::patch('/organization-units/{organizationUnit}', [OrganizationController::class, 'update'])->name('organization-units.update')->middleware('role:super-admin');
    Route::delete('/organization-units/{organizationUnit}', [OrganizationController::class, 'destroy'])->name('organization-units.destroy')->middleware('role:super-admin');
    Route::post('/permission-sets', [PermissionSetController::class, 'store'])->name('permission-sets.store')->middleware('role:super-admin');
    Route::patch('/permission-sets/{permissionSet}', [PermissionSetController::class, 'update'])->name('permission-sets.update')->middleware('role:super-admin');
    Route::delete('/permission-sets/{permissionSet}', [PermissionSetController::class, 'destroy'])->name('permission-sets.destroy')->middleware('role:super-admin');
    Route::patch('/settings/security-auth', [SecuritySettingsController::class, 'update'])->name('settings.security-auth')->middleware('role:super-admin');
    Route::patch('/settings/access-maturity', [SecuritySettingsController::class, 'updateAccess'])->name('settings.access-maturity')->middleware('role:super-admin');

    Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
    Route::patch('/profile', [ProfileController::class, 'updateInfo'])->name('profile.update');
    Route::patch('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    // Super Admin-only organization and authorization management.
    Route::middleware('role:super-admin')->group(function () {
        Route::patch('/users/{user}/role', [UserController::class, 'updateRole'])
            ->name('users.update-role');
        Route::patch('/users/{user}/department', [UserController::class, 'updateDepartment'])
            ->name('users.update-department');

        Route::post('/departments', [DepartmentController::class, 'store'])
            ->name('departments.store');
        Route::patch('/departments/{department}', [DepartmentController::class, 'update'])
            ->name('departments.update');
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy'])
            ->name('departments.destroy');

        Route::patch('/settings/appearance/fonts', [AppearanceSettingsController::class, 'update'])->name('settings.fonts.update');
        Route::post('/settings/fonts/upload', [FontController::class, 'upload'])->name('settings.fonts.upload');
        Route::patch('/settings/fonts/{fontFamily}/status', [FontController::class, 'setStatus'])->name('settings.fonts.status');
        Route::delete('/settings/fonts/{fontFamily}', [FontController::class, 'destroy'])->name('settings.fonts.destroy');
        Route::patch('/settings/search', [SearchSettingsController::class, 'update'])->name('settings.search.update');
        Route::post('/settings/search/sync', [SearchSettingsController::class, 'sync'])->name('settings.search.sync');
        Route::post('/settings/master-data', [MasterDataController::class, 'store'])->name('settings.master-data.store');
        Route::patch('/settings/master-data/{masterDataValue}', [MasterDataController::class, 'update'])->name('settings.master-data.update');
        Route::delete('/settings/master-data/{masterDataValue}', [MasterDataController::class, 'destroy'])->name('settings.master-data.destroy');
        Route::patch('/settings/metadata', [MetadataSettingsController::class, 'update'])->name('settings.metadata.update');
        Route::patch('/settings/notifications', [NotificationSettingsController::class, 'update'])->name('settings.notifications.update');
        Route::patch('/settings/notifications/escalation', [NotificationSettingsController::class, 'updateEscalation'])->name('settings.notifications.escalation');
        Route::patch('/settings/upload', [UploadSettingsController::class, 'update'])->name('settings.upload.update');
        Route::post('/settings/notifications/test', [NotificationSettingsController::class, 'test'])->name('settings.notifications.test');
        Route::post('/settings/integrations', [IntegrationController::class, 'store'])->name('settings.integrations.store');
        Route::patch('/settings/integrations/{integrationConnection}', [IntegrationController::class, 'update'])->name('settings.integrations.update');
        Route::delete('/settings/integrations/{integrationConnection}', [IntegrationController::class, 'destroy'])->name('settings.integrations.destroy');
        Route::post('/settings/integrations/{integrationConnection}/check', [IntegrationController::class, 'check'])->name('settings.integrations.check');
        Route::patch('/settings/backups', [BackupController::class, 'updateSettings'])->name('settings.backups.update');
        Route::post('/settings/backups/create', [BackupController::class, 'create'])->name('settings.backups.create');
        Route::post('/settings/backups/{backupRun}/verify', [BackupController::class, 'verify'])->name('settings.backups.verify');
        Route::post('/settings/backups/{backupRun}/restore-verification', [BackupController::class, 'restoreVerification'])->name('settings.backups.restore-verification');
        Route::get('/settings/backups/{backupRun}/download', [BackupController::class, 'download'])->name('settings.backups.download');
        Route::post('/settings/backups/prune', [BackupController::class, 'prune'])->name('settings.backups.prune');
        // v12.x UAT + Go-Live Readiness control center.
        Route::post('/settings/readiness/run', [ReadinessController::class, 'run'])->name('settings.readiness.run');
        Route::patch('/settings/readiness/uat/{uatCase}', [ReadinessController::class, 'updateUat'])->name('settings.readiness.uat.update');
        Route::post('/settings/readiness/uat/{uatCase}/approve', [ReadinessController::class, 'approveUat'])->name('settings.readiness.uat.approve');
        Route::patch('/settings/readiness/go-live', [ReadinessController::class, 'updateSettings'])->name('settings.readiness.go-live.update');
        Route::patch('/settings/readiness/dependencies', [ReadinessController::class, 'updateDependencies'])->name('settings.readiness.dependencies.update');
        Route::post('/settings/readiness/review', [ReadinessController::class, 'review'])->name('settings.readiness.review');
        Route::get('/settings/readiness/export.csv', [ReadinessController::class, 'export'])->name('settings.readiness.export');
        Route::patch('/settings/feature-completion', [FeatureCompletionSettingsController::class, 'update'])->name('settings.feature-completion.update');
        Route::post('/settings/branding/logo', [FeatureCompletionSettingsController::class, 'logo'])->name('settings.branding.logo');
        Route::delete('/settings/branding/logo', [FeatureCompletionSettingsController::class, 'resetLogo'])->name('settings.branding.logo.reset');
        Route::get('/users/export.xlsx', [ImportExportController::class, 'usersExport'])->name('users.export.xlsx');
        Route::post('/users/import.xlsx', [ImportExportController::class, 'usersImport'])->name('users.import.xlsx');
        Route::get('/scheduled-reports', [ScheduledReportController::class, 'index'])->name('scheduled-reports.index');
        Route::post('/scheduled-reports', [ScheduledReportController::class, 'store'])->name('scheduled-reports.store');
        Route::patch('/scheduled-reports/{scheduledReport}', [ScheduledReportController::class, 'update'])->name('scheduled-reports.update');
        Route::delete('/scheduled-reports/{scheduledReport}', [ScheduledReportController::class, 'destroy'])->name('scheduled-reports.destroy');
        Route::post('/scheduled-reports/{scheduledReport}/run', [ScheduledReportController::class, 'run'])->name('scheduled-reports.run');

    });
});
