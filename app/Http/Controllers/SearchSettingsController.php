<?php
namespace App\Http\Controllers;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\SearchConfigurationService;
use App\Services\SearchIndexService;
use Illuminate\Http\Request;
class SearchSettingsController extends Controller
{
 public function update(Request $request, SearchConfigurationService $service, AuditService $audit){$data=$request->validate([
  'enabled'=>'required|boolean','mode'=>'required|in:meilisearch,hybrid','fuzzy'=>'required|boolean','search_as_you_type'=>'required|boolean','search_ui_enabled'=>'required|boolean','best_match_sort'=>'required|boolean','department_filter'=>'required|boolean','engine_pipeline_enabled'=>'required|boolean','synonyms'=>'required|boolean','aliases'=>'required|boolean','transliteration'=>'required|boolean','filters'=>'required|boolean','exact_fields_enabled'=>'required|boolean','exact_fields'=>'array|max:30','exact_fields.*'=>'string|max:80|regex:/^[A-Za-z0-9_]+$/','synonym_groups'=>'array|max:50','synonym_groups.*'=>'array|max:10','synonym_groups.*.*'=>'string|max:120','alias_groups'=>'array|max:50','alias_groups.*'=>'array|max:10','alias_groups.*.*'=>'string|max:120']);$data['hybrid_semantic_enabled']=false;$before=SystemSetting::valueFor('search.settings',[]);SystemSetting::put('search.settings',$data);$sync=$service->syncToMeilisearch();$audit->log($request,'settings.search.updated',null,'Search settings updated.',['before'=>$before,'after'=>$data,'sync'=>$sync]);return back()->with($sync['ok']?'success':'warning',$sync['message']);}
 public function sync(Request $request, SearchConfigurationService $config, SearchIndexService $index, AuditService $audit){$a=$config->syncToMeilisearch();if(!$a['ok']){$audit->log($request,'search.sync.failed',null,'Search settings sync failed.',$a);return back()->with('warning',$a['message']);}$b=$index->rebuild();$audit->log($request,$b['ok']?'search.rebuild.completed':'search.rebuild.failed',null,'Search index rebuild requested.',['settings_sync'=>$a,'rebuild'=>$b]);return back()->with($b['ok']?'success':'warning',$b['message']);}
}
