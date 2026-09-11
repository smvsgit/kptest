export type UserRole = 'super-admin' | 'department-admin' | 'department-operator' | 'viewer';
export type UiThemePreset = 'smvs-light' | 'smvs-dark' | 'slack-light' | 'slack-dark' | 'google-light' | 'google-dark' | 'ocean-light' | 'ocean-dark' | 'royal-light' | 'royal-dark' | 'forest-light' | 'forest-dark' | 'rose-light' | 'rose-dark' | 'amber-light' | 'amber-dark';
export type UiTheme = UiThemePreset | 'smvs' | 'slack' | 'google';
export type PageAccessKey = 'browse'|'upload'|'access'|'reports'|'integrations'|'guide'|'settings';

export interface Department { id:number; name:string; is_active:boolean; is_system:boolean; }
export interface OrganizationUnit { id:number; department_id:number; parent_id:number|null; type:'sub-department'|'team'; name:string; is_active:boolean; parent?:{id:number;name:string}|null; }
export interface PermissionSet { id:number; name:string; description:string|null; permissions:Record<string,boolean>; is_active:boolean; }

export interface PortalRole { id:number; name:string; slug:string; base_role:UserRole; is_builtin:boolean; is_active:boolean; permissions:Record<string,boolean>; page_access:Record<PageAccessKey,boolean>; }
export interface UserGroup { id:number; department_id:number|null; name:string; description:string|null; portal_role_id:number|null; is_active:boolean; department?:Department|null; portal_role?:PortalRole|null; members?:User[]; }
export interface NetworkPolicySummary { enabled:boolean; department_admin_can_manage_external_access:boolean; }

export interface ApprovalDelegation { id:number; department_id:number; delegator_user_id:number; delegate_user_id:number; starts_at:string; ends_at:string; is_active:boolean; reason:string|null; department?:Department; delegator?:{id:number;name:string}; delegate?:{id:number;name:string}; }
export interface SecurityAuthSettings { failed_login_limit:number; lockout_minutes:number; session_timeout_minutes:number; password_min_length:number; }
export interface AccessMaturitySettings { default_expiry_hours:number; signed_download_minutes:number; max_bulk_request_files:number; }
export interface AppVersion { current:string; previous:string; release_type:'major'|'minor'|string; release_date:string; release_name:string; }
export type FileType = 'image'|'video'|'audio'|'document';
export type AccessPolicy = 'public'|'protected'|'private';
export type AccessLevel = 'view'|'download';
export type AccessRequestStatus='pending'|'approved'|'rejected'|'more-info'|'expired'|'revoked'|'cancelled';
export interface User { id:number; name:string; email:string; phone:string|null; department_id:number|null; department?:Department|null; organization_unit_id?:number|null; organization_unit?:OrganizationUnit|null; role:UserRole; portal_role_id?:number|null; portal_role?:PortalRole|null; permission_set_id?:number|null; permission_set?:PermissionSet|null; groups?:UserGroup[]; status?:'active'|'disabled'|'inactive'; status_reason?:string|null; locked_until?:string|null; last_login_at?:string|null; last_seen_at?:string|null; must_change_password?:boolean; preferred_language?:'en'|'gu'|null; ui_theme?:UiTheme|null; two_factor_confirmed_at?:string|null; external_access_allowed?:boolean; external_access_starts_at?:string|null; external_access_expires_at?:string|null; external_access_reason?:string|null; external_access_approved_by?:number|null; external_access_approver?:{id:number;name:string}|null; }
export interface Category { id:number; name:string; subcategories:Subcategory[]; files_count:number; }
export interface Subcategory { id:number; category_id:number; name:string; files_count:number; }

export type MasterDataType = 'country'|'state'|'city'|'mandir'|'event'|'person'|'language'|'media_type';
export interface MasterDataValue { id:number; type:MasterDataType; name:string; code:string|null; parent_id:number|null; aliases:string[]; extra?:Record<string,unknown>|null; is_active:boolean; sort_order:number; }
export type MasterDataMap = Partial<Record<MasterDataType,MasterDataValue[]>>;
export interface MetadataSettings { required_fields:string[]; person_required:boolean; allow_free_tags:boolean; years_min:number; years_max:number; }


export interface IntegrationConnection { id:number; name:string; type:'local'|'nas'|'google-drive'|'youtube'; is_active:boolean; root_path:string|null; base_url:string|null; credential_set:boolean; sync_frequency_minutes:number; storage_warning_percent:number; status:string; last_checked_at:string|null; last_success_at:string|null; last_error:string|null; capacity_bytes:number|null; used_bytes:number|null; free_bytes:number|null; metadata?:Record<string,unknown>|null; source_count:number; }
export interface SourceConnection { id:number; name:string; type:'local'|'nas'|'google-drive'|'youtube'; status:string; }
export interface MediaSourceItem { id:number; media_file_id:number; integration_connection_id:number|null; type:'local'|'nas'|'google-drive'|'youtube'; label:string|null; locator:string|null; external_id:string|null; is_primary:boolean; is_enabled:boolean; status:'active'|'inactive'|'missing'|'broken'|string; last_checked_at:string|null; last_success_at:string|null; broken_detected_at:string|null; last_error:string|null; repair_note:string|null; open_url:string|null; embed_url:string|null; connection?:{id:number;name:string;type:string;status:string;is_active:boolean}|null; }
export interface IntegrationHealthSummary { healthy:number; degraded:number; unavailable:number; broken_sources:number; }

export interface UploadSettings {
    allowed_extensions:string[]; max_file_size_mb:number; max_batch_count:number;
    chunk_threshold_mb:number; chunk_size_mb:number; retry_count:number;
    exact_duplicate_detection:boolean; possible_duplicate_warning:boolean;
}




export type UatStatus='pending'|'wip'|'passed'|'failed'|'blocked';
export interface UatCaseRow { id:number; code:string; title:string; priority:string; category:string|null; status:UatStatus; execution_notes:string|null; evidence_reference:string|null; executed_by:string|null; executed_at:string|null; executed_app_version:string|null; approved_by:string|null; approved_at:string|null; approved_app_version:string|null; }
export interface ReadinessCheck { key:string; label:string; status:'pass'|'warn'|'fail'; blocking:boolean; detail:string; meta?:Record<string,unknown>; }
export interface ReadinessSummary { overall_status:'ready'|'ready-with-warnings'|'blocked'|string; blocking_failures:number; warnings:number; passes:number; total:number; go_live_allowed:boolean; }
export interface ReadinessSnapshotRow { id:number; app_version:string|null; overall_status:string; checks:ReadinessCheck[]; summary:ReadinessSummary; run_by:string|null; run_at:string|null; }
export interface GoLiveSettings { planned_go_live_at:string; deployment_owner:string; rollback_owner:string; business_signoff_owner:string; smoke_test_owner:string; support_contact:string; rollback_window_minutes:number; change_freeze_confirmed:boolean; }
export interface ManagementDependency { key:string; label:string; status:'pending'|'resolved'|'accepted-risk'; note:string; }
export interface GoLiveReviewRow { id:number; decision:'draft'|'approved'|'rejected'|string; app_version:string|null; notes:string|null; dependency_snapshot?:ManagementDependency[]|null; reviewed_by:string|null; reviewed_at:string|null; readiness_snapshot_id:number|null; }
export interface ReadinessData { app_version:string; uat_cases:UatCaseRow[]; go_live_settings:GoLiveSettings; management_dependencies:ManagementDependency[]; latest_snapshot:ReadinessSnapshotRow|null; latest_review:GoLiveReviewRow|null; }

export interface BackupSettings {
 destination_type:'local'|'filesystem'; filesystem_path:string; responsible_owner:string;
 daily_enabled:boolean; daily_hour:number; weekly_enabled:boolean; weekly_day:number; weekly_hour:number; monthly_enabled:boolean; monthly_day:number; monthly_hour:number;
 daily_retention_days:number; weekly_retention_days:number; monthly_retention_days:number; rpo_minutes:number; rto_minutes:number;
 backup_database:boolean; backup_configuration:boolean; backup_audit_security:boolean; external_source_responsibility:string;
}
export interface BackupRunRow { id:number; trigger:string; retention_class:string; status:string; destination_type:string; storage_path:string|null; filename:string|null; size_bytes:number|null; sha256:string|null; format_version:string; encrypted:boolean; scope?:Record<string,boolean>|null; manifest?:Record<string,unknown>|null; triggered_by:string|null; started_at:string|null; completed_at:string|null; verified_at:string|null; error:string|null; }
export interface RestoreVerificationRow { id:number; backup_run_id:number|null; verification_type:string; status:string; target_environment:string|null; duration_minutes:number|null; results?:Record<string,unknown>|null; notes:string|null; verified_by:string|null; verified_at:string|null; }
export interface BackupStatusSummary { readiness:'ready'|'warning'|'policy-pending'|string; latest_backup_at:string|null; latest_backup_id:number|null; latest_backup_verified?:boolean; latest_backup_verified_at?:string|null; latest_backup_verification_status?:string|null; latest_backup_age_minutes:number|null; last_restore_test_at:string|null; last_restore_duration_minutes:number|null; rpo_target_minutes:number; rto_target_minutes:number; rpo_met:boolean|null; rto_met:boolean|null; retention_policy_pending:boolean; schedule_enabled:boolean; app_key_custody_required:boolean; destination_type?:string; responsible_owner?:string; }

export interface NotificationPreferences {
 portal_enabled:boolean; email_enabled:boolean; whatsapp_enabled:boolean; sms_enabled:boolean;
 access_enabled:boolean; file_enabled:boolean; storage_enabled:boolean; security_enabled:boolean;
}
export interface NotificationEscalationSettings { enabled:boolean; first_after_hours:number; repeat_every_hours:number; max_escalations:number; }
export interface ReportAuditRow { id:number; created_at:string|null; user:string; event:string; subject:string; description:string|null; ip_address:string|null; session_id:string|null; source:string|null; context?:Record<string,unknown>|null; }
export interface NotificationDeliveryRow { id:number; created_at:string|null; event:string; category:string; channel:string; provider:string|null; recipient:string|null; status:string; attempts:number; sent_at:string|null; error:string|null; user:string|null; }

export interface NotificationChannelSettings {
 portal:{enabled:boolean};
 email:{enabled:boolean;provider:string;host:string;port:number;encryption:string;username:string;password?:string;password_set?:boolean;from_address:string;from_name:string};
 whatsapp:{enabled:boolean;provider:string;endpoint:string;phone_number_id:string;business_account_id:string;token?:string;token_set?:boolean;sender:string};
 sms:{enabled:boolean;provider:string;endpoint:string;api_key?:string;api_key_set?:boolean;sender_id:string};
 events:Record<string,{portal:boolean;email:boolean;whatsapp:boolean;sms:boolean}>;
}

export interface FontFile { id:number; weight:number; style:'normal'|'italic'; original_name:string; mime:string|null; asset_url:string; }
export interface FontFamily { id:number; name:string; slug:string; css_family:string; source:'fontsource'|'custom'|string; scripts:string[]; package?:string|null; is_system:boolean; is_active:boolean; files:FontFile[]; }
export interface FontAssignments { global:string; english:string; hindi:string; gujarati:string; }
export interface SearchSettings {
    enabled:boolean; mode:'meilisearch'|'hybrid'; fuzzy:boolean; search_as_you_type:boolean;
    search_ui_enabled:boolean; best_match_sort:boolean; department_filter:boolean; engine_pipeline_enabled:boolean;
    synonyms:boolean; aliases:boolean; transliteration:boolean; filters:boolean;
    exact_fields_enabled:boolean; exact_fields:string[]; synonym_groups:string[][]; alias_groups:string[][];
    hybrid_semantic_enabled:boolean;
}
export interface MediaFileVersion { id:number; version_number:number; original_name:string; size:number; checksum_sha256:string|null; change_note:string|null; changed_by:string|null; restored_from_version_id:number|null; created_at:string; is_current:boolean; download_url:string; }
export interface RecycleBinItem { id:number; name:string; type:FileType; size:number; department:string|null; current_version:number; deleted_at:string; }

export interface MediaFile {
    id:number; name:string; type:FileType; size:number; category_id:number|null; subcategory_id:number|null;
    department_id:number|null; department?:Department|null; year?:number|null; country_id?:number|null; state_id?:number|null; city_id?:number|null; mandir_id?:number|null; event_id?:number|null; person_id?:number|null; language_id?:number|null; media_type_id?:number|null; description?:string|null; internal_remarks?:string|null; source_type?:string; asset_status?:string; current_version:number; duplicate_of_id?:number|null; archived_from_status?:string|null; archived_at?:string|null; country?:MasterDataValue|null; state?:MasterDataValue|null; city?:MasterDataValue|null; mandir?:MasterDataValue|null; event?:MasterDataValue|null; person?:MasterDataValue|null; language?:MasterDataValue|null; media_type?:MasterDataValue|null; access_policy:AccessPolicy; download_allowed:boolean; tags:string[]; resolution:string|null;
    file_path:string|null; thumbnail_path:string|null; thumbnail_url:string|null; video_url:string|null;
    audio_url:string|null; document_url:string|null; download_url:string|null; temporary_download_url?:string|null; checksum_sha256?:string|null;
    processing_status?:string; uploaded_by:number; uploader:User; created_at:string;
    can_preview:boolean; can_download:boolean; can_request_access:boolean; can_manage_policy:boolean; can_manage_lifecycle:boolean; can_archive:boolean; is_favorite?:boolean;
    access_request_status:AccessRequestStatus|null; access_request_level:AccessLevel|null; access_expires_at?:string|null; source_items?:MediaSourceItem[]; source_health_status?:string; watermark_enabled_effective?:boolean; watermark_text?:string;
}

export interface MediaAccessRequest {
    id:number; media_file_id:number; user_id:number; access_level:AccessLevel; reason:string; status:AccessRequestStatus;
    decision_note:string|null; decided_by:number|null; decided_at:string|null; expires_at?:string|null; revoked_at?:string|null; revoke_reason?:string|null; can_review?:boolean; created_at:string; updated_at:string;
    media_file?:MediaFile; user?:User; decider?:User|null;
}
export interface PortalNotification {
    id:number; user_id:number; type:string; title:string; message:string; data?:Record<string,unknown>|null; read_at:string|null; created_at:string;
}


export interface SavedSearch { id:number; name:string; filters:Record<string,unknown>; created_at:string; updated_at:string; }
export interface FilterOwner { id:number; name:string; department_id:number|null; }
export interface QuickViewCounts { favorites:number; recent:number; }

export interface Stats { total_files:number; total_size:number; by_type:{video:number;image:number;audio:number;document:number}; }

export interface UserGuideAccess { can_view:boolean; allowed_pages:number; total_pages:number; document_url:string|null; html_url:string|null; }
export interface UserGuidePage { number:number; title:string; html:string; }
export interface UserGuideData { allowed_pages:number; total_pages:number; pages:UserGuidePage[]; document_url:string|null; html_url:string; }
export interface ToastItem { id:string; message:string; type:'success'|'error'|'info'; }
export interface PaginatedResult<T> { data:T[]; current_page:number; last_page:number; per_page:number; total:number; from:number|null; to:number|null; }
export interface SharedAppearance { assignments:FontAssignments; families:FontFamily[]; }
export interface BrandingSettings { portal_name:string; login_text:string; default_language:'en'|'gu'|string; logo_path?:string|null; logo_url:string; }
export interface SharedProps { auth:{user:User|null}; appearance:SharedAppearance; branding:BrandingSettings; locale:'en'|'gu'|string; flash?:{success?:string;warning?:string}; }
export interface DashboardProps {
    categories:Category[]; departments:Department[]; appVersion:AppVersion; files?:PaginatedResult<MediaFile>;
    users:User[]; organizationUnits:OrganizationUnit[]; permissionSets:PermissionSet[]; portalRoles:PortalRole[]; userGroups:UserGroup[]; pageAccess:Record<PageAccessKey,boolean>; networkPolicySummary:NetworkPolicySummary; approvalDelegations:ApprovalDelegation[]; securityAuthSettings:SecurityAuthSettings; accessMaturitySettings:AccessMaturitySettings; fontFamilies:FontFamily[]; appearanceSettings:FontAssignments; searchSettings:SearchSettings; masterData:MasterDataMap; metadataSettings:MetadataSettings; notificationSettings:NotificationChannelSettings; notificationPreferences:NotificationPreferences; notificationEscalation:NotificationEscalationSettings; uploadSettings:UploadSettings; integrationConnections:IntegrationConnection[]; sourceConnections:SourceConnection[]; integrationHealthSummary:IntegrationHealthSummary; backupSettings:BackupSettings; backupRuns:BackupRunRow[]; restoreVerifications:RestoreVerificationRow[]; backupStatus:BackupStatusSummary; readinessData:ReadinessData; featureCompletionSettings?:Record<string,Record<string,unknown>>; userGuideAccess:UserGuideAccess;
    stats?:Stats; currentUser:User; simulatedRole:UserRole; accessRequests:MediaAccessRequest[]; notifications:PortalNotification[]; unreadNotifications:number;
    filterOwners:FilterOwner[]; savedSearches:SavedSearch[]; quickViewCounts:QuickViewCounts;
    filters:{ search?:string; scope?:'everywhere'|'current_department'; type?:string; sort?:string; department_id?:number|null; category_id?:number|null; subcategory_id?:number|null; access_policy?:AccessPolicy|null; year?:number|null; country_id?:number|null; state_id?:number|null; city_id?:number|null; mandir_id?:number|null; event_id?:number|null; person_id?:number|null; language_id?:number|null; media_type_id?:number|null; asset_status?:string|null; source_type?:string|null; uploaded_by?:number|null; date_from?:string|null; date_to?:string|null; quick_view?:'favorites'|'recent'|null; panel?:'browse'|'upload'|'access'|'settings'|'notifications'|'reports'|'integrations'|'guide'; page?:number; };
}
