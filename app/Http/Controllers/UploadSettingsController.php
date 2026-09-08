<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\MediaAccessService;
use App\Services\UploadPolicyService;
use Illuminate\Http\Request;

class UploadSettingsController extends Controller
{
    public function update(Request $request, UploadPolicyService $policy, AuditService $audit)
    {
        $data = $request->validate([
            'allowed_extensions' => 'required|array|min:1|max:100',
            'allowed_extensions.*' => ['required','string','max:12','regex:/^[a-z0-9]+$/'],
            'max_file_size_mb' => 'required|integer|min:0|max:1048576',
            'max_batch_count' => 'required|integer|min:0|max:10000',
            'chunk_threshold_mb' => 'required|integer|min:5|max:102400',
            'chunk_size_mb' => 'required|integer|min:5|max:20',
            'retry_count' => 'required|integer|min:1|max:10',
            'exact_duplicate_detection' => 'required|boolean',
            'possible_duplicate_warning' => 'required|boolean',
        ]);
        $data['allowed_extensions'] = array_values(array_unique(array_map('strtolower', $data['allowed_extensions'])));
        $before = $policy->settings();
        SystemSetting::put('upload.settings', $data);
        $audit->log($request, 'settings.upload.updated', null, 'Upload and integrity settings updated.', ['before'=>$before,'after'=>$data]);
        return back()->with('success', 'Upload & Integrity settings saved.');
    }

    public function preflight(Request $request, UploadPolicyService $policy, MediaAccessService $access)
    {
        $data = $request->validate([
            'files' => 'required|array|min:1|max:10000',
            'files.*.name' => 'required|string|max:255',
            'files.*.size' => 'required|integer|min:0',
        ]);
        $settings = $policy->settings();
        $maxBatch = max(0, (int)($settings['max_batch_count'] ?? 0));
        if ($maxBatch > 0 && count($data['files']) > $maxBatch) {
            return response()->json(['ok'=>false,'message'=>"Batch exceeds the configured {$maxBatch}-file limit.",'files'=>[]], 422);
        }
        $results=[]; $hasError=false;
        foreach($data['files'] as $file){
            $errors=[];
            try{$policy->validateFile($file['name'], (int)$file['size']);}catch(\Illuminate\Validation\ValidationException $e){$errors=array_values($e->errors()['file']??['Upload policy validation failed.']);$hasError=true;}
            $dupes=$errors?[]:$policy->possibleDuplicates($request->user(),$file['name'],(int)$file['size'],$access);
            $results[]=['name'=>$file['name'],'size'=>(int)$file['size'],'errors'=>$errors,'possible_duplicates'=>$dupes];
        }
        return response()->json(['ok'=>!$hasError,'settings'=>$settings,'files'=>$results]);
    }
}
