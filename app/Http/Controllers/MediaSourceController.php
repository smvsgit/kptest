<?php
namespace App\Http\Controllers;
use App\Models\IntegrationConnection;
use App\Models\Department;
use App\Models\Subcategory;
use App\Models\SystemSetting;
use App\Models\MediaFile;
use App\Models\MediaSource;
use App\Services\AuditService;
use App\Services\IntegrationHealthService;
use App\Services\MediaAccessService;
use App\Services\SearchIndexService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
class MediaSourceController extends Controller {
 public function createReference(Request $request, AuditService $audit, SearchIndexService $searchIndex)
 {
  abort_unless($request->user()->canUpload(),403);
  $d=$request->validate([
   'name'=>'required|string|max:255','type'=>['required',Rule::in(['video','image','audio','document'])],
   'category_id'=>'required|integer|exists:categories,id','subcategory_id'=>'nullable|integer|exists:subcategories,id',
   'department_id'=>'nullable|integer|exists:departments,id','access_policy'=>['required',Rule::in(['public','protected','private'])],
   'download_allowed'=>'nullable|boolean','source_type'=>['required',Rule::in(['local','nas','google-drive','youtube'])],
   'integration_connection_id'=>'nullable|integer|exists:integration_connections,id','locator'=>'required|string|max:4000','label'=>'nullable|string|max:160',
   'year'=>'nullable|integer|min:1800|max:2500','description'=>'nullable|string|max:5000',
   'country_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','country')->where('is_active',true)],
   'state_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','state')->where('is_active',true)],
   'city_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','city')->where('is_active',true)],
   'mandir_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','mandir')->where('is_active',true)],
   'event_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','event')->where('is_active',true)],
   'person_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','person')->where('is_active',true)],
   'language_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','language')->where('is_active',true)],
   'media_type_id'=>['nullable',Rule::exists('master_data_values','id')->where('type','media_type')->where('is_active',true)],
  ]);
  $meta=SystemSetting::valueFor('metadata.settings',[]);$required=$meta['required_fields']??[];if(($meta['person_required']??false)&&!in_array('person_id',$required,true))$required[]='person_id';
  $labels=['year'=>'Year','country_id'=>'Country','state_id'=>'State','city_id'=>'City','mandir_id'=>'Mandir','event_id'=>'Event','person_id'=>'Person','language_id'=>'Language','media_type_id'=>'Media Type','description'=>'Description'];$missing=[];foreach($required as $field)if(!$request->filled($field))$missing[]=$labels[$field]??$field;if($missing)abort(422,'Required metadata missing: '.implode(', ',$missing));
  $min=(int)($meta['years_min']??1950);$max=(int)($meta['years_max']??((int)date('Y')+2));if($request->filled('year')&&($request->integer('year')<$min||$request->integer('year')>$max))abort(422,"Year must be between {$min} and {$max}.");
  foreach([['state_id','country_id'],['city_id','state_id'],['mandir_id','city_id']] as [$child,$parent])if($request->filled($child)&&$request->filled($parent)){ $row=\App\Models\MasterDataValue::find($request->input($child));if(!$row||(int)$row->parent_id!==(int)$request->input($parent))abort(422,ucfirst(str_replace('_id','',$child)).' does not belong to selected '.str_replace('_id','',$parent).'.'); }
  if(!empty($d['subcategory_id'])){ $sub=Subcategory::findOrFail($d['subcategory_id']); abort_if($sub->category_id!==(int)$d['category_id'],422,'Sub-category does not belong to selected category.'); }
  if($request->user()->role!=='super-admin'&&!empty($d['department_id'])&&(int)$d['department_id']!==(int)$request->user()->department_id)abort(403,'Only Super Admin can create a reference asset in another department.');
  $this->validateConnection(['type'=>$d['source_type'],'integration_connection_id'=>$d['integration_connection_id']??null]);
  $departmentId=$request->user()->role==='super-admin'&& !empty($d['department_id']) ? (int)$d['department_id'] : ($request->user()->department_id ?? Department::where('is_system',true)->value('id'));
  $media=MediaFile::create(['name'=>$d['name'],'type'=>$d['type'],'size'=>0,'category_id'=>$d['category_id'],'subcategory_id'=>$d['subcategory_id']??null,'department_id'=>$departmentId,'year'=>$d['year']??null,'country_id'=>$d['country_id']??null,'state_id'=>$d['state_id']??null,'city_id'=>$d['city_id']??null,'mandir_id'=>$d['mandir_id']??null,'event_id'=>$d['event_id']??null,'person_id'=>$d['person_id']??null,'language_id'=>$d['language_id']??null,'media_type_id'=>$d['media_type_id']??null,'description'=>$d['description']??null,'source_type'=>$d['source_type'],'asset_status'=>'active','access_policy'=>$d['access_policy'],'download_allowed'=>$request->boolean('download_allowed',true),'tags'=>[],'uploaded_by'=>$request->user()->id,'owner_user_id'=>$request->user()->id,'processing_status'=>'ready']);
  $source=$media->sources()->create(['integration_connection_id'=>$d['integration_connection_id']??null,'type'=>$d['source_type'],'label'=>$d['label']?:'Primary external source','locator'=>$d['locator'],'is_primary'=>true,'is_enabled'=>true,'status'=>'active']);
  $searchIndex->upsert($media->fresh(['category','subcategory','department','uploader','country','state','city','mandir','event','person','language','mediaType','sources']));
  $audit->log($request,'media.reference-created',$media,'External/reference media asset created.',['source_id'=>$source->id,'source_type'=>$source->type,'connection_id'=>$source->integration_connection_id]);
  return response()->json(['message'=>'Reference asset created.','id'=>$media->id],201);
 }
 public function store(Request $request,MediaFile $mediaFile,MediaAccessService $access,AuditService $audit){abort_unless($access->canManageLifecycle($mediaFile,$request->user()),403);$d=$this->validateSource($request);$this->validateConnection($d);if($request->boolean('is_primary'))$mediaFile->sources()->update(['is_primary'=>false]);$isPrimary=$request->boolean('is_primary');$s=$mediaFile->sources()->create($d+['is_primary'=>$isPrimary,'is_enabled'=>$request->boolean('is_enabled',true),'status'=>$request->boolean('is_enabled',true)?'active':'inactive']);if($isPrimary)$mediaFile->update(['source_type'=>$s->type]);$audit->log($request,'media.source-added',$mediaFile,'Media source added.',['source_id'=>$s->id,'type'=>$s->type,'connection_id'=>$s->integration_connection_id]);return response()->json(['message'=>'Source added.','source'=>$s],201);}
 public function update(Request $request,MediaFile $mediaFile,MediaSource $source,MediaAccessService $access,AuditService $audit){$this->belongs($mediaFile,$source);abort_unless($access->canManageLifecycle($mediaFile,$request->user()),403);$d=$this->validateSource($request);$this->validateConnection($d);if($request->boolean('is_primary'))$mediaFile->sources()->whereKeyNot($source->id)->update(['is_primary'=>false]);$before=$source->only(['type','label','locator','external_id','status','integration_connection_id']);$isPrimary=$request->boolean('is_primary');$source->update($d+['is_primary'=>$isPrimary,'is_enabled'=>$request->boolean('is_enabled',true),'status'=>$request->boolean('is_enabled',true)?($source->status==='inactive'?'active':$source->status):'inactive','repair_note'=>$request->input('repair_note')]);if($isPrimary)$mediaFile->update(['source_type'=>$source->type]);$audit->log($request,'media.source-repaired',$mediaFile,'Media source reference updated.',['source_id'=>$source->id,'before'=>$before,'after'=>$source->only(array_keys($before))]);return response()->json(['message'=>'Source reference updated.']);}
 public function destroy(Request $request,MediaFile $mediaFile,MediaSource $source,MediaAccessService $access,AuditService $audit){$this->belongs($mediaFile,$source);abort_unless($access->canManageLifecycle($mediaFile,$request->user()),403);abort_if($mediaFile->sources()->count()<=1,422,'A logical asset must keep at least one source.');$wasPrimary=$source->is_primary;$audit->log($request,'media.source-removed',$mediaFile,'Media source removed.',['source_id'=>$source->id,'type'=>$source->type]);$source->delete();if($wasPrimary&&($next=$mediaFile->sources()->first())){$next->update(['is_primary'=>true]);$mediaFile->update(['source_type'=>$next->type]);}return response()->json(['message'=>'Source removed.']);}
 public function check(Request $request,MediaFile $mediaFile,MediaSource $source,MediaAccessService $access,IntegrationHealthService $health,AuditService $audit){$this->belongs($mediaFile,$source);abort_unless($access->canSeeMetadata($mediaFile,$request->user()),404);$r=$health->checkSource($source);$audit->log($request,'media.source-checked',$mediaFile,'Media source health check executed.',['source_id'=>$source->id]+$r);return response()->json($r);}
 public function deliver(Request $request,MediaFile $mediaFile,MediaSource $source,MediaAccessService $access){$this->belongs($mediaFile,$source);abort_unless($access->canPreview($mediaFile,$request->user()),403);if(in_array($source->type,['youtube','google-drive'],true)&&$source->open_url)return redirect()->away($source->open_url);if($source->type==='local'){abort_unless(Storage::disk('media')->exists($source->locator),404);return Storage::disk('media')->response($source->locator,$mediaFile->name);}$root=$source->connection?->root_path;abort_unless($source->type==='nas'&&$root,404);$rootReal=realpath($root);abort_unless($rootReal!==false,404);$rel=ltrim(str_replace(chr(0),'',$source->locator),'/\\');abort_if(str_contains($rel,'../')||str_contains($rel,'..\\'),403,'Unsafe NAS source path.');$path=realpath(rtrim($rootReal,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$rel);abort_unless($path!==false&&($path===$rootReal||str_starts_with($path,rtrim($rootReal,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))&&is_file($path)&&is_readable($path),404);return response()->file($path);}
 private function belongs(MediaFile $m,MediaSource $s):void{abort_unless($s->media_file_id===$m->id,404);}
 private function validateSource(Request $r):array{return $r->validate(['type'=>['required',Rule::in(['local','nas','google-drive','youtube'])],'label'=>'nullable|string|max:160','locator'=>'required|string|max:4000','external_id'=>'nullable|string|max:255','integration_connection_id'=>'nullable|integer|exists:integration_connections,id','is_primary'=>'nullable|boolean','is_enabled'=>'nullable|boolean','repair_note'=>'nullable|string|max:2000']);}
 private function validateConnection(array $d):void{if(in_array($d['type'],['nas','google-drive'],true))abort_if(empty($d['integration_connection_id']),422,'This source type requires an integration connection.');if(!empty($d['integration_connection_id'])){$c=IntegrationConnection::find($d['integration_connection_id']);abort_unless($c&&$c->type===$d['type'],422,'Selected connection type does not match source type.');}}
}
