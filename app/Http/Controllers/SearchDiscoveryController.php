<?php

namespace App\Http\Controllers;

use App\Models\MediaFavorite;
use App\Models\MediaFile;
use App\Models\MediaRecentView;
use App\Models\SavedSearch;
use App\Services\MediaAccessService;
use Illuminate\Http\Request;

class SearchDiscoveryController extends Controller
{
    private const FILTER_KEYS = [
        'search','scope','type','sort','department_id','category_id','subcategory_id','access_policy',
        'year','country_id','state_id','city_id','mandir_id','event_id','person_id','language_id','media_type_id',
        'asset_status','source_type','uploaded_by','date_from','date_to','quick_view',
    ];

    public function save(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','string','max:120'],
            'filters' => ['required','array'],
        ]);
        $filters = collect($data['filters'])->only(self::FILTER_KEYS)->filter(fn ($value) => $value !== null && $value !== '')->all();
        $saved = SavedSearch::updateOrCreate(
            ['user_id' => $request->user()->id, 'name' => trim($data['name'])],
            ['filters' => $filters]
        );
        return response()->json(['ok' => true, 'saved_search' => $saved]);
    }

    public function destroy(Request $request, SavedSearch $savedSearch)
    {
        abort_unless($savedSearch->user_id === $request->user()->id, 404);
        $savedSearch->delete();
        return response()->json(['ok' => true]);
    }

    public function favorite(Request $request, MediaFile $mediaFile, MediaAccessService $access)
    {
        abort_unless($access->canSeeMetadata($mediaFile, $request->user()), 404);
        $key = ['user_id' => $request->user()->id, 'media_file_id' => $mediaFile->id];
        $existing = MediaFavorite::where($key)->first();
        if ($existing) {
            $existing->delete();
            return response()->json(['ok' => true, 'favorite' => false]);
        }
        MediaFavorite::create($key);
        return response()->json(['ok' => true, 'favorite' => true]);
    }

    public function recent(Request $request, MediaFile $mediaFile, MediaAccessService $access)
    {
        abort_unless($access->canSeeMetadata($mediaFile, $request->user()), 404);
        MediaRecentView::updateOrCreate(
            ['user_id' => $request->user()->id, 'media_file_id' => $mediaFile->id],
            ['viewed_at' => now()]
        );
        return response()->json(['ok' => true]);
    }
}
