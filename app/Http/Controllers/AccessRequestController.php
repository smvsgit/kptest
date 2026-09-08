<?php

namespace App\Http\Controllers;

use App\Models\MediaAccessRequest;
use App\Models\MediaFile;
use App\Models\ApprovalDelegation;
use App\Models\User;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\MediaAccessService;
use App\Services\NotificationDeliveryService;
use Illuminate\Http\Request;

class AccessRequestController extends Controller
{
    public function store(Request $request, MediaFile $mediaFile, MediaAccessService $access, AuditService $audit, NotificationDeliveryService $notifications)
    {
        abort_unless($access->canSeeMetadata($mediaFile, $request->user()), 404);
        abort_unless($mediaFile->access_policy === 'protected', 422, 'Access requests are only available for protected files.');

        $data = $request->validate([
            'reason' => 'required|string|min:5|max:2000',
            'access_level' => 'required|in:view,download',
        ]);

        if ($data['access_level'] === 'download' && !$mediaFile->download_allowed) {
            return response()->json(['message' => 'Download requests are disabled for this file. Request view-only access instead.'], 422);
        }
        if ($access->canPreview($mediaFile, $request->user()) && !($data['access_level'] === 'download' && !$access->canDownload($mediaFile, $request->user()))) {
            return response()->json(['message' => 'You already have the requested access to this file.'], 422);
        }

        $existing = $mediaFile->accessRequests()
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['pending','approved'])
            ->where(function($q){$q->where('status','pending')->orWhere(function($a){$a->where('status','approved')->whereNull('revoked_at')->where(function($e){$e->whereNull('expires_at')->orWhere('expires_at','>',now());});});})
            ->latest('id')->first();
        if ($existing && $existing->status === 'pending') {
            return response()->json(['message' => 'An access request is already pending.'], 422);
        }
        if ($existing && $existing->status === 'approved' && !($existing->access_level === 'view' && $data['access_level'] === 'download')) {
            return response()->json(['message' => 'Access is already approved.'], 422);
        }

        $accessRequest = MediaAccessRequest::create([
            'media_file_id' => $mediaFile->id,
            'user_id' => $request->user()->id,
            'access_level' => $data['access_level'],
            'reason' => trim($data['reason']),
            'status' => 'pending',
        ]);

        $admins=$this->reviewersFor($mediaFile);

        foreach ($admins as $admin) {
            $notifications->sendEvent(
                $admin,
                'access_request_created',
                'Protected file access requested',
                $request->user()->name.' requested '.($data['access_level'] === 'download' ? 'view + download' : 'view-only').' access to '.$mediaFile->name.'.',
                ['access_request_id' => $accessRequest->id, 'media_file_id' => $mediaFile->id]
            );
        }

        $audit->log($request, 'access.requested', $accessRequest, 'Protected media access requested.', [
            'media_file_id' => $mediaFile->id,
            'access_level' => $data['access_level'],
            'reason' => trim($data['reason']),
        ]);

        return response()->json(['message' => 'Access request submitted.', 'request_id' => $accessRequest->id], 201);
    }

    public function update(Request $request, MediaAccessRequest $accessRequest, MediaAccessService $access, AuditService $audit, NotificationDeliveryService $notifications)
    {
        $accessRequest->loadMissing(['mediaFile.department', 'user']);
        abort_unless($access->canReviewRequest($accessRequest, $request->user()), 403);
        abort_unless($accessRequest->status === 'pending', 422, 'Only pending requests can be reviewed.');

        $data = $request->validate([
            'status' => 'required|in:approved,rejected,more-info',
            'decision_note' => 'nullable|string|max:2000',
            'access_level' => 'nullable|in:view,download',
            'expiry_hours' => 'nullable|integer|min:0|max:8760',
        ]);

        if ($data['status'] !== 'approved' && blank($data['decision_note'] ?? null)) {
            return response()->json(['message' => 'A reason/note is required for Reject or More Information.'], 422);
        }

        $level = $data['access_level'] ?? $accessRequest->access_level;
        if ($level === 'download' && !$accessRequest->mediaFile->download_allowed) {
            return response()->json(['message' => 'Download access is disabled for this file.'], 422);
        }

        $before = ['status'=>$accessRequest->status,'access_level'=>$accessRequest->access_level,'expires_at'=>$accessRequest->expires_at?->toIso8601String()];
        $maturity=SystemSetting::valueFor('access.maturity',[]);
        $hours=array_key_exists('expiry_hours',$data)?(int)$data['expiry_hours']:(int)($maturity['default_expiry_hours']??0);
        $delegation=$access->activeDelegationFor($accessRequest,$request->user());
        $accessRequest->update([
            'status'=>$data['status'],'access_level'=>$level,'decision_note'=>$data['decision_note']??null,
            'decided_by'=>$request->user()->id,'decided_at'=>now(),
            'expires_at'=>$data['status']==='approved'&&$hours>0?now()->addHours($hours):null,
            'revoked_at'=>null,'revoke_reason'=>null,'reviewed_via_delegation_id'=>$delegation?->id,
        ]);

        $label = match ($data['status']) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            default => 'needs more information',
        };

        $notifications->sendEvent(
            $accessRequest->user,
            'access_request_decided',
            'Access request '.$label,
            'Your request for '.$accessRequest->mediaFile->name.' '.$label.'.'.(($data['decision_note'] ?? null) ? ' Note: '.$data['decision_note'] : ''),
            ['access_request_id' => $accessRequest->id, 'media_file_id' => $accessRequest->media_file_id, 'status' => $data['status']]
        );

        $audit->log($request, 'access.'.$data['status'], $accessRequest, 'Protected media access request reviewed.', [
            'before' => $before,
            'after'=>['status'=>$data['status'],'access_level'=>$level,'expires_at'=>$accessRequest->expires_at?->toIso8601String(),'delegation_id'=>$delegation?->id],
            'decision_note' => $data['decision_note'] ?? null,
        ]);

        return response()->json(['message' => 'Access request updated.']);
    }

    public function storeBulk(Request $request, MediaAccessService $access, AuditService $audit, NotificationDeliveryService $notifications)
    {
        $settings=SystemSetting::valueFor('access.maturity',[]); $max=max(1,(int)($settings['max_bulk_request_files']??100));
        $data=$request->validate(['ids'=>'nullable|array','ids.*'=>'integer','event_id'=>'nullable|integer','category_id'=>'nullable|integer','subcategory_id'=>'nullable|integer','reason'=>'required|string|min:5|max:2000','access_level'=>'required|in:view,download']);
        $query=MediaFile::query()->where('access_policy','protected'); $access->applyVisibility($query,$request->user());
        if(!empty($data['ids']))$query->whereIn('id',array_unique($data['ids']));
        elseif(!empty($data['event_id']))$query->where('event_id',$data['event_id']);
        elseif(!empty($data['subcategory_id']))$query->where('subcategory_id',$data['subcategory_id']);
        elseif(!empty($data['category_id']))$query->where('category_id',$data['category_id']);
        else return response()->json(['message'=>'Select files, Event, Category or Sub-category for the bulk request.'],422);
        $files=$query->limit($max+1)->get(); if($files->count()>$max)return response()->json(['message'=>"Bulk request exceeds configured maximum of {$max} files."],422);
        $created=0;$skipped=0;
        foreach($files as $media){
            if(!$access->canRequest($media,$request->user())||($data['access_level']==='download'&&!$media->download_allowed)){$skipped++;continue;}
            $ar=MediaAccessRequest::create(['media_file_id'=>$media->id,'user_id'=>$request->user()->id,'access_level'=>$data['access_level'],'reason'=>trim($data['reason']),'status'=>'pending']);$created++;
            $admins=$this->reviewersFor($media);
            foreach($admins as $admin)$notifications->sendEvent($admin,'access_request_created','Bulk protected access requested',$request->user()->name.' requested access to '.$media->name.'.',['access_request_id'=>$ar->id,'media_file_id'=>$media->id]);
            $audit->log($request,'access.bulk-requested',$ar,'Bulk protected media access requested.',['media_file_id'=>$media->id]);
        }
        return response()->json(['message'=>"Created {$created} access request(s); skipped {$skipped}.",'created'=>$created,'skipped'=>$skipped],201);
    }

    public function resubmit(Request $request, MediaAccessRequest $accessRequest, AuditService $audit, NotificationDeliveryService $notifications)
    {
        abort_unless($accessRequest->user_id === $request->user()->id, 403);
        abort_unless(in_array($accessRequest->status, ['more-info', 'rejected'], true), 422, 'This request cannot be resubmitted.');
        $accessRequest->loadMissing('mediaFile');

        $data = $request->validate([
            'reason' => 'required|string|min:5|max:2000',
            'access_level' => 'required|in:view,download',
        ]);
        if ($data['access_level'] === 'download' && !$accessRequest->mediaFile->download_allowed) {
            return response()->json(['message' => 'Download requests are disabled for this file.'], 422);
        }

        $accessRequest->update([
            'reason' => trim($data['reason']),
            'access_level' => $data['access_level'],
            'status' => 'pending',
            'decision_note' => null,
            'decided_by' => null,
            'decided_at' => null,
        ]);

        $admins=$this->reviewersFor($accessRequest->mediaFile);
        foreach ($admins as $admin) {
            $notifications->sendEvent($admin, 'access_request_created', 'Access request resubmitted', $request->user()->name.' resubmitted access request for '.$accessRequest->mediaFile->name.'.', ['access_request_id' => $accessRequest->id]);
        }

        $audit->log($request, 'access.resubmitted', $accessRequest, 'Access request resubmitted with additional information.');
        return response()->json(['message' => 'Access request resubmitted.']);
    }
    private function reviewersFor(MediaFile $media): \Illuminate\Support\Collection
    {
        $ids=User::query()->where('status','active')->where(function($q)use($media){$q->where('role','super-admin');if($media->department_id)$q->orWhere(fn($d)=>$d->where('role','department-admin')->where('department_id',$media->department_id));})->pluck('id');
        if($media->department_id){$delegates=ApprovalDelegation::activeNow()->where('department_id',$media->department_id)->pluck('delegate_user_id');$ids=$ids->merge($delegates);}
        return User::whereIn('id',$ids->unique()->values())->where('status','active')->get();
    }

}
