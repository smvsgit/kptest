<?php

namespace App\Services;

use App\Models\MediaFile;
use Illuminate\Support\Facades\Http;
use Throwable;

class SearchIndexService
{
    private function request()
    {
        $request = Http::acceptJson()->timeout(config('search.timeout', 3));
        if ($key = config('search.key')) $request = $request->withToken($key);
        return $request;
    }

    private function url(string $path): string
    {
        return rtrim(config('search.host'), '/').'/'.ltrim($path, '/');
    }

    public function document(MediaFile $media): array
    {
        $media->loadMissing(['category','subcategory','department','uploader','country','state','city','mandir','event','person','language','mediaType','sources']);
        $tags = $media->tags ?? [];
        $metadataAliases = collect([$media->country,$media->state,$media->city,$media->mandir,$media->event,$media->person,$media->language,$media->mediaType])
            ->flatMap(fn ($value) => $value?->aliases ?? [])->filter()->unique()->values()->all();
        $sourceText = trim(implode(' ', array_filter([$media->name, implode(' ', $tags), implode(' ', $metadataAliases), $media->category?->name, $media->subcategory?->name, $media->department?->name, $media->uploader?->name, $media->event?->name, $media->person?->name, $media->country?->name, $media->state?->name, $media->city?->name, $media->mandir?->name, $media->language?->name, $media->mediaType?->name, $media->description])));
        $translit = $sourceText;
        if (class_exists(\Transliterator::class)) {
            $t = \Transliterator::create('Any-Latin; Latin-ASCII');
            if ($t) $translit = $t->transliterate($sourceText);
        }
        return [
            'id'=>$media->id,'name'=>$media->name,'tags'=>$tags,'type'=>$media->type,'size'=>$media->size,
            'department_id'=>$media->department_id,'department'=>$media->department?->name,'category_id'=>$media->category_id,
            'category'=>$media->category?->name,'subcategory_id'=>$media->subcategory_id,'subcategory'=>$media->subcategory?->name,
            'created_at'=>optional($media->created_at)->timestamp,'updated_at'=>optional($media->updated_at)->timestamp,'access_policy'=>$media->access_policy,'search_transliteration'=>$translit,
            'uploaded_by'=>$media->uploaded_by,'uploader'=>$media->uploader?->name,'metadata_aliases'=>$metadataAliases,
            'year'=>$media->year,'country_id'=>$media->country_id,'country'=>$media->country?->name,'state_id'=>$media->state_id,'state'=>$media->state?->name,
            'city_id'=>$media->city_id,'city'=>$media->city?->name,'mandir_id'=>$media->mandir_id,'mandir'=>$media->mandir?->name,
            'event_id'=>$media->event_id,'event'=>$media->event?->name,'person_id'=>$media->person_id,'person'=>$media->person?->name,
            'language_id'=>$media->language_id,'language'=>$media->language?->name,'media_type_id'=>$media->media_type_id,'media_type'=>$media->mediaType?->name,
            'description'=>$media->description,'source_type'=>array_values($media->sources->pluck('type')->push($media->source_type)->filter()->unique()->values()->all()),'source_statuses'=>array_values($media->sources->pluck('status')->filter()->unique()->values()->all()),'asset_status'=>$media->asset_status,
        ];
    }

    public function search(string $query, array $filters = [], int $limit = 1000): array
    {
        try {
            $payload=['q'=>$query,'limit'=>$limit,'attributesToRetrieve'=>['id']];
            $filter=[];
            foreach (['type','department_id','category_id','subcategory_id','access_policy','year','country_id','state_id','city_id','mandir_id','event_id','person_id','language_id','media_type_id','source_type','asset_status','uploaded_by'] as $key) if (!empty($filters[$key])) {
                $value=is_numeric($filters[$key])?(int)$filters[$key]:(string)$filters[$key];
                $filter[]=$key.' = '.(is_int($value)?$value:json_encode($value));
            }
            if (!empty($filters['date_from'])) {
                $ts = strtotime((string) $filters['date_from'].' 00:00:00');
                if ($ts !== false) $filter[] = 'created_at >= '.(int) $ts;
            }
            if (!empty($filters['date_to'])) {
                $ts = strtotime((string) $filters['date_to'].' 23:59:59');
                if ($ts !== false) $filter[] = 'created_at <= '.(int) $ts;
            }
            if (array_key_exists('_visibility_department_id', $filters)) {
                $dept = $filters['_visibility_department_id'];
                $visibility = $dept ? '(access_policy != "private" OR department_id = '.(int)$dept.')' : 'access_policy != "private"';
                $filter[] = $visibility;
            }
            if ($filter) $payload['filter']=$filter;
            $r=$this->request()->post($this->url('indexes/'.config('search.index').'/search'),$payload);
            if (!$r->successful()) return [];
            return array_values(array_filter(array_map(fn($hit)=>(int)($hit['id']??0),$r->json('hits',[]))));
        } catch (Throwable $e) { report($e); return []; }
    }

    public function upsert(MediaFile $media): void
    {
        try { $this->request()->post($this->url('indexes/'.config('search.index').'/documents?primaryKey=id'),[$this->document($media)]); } catch (Throwable $e) { report($e); }
    }

    public function delete(int $id): void
    {
        try { $this->request()->delete($this->url('indexes/'.config('search.index').'/documents/'.$id)); } catch (Throwable $e) { report($e); }
    }

    public function rebuild(): array
    {
        try {
            $clear = $this->request()->delete($this->url('indexes/'.config('search.index').'/documents'));
            if (!$clear->successful() && $clear->status() !== 404) $clear->throw();
            MediaFile::with(['category','subcategory','department','uploader','country','state','city','mandir','event','person','language','mediaType','sources'])->chunkById(250,function($rows){
                $docs=$rows->map(fn($m)=>$this->document($m))->all();
                $this->request()->post($this->url('indexes/'.config('search.index').'/documents?primaryKey=id'),$docs);
            });
            return ['ok'=>true,'message'=>'Search re-index requested for all media files.'];
        } catch (Throwable $e) { report($e); return ['ok'=>false,'message'=>'Re-index failed: '.$e->getMessage()]; }
    }
}
