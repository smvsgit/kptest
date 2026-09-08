<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Throwable;

class SearchConfigurationService
{
    public function settings(): array { return SystemSetting::valueFor('search.settings',[]); }
    private function request(){ $r=Http::acceptJson()->timeout(config('search.timeout',3)); if($key=config('search.key'))$r=$r->withToken($key); return $r; }
    private function url(string $path){ return rtrim(config('search.host'),'/').'/'.ltrim($path,'/'); }
    public function syncToMeilisearch(): array
    {
        $s=$this->settings(); $index=config('search.index');
        try {
            $create=$this->request()->post($this->url('indexes'),['uid'=>$index,'primaryKey'=>'id']);
            if (!$create->successful() && $create->status() !== 409) $create->throw();
            $base='indexes/'.$index.'/settings/';
            $searchable=['name','tags','department','uploader','category','subcategory','event','person','country','state','city','mandir','language','media_type','metadata_aliases','description']; if($s['transliteration']??true)$searchable[]='search_transliteration';
            $this->request()->put($this->url($base.'searchable-attributes'),$searchable)->throw();
            $filterable=($s['filters']??true)?['type','department_id','uploaded_by','category_id','subcategory_id','created_at','access_policy','year','country_id','state_id','city_id','mandir_id','event_id','person_id','language_id','media_type_id','source_type','source_statuses','asset_status']:[];
            $this->request()->put($this->url($base.'filterable-attributes'),$filterable)->throw();
            $this->request()->put($this->url($base.'sortable-attributes'),['created_at','updated_at','name','size'])->throw();
            $exactFields = ($s['exact_fields_enabled'] ?? true)
                ? array_values(array_intersect($s['exact_fields'] ?? [], $searchable))
                : [];
            $this->request()->patch($this->url($base.'typo-tolerance'),['enabled'=>(bool)($s['fuzzy']??true),'disableOnAttributes'=>$exactFields])->throw();
            $syn=[];
            foreach (($s['synonyms']??true)?($s['synonym_groups']??[]):[] as $g){$g=array_values(array_filter(array_map('trim',$g)));foreach($g as $w)$syn[$w]=array_values(array_diff($g,[$w]));}
            foreach (($s['aliases']??true)?($s['alias_groups']??[]):[] as $g){$g=array_values(array_filter(array_map('trim',$g)));foreach($g as $w)$syn[$w]=array_values(array_unique(array_merge($syn[$w]??[],array_diff($g,[$w]))));}
            $this->request()->put($this->url($base.'synonyms'),$syn)->throw();
            return ['ok'=>true,'message'=>'Meilisearch settings synchronized.'];
        } catch(Throwable $e){report($e);return ['ok'=>false,'message'=>'Settings saved; Meilisearch sync could not be completed: '.$e->getMessage()];}
    }
}
