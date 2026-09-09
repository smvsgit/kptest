<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Department;
use App\Models\OrganizationUnit;
use App\Models\PermissionSet;
use App\Models\ApprovalDelegation;
use App\Models\BackupRun;
use App\Models\RestoreVerification;
use App\Models\FontFamily;
use App\Models\MediaAccessRequest;
use App\Models\MediaFile;
use App\Models\MediaRecentView;
use App\Models\MasterDataValue;
use App\Models\PortalNotification;
use App\Models\NotificationPreference;
use App\Models\SavedSearch;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\IntegrationConnection;
use App\Models\MediaSource;
use App\Services\MediaAccessService;
use App\Services\SearchIndexService;
use App\Services\IntegrationHealthService;
use App\Services\BackupService;
use App\Services\ReadinessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    private const FILTER_KEYS = [
        'search','scope','type','sort','department_id','category_id','subcategory_id','access_policy',
        'year','country_id','state_id','city_id','mandir_id','event_id','person_id','language_id','media_type_id',
        'asset_status','source_type','uploaded_by','date_from','date_to','quick_view','panel','page',
    ];

    public function index(Request $request, SearchIndexService $searchIndex, MediaAccessService $access, IntegrationHealthService $integrationHealth, BackupService $backups, ReadinessService $readiness)
    {
        $user = $request->user();
        $simulatedRole = $user->role === 'super-admin' ? session('simulated_role', 'super-admin') : $user->role;
        $query = MediaFile::with([
            'uploader.department', 'department', 'category', 'subcategory',
            'country','state','city','mandir','event','person','language','mediaType','sources.connection',
            'favorites' => fn ($q) => $q->where('user_id', $user->id),
            'accessRequests' => fn ($q) => $q->where('user_id', $user->id)->latest('id'),
        ]);
        $access->applyVisibility($query, $user);
        $searchSettings = SystemSetting::valueFor('search.settings', []);
        $scope = $request->get('scope', 'everywhere');

        // Search within the signed-in user's department. An explicit department filter is ignored in this scope.
        if ($scope === 'current_department' && $user->department_id) {
            $query->where('media_files.department_id', $user->department_id);
        }

        if ($search = trim((string) $request->get('search'))) {
            $ids = [];
            if (($searchSettings['enabled'] ?? true) && ($searchSettings['engine_pipeline_enabled'] ?? true)) {
                $engineFilters = ($searchSettings['filters'] ?? true)
                    ? $request->only(['type','department_id','category_id','subcategory_id','access_policy','year','country_id','state_id','city_id','mandir_id','event_id','person_id','language_id','media_type_id','asset_status','source_type','uploaded_by','date_from','date_to'])
                    : [];
                if ($scope === 'current_department' && $user->department_id) {
                    $engineFilters['department_id'] = $user->department_id;
                }
                if ($user->role !== 'super-admin') {
                    $engineFilters['_visibility_department_id'] = $user->department_id;
                }
                $ids = $searchIndex->search($search, $engineFilters);
            }

            if ($ids) {
                $query->whereIn('media_files.id', $ids);
                $safeIds = implode(',', array_map('intval', $ids));
                if (($searchSettings['mode'] ?? 'meilisearch') === 'hybrid') {
                    $query->orderByRaw('CASE WHEN LOWER(name)=LOWER(?) THEN 0 WHEN LOWER(name) LIKE LOWER(?) THEN 1 ELSE 2 END', [$search, $search.'%'])
                        ->orderByRaw("FIELD(media_files.id, {$safeIds})");
                } else {
                    $query->orderByRaw("FIELD(media_files.id, {$safeIds})");
                }
            } else {
                // Database fallback still searches core structured metadata when Meilisearch is unavailable.
                $query->where(function (Builder $q) use ($search) {
                    $like = '%'.$search.'%';
                    $q->where('name', 'like', $like)
                        ->orWhere('tags', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhereHas('category', fn ($r) => $r->where('name', 'like', $like))
                        ->orWhereHas('subcategory', fn ($r) => $r->where('name', 'like', $like))
                        ->orWhereHas('event', fn ($r) => $r->where('name', 'like', $like))
                        ->orWhereHas('person', fn ($r) => $r->where('name', 'like', $like))
                        ->orWhereHas('city', fn ($r) => $r->where('name', 'like', $like))
                        ->orWhereHas('mandir', fn ($r) => $r->where('name', 'like', $like))
                        ->orWhereHas('language', fn ($r) => $r->where('name', 'like', $like))
                        ->orWhereHas('uploader', fn ($r) => $r->where('name', 'like', $like));
                });
            }
        }

        if ($type = $request->get('type')) $query->where('media_files.type', $type);
        if ($scope !== 'current_department' && ($departmentId = $request->get('department_id'))) $query->where('media_files.department_id', $departmentId);
        if ($categoryId = $request->get('category_id')) $query->where('media_files.category_id', $categoryId);
        if ($subcategoryId = $request->get('subcategory_id')) $query->where('media_files.subcategory_id', $subcategoryId);
        if ($policy = $request->get('access_policy')) $query->where('media_files.access_policy', $policy);
        foreach (['year','country_id','state_id','city_id','mandir_id','event_id','person_id','language_id','media_type_id','asset_status','source_type','uploaded_by'] as $field) {
            if (($value = $request->get($field)) !== null && $value !== '') { if($field==='source_type') $query->where(function($q)use($value){$q->where('media_files.source_type',$value)->orWhereHas('sources',fn($s)=>$s->where('type',$value));}); else $query->where('media_files.'.$field, $value); }
        }
        if ($dateFrom = $request->get('date_from')) $query->whereDate('media_files.created_at', '>=', $dateFrom);
        if ($dateTo = $request->get('date_to')) $query->whereDate('media_files.created_at', '<=', $dateTo);

        $quickView = $request->get('quick_view');
        if ($quickView === 'favorites') {
            $query->whereHas('favorites', fn ($q) => $q->where('user_id', $user->id));
        } elseif ($quickView === 'recent') {
            $query->whereHas('recentViews', fn ($q) => $q->where('user_id', $user->id));
            $query->orderByDesc(MediaRecentView::select('viewed_at')
                ->whereColumn('media_file_id', 'media_files.id')
                ->where('user_id', $user->id)
                ->limit(1));
        }

        $sort = $request->get('sort', ($request->filled('search') && ($searchSettings['best_match_sort'] ?? true)) ? 'best' : 'newest');
        if ($quickView !== 'recent' && (!$request->filled('search') || $sort !== 'best')) {
            match ($sort) {
                'oldest' => $query->oldest('media_files.created_at'),
                'updated' => $query->orderByDesc('media_files.updated_at'),
                'name_asc' => $query->orderBy('media_files.name'),
                'name_desc' => $query->orderByDesc('media_files.name'),
                'size_desc' => $query->orderByDesc('media_files.size'),
                default => $query->latest('media_files.created_at'),
            };
        }

        $categories = Category::query()
            ->withCount(['files' => fn ($q) => $access->applyVisibility($q, $user)])
            ->with(['subcategories' => fn ($q) => $q->withCount(['files' => fn ($files) => $access->applyVisibility($files, $user)])])
            ->orderBy('name')->get();

        $requests = MediaAccessRequest::query()->with(['mediaFile.department','user.department','decider']);
        $delegatedDepartmentIds=ApprovalDelegation::activeNow()->where('delegate_user_id',$user->id)->pluck('department_id')->all();
        if ($user->role === 'department-admin') {
            $requests->whereHas('mediaFile', fn ($q) => $q->where('department_id', $user->department_id));
        } elseif ($user->role !== 'super-admin' && $delegatedDepartmentIds) {
            $requests->where(function($q)use($user,$delegatedDepartmentIds){$q->where('user_id',$user->id)->orWhereHas('mediaFile',fn($m)=>$m->whereIn('department_id',$delegatedDepartmentIds));});
        } elseif ($user->role !== 'super-admin') {
            $requests->where('user_id', $user->id);
        }

        $ownerBase = MediaFile::query()->select('uploaded_by')->whereNotNull('uploaded_by');
        $access->applyVisibility($ownerBase, $user);
        if ($scope === 'current_department' && $user->department_id) {
            $ownerBase->where('department_id', $user->department_id);
        } elseif ($request->filled('department_id')) {
            $ownerBase->where('department_id', $request->integer('department_id'));
        }
        $ownerIds = $ownerBase->distinct()->pluck('uploaded_by');
        $filterOwners = User::query()->whereIn('id', $ownerIds)->select('id','name','department_id')->orderBy('name')->get();

        $favoriteCountQuery = MediaFile::query()->whereHas('favorites', fn ($q) => $q->where('user_id', $user->id));
        $access->applyVisibility($favoriteCountQuery, $user);
        $recentCountQuery = MediaFile::query()->whereHas('recentViews', fn ($q) => $q->where('user_id', $user->id));
        $access->applyVisibility($recentCountQuery, $user);

        $userList = $user->role==='super-admin'
            ? User::with(['department','organizationUnit','permissionSet'])->orderBy('name')->get()
            : ($user->role==='department-admin' ? User::with(['department','organizationUnit','permissionSet'])->where('department_id',$user->department_id)->orderBy('name')->get() : collect());
        $requestRows=$requests->latest('id')->limit(150)->get();
        $requestRows->each(fn($row)=>$row->setAttribute('can_review',$access->canReviewRequest($row,$user)));
        $delegations=ApprovalDelegation::with(['department','delegator:id,name','delegate:id,name'])
            ->when($user->role!=='super-admin',fn($q)=>$q->where('department_id',$user->department_id))->latest('id')->limit(100)->get();

        return Inertia::render('Dashboard', [
            'categories' => $categories,
            'departments' => Department::orderBy('is_system')->orderBy('name')->get(),
            'users'=>$userList,
            'organizationUnits'=>OrganizationUnit::with('parent:id,name')->when($user->role!=='super-admin',fn($q)=>$q->where('department_id',$user->department_id))->orderBy('department_id')->orderBy('type')->orderBy('name')->get(),
            'permissionSets'=>$user->role==='super-admin'?PermissionSet::orderBy('name')->get():[],
            'approvalDelegations'=>$delegations,
            'filterOwners' => $filterOwners,
            'savedSearches' => SavedSearch::where('user_id', $user->id)->latest('updated_at')->get(),
            'quickViewCounts' => ['favorites' => $favoriteCountQuery->count(), 'recent' => $recentCountQuery->count()],
            'fontFamilies' => $user->role === 'super-admin' ? FontFamily::with('files')->orderByDesc('is_system')->orderBy('name')->get() : [],
            'appearanceSettings' => SystemSetting::valueFor('appearance.fonts', ['global' => 'hind-vadodara', 'gujarati' => 'hind-vadodara', 'hindi' => 'noto-sans-devanagari', 'english' => 'inter']),
            'searchSettings' => $searchSettings,
            'masterData' => MasterDataValue::query()->orderBy('type')->orderBy('sort_order')->orderBy('name')->get()->groupBy('type'),
            'metadataSettings' => SystemSetting::valueFor('metadata.settings', ['required_fields'=>['year','event_id','city_id','language_id'],'person_required'=>false,'allow_free_tags'=>true,'years_min'=>1950,'years_max'=>(int) date('Y')+2]),
            'notificationSettings' => $user->role === 'super-admin' ? $this->publicNotificationSettings() : ['portal'=>['enabled'=>true],'email'=>['enabled'=>false,'provider'=>'smtp','host'=>'','port'=>587,'encryption'=>'tls','username'=>'','from_address'=>'','from_name'=>''],'whatsapp'=>['enabled'=>false,'provider'=>'meta-cloud-api','endpoint'=>'','phone_number_id'=>'','business_account_id'=>'','sender'=>''],'sms'=>['enabled'=>false,'provider'=>'generic-http','endpoint'=>'','sender_id'=>''],'events'=>[]],
            'notificationPreferences' => ($pref = NotificationPreference::where('user_id',$user->id)->first()) ? $pref->only(['portal_enabled','email_enabled','whatsapp_enabled','sms_enabled','access_enabled','file_enabled','storage_enabled','security_enabled']) : ['portal_enabled'=>true,'email_enabled'=>true,'whatsapp_enabled'=>true,'sms_enabled'=>true,'access_enabled'=>true,'file_enabled'=>true,'storage_enabled'=>true,'security_enabled'=>true],
            'notificationEscalation' => $user->role === 'super-admin' ? SystemSetting::valueFor('notification.escalation', ['enabled'=>false,'first_after_hours'=>24,'repeat_every_hours'=>24,'max_escalations'=>3]) : ['enabled'=>false,'first_after_hours'=>24,'repeat_every_hours'=>24,'max_escalations'=>3],
            'uploadSettings' => SystemSetting::valueFor('upload.settings', ['allowed_extensions'=>\App\Services\UploadPolicyService::DEFAULT_EXTENSIONS,'max_file_size_mb'=>0,'max_batch_count'=>0,'chunk_threshold_mb'=>50,'chunk_size_mb'=>10,'retry_count'=>3,'exact_duplicate_detection'=>true,'possible_duplicate_warning'=>true]),
            'integrationConnections' => $user->role === 'super-admin' ? IntegrationConnection::withCount('sources')->orderBy('type')->orderBy('name')->get()->map(fn($c)=>$integrationHealth->publicConnection($c))->values() : [],
            'sourceConnections' => $user->canUpload() ? IntegrationConnection::where('is_active',true)->orderBy('name')->get(['id','name','type','status'])->values() : [],
            'integrationHealthSummary' => in_array($user->role,['super-admin','department-admin'],true) ? ['healthy'=>IntegrationConnection::where('status','healthy')->count(),'degraded'=>IntegrationConnection::where('status','degraded')->count(),'unavailable'=>IntegrationConnection::where('status','unavailable')->count(),'broken_sources'=>MediaSource::whereIn('status',['missing','broken'])->when($user->role==='department-admin',fn($q)=>$q->whereHas('mediaFile',fn($m)=>$m->where('department_id',$user->department_id)))->count()] : ['healthy'=>0,'degraded'=>0,'unavailable'=>0,'broken_sources'=>0],
            'backupSettings' => $user->role==='super-admin' ? $backups->settings() : [],
            'backupRuns' => $user->role==='super-admin' ? BackupRun::with('triggeredBy:id,name')->latest('id')->limit(50)->get()->map(fn($r)=>$backups->publicRun($r))->values() : [],
            'restoreVerifications' => $user->role==='super-admin' ? RestoreVerification::with('verifiedBy:id,name')->latest('id')->limit(50)->get()->map(fn($v)=>$backups->publicVerification($v))->values() : [],
            'backupStatus' => $user->role==='super-admin' ? $backups->statusSummary() : ['readiness'=>'unavailable','latest_backup_at'=>null,'latest_backup_id'=>null,'latest_backup_age_minutes'=>null,'last_restore_test_at'=>null,'last_restore_duration_minutes'=>null,'rpo_target_minutes'=>0,'rto_target_minutes'=>0,'rpo_met'=>null,'rto_met'=>null,'retention_policy_pending'=>true,'schedule_enabled'=>false,'app_key_custody_required'=>true],
            'readinessData' => $user->role==='super-admin' ? $readiness->dashboardData() : ['app_version'=>config('version.current'),'uat_cases'=>[],'go_live_settings'=>[],'management_dependencies'=>[],'latest_snapshot'=>null,'latest_review'=>null],
            'currentUser'=>$user->loadMissing(['department','organizationUnit','permissionSet']),
            'securityAuthSettings'=>SystemSetting::valueFor('security.auth',['failed_login_limit'=>5,'lockout_minutes'=>15,'session_timeout_minutes'=>120,'password_min_length'=>12]),
            'accessMaturitySettings'=>SystemSetting::valueFor('access.maturity',['default_expiry_hours'=>0,'signed_download_minutes'=>15,'max_bulk_request_files'=>100]),
            'featureCompletionSettings'=>[
                'maintenance'=>SystemSetting::valueFor('maintenance.settings',[]), 'branding'=>SystemSetting::valueFor('branding.settings',[]),
                'network'=>SystemSetting::valueFor('security.network',[]), 'two_factor'=>SystemSetting::valueFor('security.two_factor',[]),
                'watermark'=>SystemSetting::valueFor('watermark.settings',[]), 'lifecycle'=>SystemSetting::valueFor('lifecycle.settings',[]),
                'type_required'=>SystemSetting::valueFor('metadata.type_required',[]), 'audit_retention'=>SystemSetting::valueFor('audit.retention',[]),
                'recycle_retention'=>SystemSetting::valueFor('recycle.retention',[]), 'storage_quotas'=>SystemSetting::valueFor('storage.quotas',[]),
            ],
            'simulatedRole' => $simulatedRole,
            'appVersion' => [
                'current' => config('version.current'), 'previous' => config('version.previous'), 'release_type' => config('version.release_type'),
                'release_date' => config('version.release_date'), 'release_name' => config('version.release_name'),
            ],
            'filters' => $request->only(self::FILTER_KEYS),
            'accessRequests'=>$requestRows,
            'notifications' => PortalNotification::query()->where('user_id', $user->id)->latest()->limit(60)->get(),
            'unreadNotifications' => PortalNotification::query()->where('user_id', $user->id)->whereNull('read_at')->count(),
            'files' => Inertia::defer(function () use ($query, $user, $access, $integrationHealth) {
                $page = $query->paginate(24)->withQueryString();
                $page->through(function (MediaFile $media) use ($user, $access, $integrationHealth) {
                    foreach ($access->presentation($media, $user) as $key => $value) $media->setAttribute($key, $value);
                    $media->setAttribute('can_manage_policy', $access->canManagePolicy($media, $user));
                    $media->setAttribute('is_favorite', $media->favorites->isNotEmpty());
                    $canManage=$access->canManageLifecycle($media,$user);
                    $media->setAttribute('source_items',$media->sources->map(fn($src)=>$integrationHealth->publicSource($src,$canManage))->values());
                    $media->setAttribute('source_health_status',$media->sources->contains(fn($src)=>in_array($src->status,['missing','broken'],true))?'broken':($media->sources->contains(fn($src)=>$src->status==='active')?'active':'inactive'));
                    $media->makeHidden('sources');
                    return $media;
                });
                return $page;
            }),
            'stats' => Inertia::defer(function () use ($user, $access) {
                $base = MediaFile::query();
                $access->applyVisibility($base, $user);
                $byType = (clone $base)->selectRaw('type, SUM(size) as total')->groupBy('type')->pluck('total', 'type');
                return [
                    'total_files' => (clone $base)->count(), 'total_size' => (clone $base)->sum('size'),
                    'by_type' => [
                        'video' => (int) ($byType['video'] ?? 0), 'image' => (int) ($byType['image'] ?? 0),
                        'audio' => (int) ($byType['audio'] ?? 0), 'document' => (int) ($byType['document'] ?? 0),
                    ],
                ];
            }),
        ]);
    }

    private function publicNotificationSettings(): array
    {
        $settings = SystemSetting::valueFor('notification.channels', []);
        foreach ([['email','password'],['whatsapp','token'],['sms','api_key']] as [$channel,$secret]) {
            if (isset($settings[$channel])) {
                unset($settings[$channel][$secret.'_encrypted']);
                $settings[$channel][$secret] = '';
            }
        }
        return $settings;
    }

    public function simulateRole(Request $request)
    {
        $request->validate(['role' => 'required|in:super-admin,department-admin,department-operator,viewer']);
        session(['simulated_role' => $request->role]);
        return back();
    }
}
