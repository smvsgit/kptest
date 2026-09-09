<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Department;
use App\Models\MasterDataValue;
use App\Models\MediaFile;
use App\Models\MediaStatusHistory;
use App\Models\Subcategory;
use App\Services\AuditService;
use App\Services\SearchIndexService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BulkMediaController extends Controller
{
    public function update(Request $request, AuditService $audit, SearchIndexService $searchIndex)
    {
        $data=$request->validate([
            'ids'=>'required|array|min:1|max:500','ids.*'=>'integer|distinct',
            'metadata'=>'nullable|array',
            'metadata.year'=>'nullable|integer|min:1800|max:2500',
            'metadata.country_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','country')],
            'metadata.state_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','state')],
            'metadata.city_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','city')],
            'metadata.mandir_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','mandir')],
            'metadata.event_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','event')],
            'metadata.person_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','person')],
            'metadata.language_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','language')],
            'metadata.media_type_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','media_type')],
            'metadata.description'=>'nullable|string|max:5000','metadata.internal_remarks'=>'nullable|string|max:5000',
            'metadata.source_type'=>'nullable|in:local,nas,google-drive,youtube','metadata.asset_status'=>'prohibited',
            'tags_mode'=>'nullable|in:add,remove,replace','tags'=>'nullable|array|max:100','tags.*'=>'string|max:80',
            'category_id'=>'nullable|exists:categories,id','subcategory_id'=>'nullable|exists:subcategories,id',
            'department_id'=>['nullable',Rule::exists('departments','id')->where('is_active',true)->where('is_system',false)],
            'archive_mode'=>'nullable|in:archive,unarchive',
            'access_policy'=>'nullable|in:public,protected,private','download_allowed'=>'nullable|boolean',
        ]);
        $user=$request->user();
        $files=MediaFile::query()->whereIn('id',$data['ids'])->get();
        abort_unless($files->count()===count($data['ids']),404,'One or more files were not found.');
        if($user->role!=='super-admin'){
            abort_unless(in_array($user->role,['department-admin','department-operator'],true),403);
            abort_unless($user->department_id && $files->every(fn($f)=>(int)$f->department_id===(int)$user->department_id),403,'Bulk editing is limited to files owned by your department.');
        }
        if(array_key_exists('department_id',$data) && $data['department_id']!==null) abort_unless($user->role==='super-admin',403,'Only Super Admin can transfer department ownership.');
        if(!empty($data['archive_mode'])) abort_unless(in_array($user->role,['super-admin','department-admin'],true),403,'Department Operator cannot bulk archive/unarchive.');
        if(array_key_exists('subcategory_id',$data) && $data['subcategory_id']){
            $sub=Subcategory::findOrFail($data['subcategory_id']);
            if(!empty($data['category_id'])) abort_unless((int)$sub->category_id===(int)$data['category_id'],422,'Subcategory does not belong to selected category.');
        }
        $changed=[];
        DB::transaction(function() use($files,$data,$request,$audit,&$changed){
            foreach($files as $file){
                $before=$file->only(['year','country_id','state_id','city_id','mandir_id','event_id','person_id','language_id','media_type_id','description','internal_remarks','source_type','asset_status','tags','category_id','subcategory_id','department_id','access_policy','download_allowed','archived_at']);
                $updates=[];
                if(isset($data['metadata'])) $updates=array_merge($updates,$data['metadata']);
                foreach(['category_id','subcategory_id','department_id','access_policy','download_allowed'] as $field) if(array_key_exists($field,$data)) $updates[$field]=$data[$field];
                if(array_key_exists('category_id',$updates) && !array_key_exists('subcategory_id',$updates)) $updates['subcategory_id']=null;
                if(!empty($updates['subcategory_id'])) {
                    $sub=Subcategory::findOrFail($updates['subcategory_id']);
                    $finalCategory=$updates['category_id'] ?? $file->category_id;
                    abort_unless((int)$sub->category_id===(int)$finalCategory,422,'Subcategory does not belong to the target category.');
                }
                // Clearing a parent location always clears descendants to prevent invalid hierarchy.
                if(array_key_exists('country_id',$updates) && !$updates['country_id']) {$updates['state_id']=null;$updates['city_id']=null;$updates['mandir_id']=null;}
                if(array_key_exists('state_id',$updates) && !$updates['state_id']) {$updates['city_id']=null;$updates['mandir_id']=null;}
                if(array_key_exists('city_id',$updates) && !$updates['city_id']) $updates['mandir_id']=null;
                $next=array_merge($file->only(['country_id','state_id','city_id','mandir_id']),array_intersect_key($updates,array_flip(['country_id','state_id','city_id','mandir_id'])));
                $this->validateHierarchy($next);
                if(!empty($data['tags_mode'])){
                    $incoming=array_values(array_unique(array_filter(array_map('trim',$data['tags']??[]))));
                    $current=$file->tags??[];
                    $updates['tags']=match($data['tags_mode']){
                        'replace'=>$incoming,
                        'remove'=>array_values(array_diff($current,$incoming)),
                        default=>array_values(array_unique(array_merge($current,$incoming))),
                    };
                }
                if(($data['archive_mode']??null)==='archive'){
                    if($file->asset_status!=='archived'){$updates['archived_from_status']=$file->asset_status;$updates['asset_status']='archived';$updates['archived_at']=now();$updates['archived_by']=$request->user()->id;}
                }elseif(($data['archive_mode']??null)==='unarchive' && $file->asset_status==='archived'){
                    $updates['asset_status']=$file->archived_from_status ?: 'active';$updates['archived_from_status']=null;$updates['archived_at']=null;$updates['archived_by']=null;
                }
                if($updates){$file->fill($updates);$file->save();$fresh=$file->fresh(['category','subcategory','department','uploader','country','state','city','mandir','event','person','language','mediaType']);if(array_key_exists('asset_status',$updates) && ($before['asset_status']??null)!==$fresh->asset_status){MediaStatusHistory::create(['media_file_id'=>$file->id,'from_status'=>$before['asset_status']??null,'to_status'=>$fresh->asset_status,'note'=>'Bulk archive lifecycle action.','changed_by'=>$request->user()->id]);}$audit->log($request,'media.bulk.updated',$fresh,'Media updated by bulk operation.',['before'=>$before,'after'=>$fresh->only(array_keys($updates))]);$changed[]=$file->id;}
            }
        });
        if($changed){MediaFile::with(['category','subcategory','department','uploader','country','state','city','mandir','event','person','language','mediaType'])->whereIn('id',$changed)->get()->each(fn(MediaFile $media)=>$searchIndex->upsert($media));}
        return response()->json(['message'=>count($changed).' file(s) updated.','updated_ids'=>$changed]);
    }

    private function validateHierarchy(array $ids): void
    {
        foreach([['state_id','country_id'],['city_id','state_id'],['mandir_id','city_id']] as [$child,$parent]){
            if(empty($ids[$child])) continue;
            $row=MasterDataValue::find($ids[$child]);
            abort_unless($row && (int)$row->parent_id===(int)($ids[$parent]??0),422,ucfirst(str_replace('_id','',$child)).' does not belong to selected '.str_replace('_id','',$parent).'.');
        }
    }
}
