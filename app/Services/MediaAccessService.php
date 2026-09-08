<?php

namespace App\Services;

use App\Models\ApprovalDelegation;
use App\Models\MediaAccessRequest;
use App\Models\MediaFile;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;

class MediaAccessService
{
    public function applyVisibility(Builder $query, User $user): Builder
    {
        if ($user->role === 'super-admin') return $query;
        return $query->where(function (Builder $q) use ($user) {
            $q->whereIn('access_policy', ['public', 'protected']);
            if ($user->department_id) {
                $q->orWhere(fn (Builder $private) => $private->where('access_policy', 'private')->where('department_id', $user->department_id));
            }
        });
    }

    public function canSeeMetadata(MediaFile $media, User $user): bool
    {
        if (($user->status ?? 'active') !== 'active') return false;
        if ($user->role === 'super-admin') return true;
        if ($media->access_policy !== 'private') return true;
        return $this->sameDepartment($media, $user);
    }

    public function canPreview(MediaFile $media, User $user): bool
    {
        if (!$this->canSeeMetadata($media, $user)) return false;
        if ($user->role === 'super-admin' || $this->sameDepartment($media, $user)) return true;
        if ($media->access_policy === 'public') return true;
        if ($media->access_policy === 'private') return false;
        return $this->approvedRequest($media, $user) !== null;
    }

    public function canDownload(MediaFile $media, User $user): bool
    {
        if (!$media->download_allowed || !$this->canSeeMetadata($media, $user)) return false;
        if ($user->role === 'super-admin' || $this->sameDepartment($media, $user)) return true;
        if ($media->access_policy === 'public') return true;
        if ($media->access_policy === 'private') return false;
        return $this->approvedRequest($media, $user)?->access_level === 'download';
    }

    public function canRequest(MediaFile $media, User $user): bool
    {
        if (($user->status ?? 'active') !== 'active' || $media->access_policy !== 'protected') return false;
        if ($user->role === 'super-admin' || $this->sameDepartment($media, $user)) return false;
        $latest=$this->latestRequest($media,$user);
        if(!$latest||in_array($latest->status,['rejected','more-info','expired','revoked','cancelled'],true))return true;
        if($latest->status==='approved'&&($latest->revoked_at||($latest->expires_at&&$latest->expires_at->isPast())))return true;
        return $latest->status==='approved'&&$latest->access_level==='view'&&$media->download_allowed;
    }

    public function latestRequest(MediaFile $media, User $user): ?MediaAccessRequest
    {
        if ($media->relationLoaded('accessRequests')) return $media->accessRequests->where('user_id',$user->id)->sortByDesc('id')->first();
        return $media->accessRequests()->where('user_id',$user->id)->latest('id')->first();
    }

    public function approvedRequest(MediaFile $media, User $user): ?MediaAccessRequest
    {
        $valid = fn ($q) => $q->where('user_id',$user->id)->where('status','approved')->whereNull('revoked_at')->where(function($e){$e->whereNull('expires_at')->orWhere('expires_at','>',now());});
        if ($media->relationLoaded('accessRequests')) {
            return $media->accessRequests->where('user_id',$user->id)->where('status','approved')->whereNull('revoked_at')->filter(fn($r)=>!$r->expires_at||$r->expires_at->isFuture())->sortByDesc('id')->first();
        }
        return $valid($media->accessRequests())->latest('id')->first();
    }

    public function presentation(MediaFile $media, User $user): array
    {
        $latest = $this->latestRequest($media, $user);
        $approved = $this->approvedRequest($media, $user);
        return [
            'can_preview'=>$this->canPreview($media,$user),'can_download'=>$this->canDownload($media,$user),'can_request_access'=>$this->canRequest($media,$user),
            'access_request_status'=>$latest?->status,'access_request_level'=>$latest?->access_level,'access_expires_at'=>$approved?->expires_at?->toIso8601String(),
            'temporary_download_url'=>$this->temporaryDownloadUrl($media,$user),
            'can_manage_lifecycle'=>$this->canManageLifecycle($media,$user),'can_archive'=>$this->canArchive($media,$user),
            'watermark_enabled_effective'=>$this->watermarkEnabled($media,$user),'watermark_text'=>SystemSetting::valueFor('watermark.settings',['text'=>'Karyalay Portal'])['text']??'Karyalay Portal',
        ];
    }


    private function watermarkEnabled(MediaFile $media, User $user): bool
    {
        $s=SystemSetting::valueFor('watermark.settings',['enabled'=>false,'protected_only'=>true]);
        if($media->watermark_enabled !== null) return (bool)$media->watermark_enabled;
        if(!($s['enabled']??false)) return false;
        if(($s['protected_only']??true) && $media->access_policy!=='protected') return false;
        return $this->canPreview($media,$user);
    }

    public function temporaryDownloadUrl(MediaFile $media, User $user): ?string
    {
        if ($media->access_policy !== 'protected' || !$this->canDownload($media,$user) || $this->sameDepartment($media,$user) || $user->role==='super-admin') return null;
        $approved=$this->approvedRequest($media,$user); if(!$approved)return null;
        $mins=max(1,(int)(SystemSetting::valueFor('access.maturity',[])['signed_download_minutes']??15));
        $expires=now()->addMinutes($mins); if($approved->expires_at && $approved->expires_at->lt($expires))$expires=$approved->expires_at;
        return URL::temporarySignedRoute('files.approved-download',$expires,['mediaFile'=>$media->id,'request_id'=>$approved->id]);
    }

    public function canManagePolicy(MediaFile $media, User $user): bool { if($user->role==='super-admin')return true; return $user->hasPermission('manage_policy')&&$this->sameDepartment($media,$user); }
    public function canManageLifecycle(MediaFile $media, User $user): bool { if($user->role==='super-admin')return true; return $user->hasPermission('manage_lifecycle')&&$this->sameDepartment($media,$user); }
    public function canUploadVersion(MediaFile $media, User $user): bool { return $this->canManageLifecycle($media,$user); }
    public function canRestoreVersion(MediaFile $media, User $user): bool { if($user->role==='super-admin')return true; return $user->role==='department-admin'&&$user->hasPermission('manage_lifecycle')&&$this->sameDepartment($media,$user); }
    public function canArchive(MediaFile $media, User $user): bool { return $this->canRestoreVersion($media,$user); }
    public function canRestoreDeleted(MediaFile $media, User $user): bool { return $this->canRestoreVersion($media,$user); }

    public function activeDelegationFor(MediaAccessRequest $request, User $user): ?ApprovalDelegation
    {
        if(!$request->mediaFile?->department_id)return null;
        return ApprovalDelegation::activeNow()->where('department_id',$request->mediaFile->department_id)->where('delegate_user_id',$user->id)->first();
    }
    public function canReviewRequest(MediaAccessRequest $request, User $user): bool
    {
        if(($user->status??'active')!=='active')return false;
        if($user->role==='super-admin')return true;
        if($user->hasPermission('review_access')&&$user->department_id&&$request->mediaFile?->department_id===$user->department_id)return true;
        return $this->activeDelegationFor($request,$user)!==null;
    }
    private function sameDepartment(MediaFile $media, User $user): bool { return $user->department_id!==null&&$media->department_id===$user->department_id; }
}
