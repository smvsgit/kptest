<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\MediaFavorite;
use App\Models\MediaFile;
use App\Models\MediaRecentView;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $deptA = Department::create(['name'=>'Audio Video','is_active'=>true,'is_system'=>false]);
        $deptB = Department::create(['name'=>'Website','is_active'=>true,'is_system'=>false]);
        $user = User::factory()->create(['role'=>'viewer','department_id'=>$deptA->id]);
        $uploader = User::factory()->create(['role'=>'department-operator','department_id'=>$deptA->id]);
        $own = MediaFile::create(['name'=>'Gurupurnima Sabha.mp4','type'=>'video','size'=>100,'department_id'=>$deptA->id,'access_policy'=>'public','download_allowed'=>true,'tags'=>['guru'],'uploaded_by'=>$uploader->id,'year'=>2026,'source_type'=>'local','asset_status'=>'active']);
        $other = MediaFile::create(['name'=>'Website Poster.png','type'=>'image','size'=>20,'department_id'=>$deptB->id,'access_policy'=>'public','download_allowed'=>true,'tags'=>[],'uploaded_by'=>$uploader->id]);
        $private = MediaFile::create(['name'=>'Private Website.mov','type'=>'video','size'=>30,'department_id'=>$deptB->id,'access_policy'=>'private','download_allowed'=>true,'tags'=>[],'uploaded_by'=>$uploader->id]);
        return compact('deptA','deptB','user','uploader','own','other','private');
    }

    public function test_user_can_save_and_delete_own_search(): void
    {
        $f=$this->fixture();
        $this->actingAs($f['user'])->postJson('/searches/saved',['name'=>'2026 Videos','filters'=>['year'=>2026,'type'=>'video','scope'=>'current_department']])->assertOk();
        $saved=SavedSearch::firstOrFail();
        $this->assertSame(2026,$saved->filters['year']);
        $this->actingAs($f['user'])->deleteJson('/searches/saved/'.$saved->id)->assertOk();
        $this->assertDatabaseMissing('saved_searches',['id'=>$saved->id]);
    }

    public function test_user_cannot_delete_another_users_saved_search(): void
    {
        $f=$this->fixture(); $otherUser=User::factory()->create();
        $saved=SavedSearch::create(['user_id'=>$otherUser->id,'name'=>'Secret','filters'=>['search'=>'x']]);
        $this->actingAs($f['user'])->deleteJson('/searches/saved/'.$saved->id)->assertNotFound();
    }

    public function test_favorite_toggle_is_user_specific_and_private_metadata_is_not_exposed(): void
    {
        $f=$this->fixture();
        $this->actingAs($f['user'])->postJson('/files/'.$f['own']->id.'/favorite')->assertOk()->assertJson(['favorite'=>true]);
        $this->assertDatabaseHas('media_favorites',['user_id'=>$f['user']->id,'media_file_id'=>$f['own']->id]);
        $this->actingAs($f['user'])->postJson('/files/'.$f['own']->id.'/favorite')->assertOk()->assertJson(['favorite'=>false]);
        $this->actingAs($f['user'])->postJson('/files/'.$f['private']->id.'/favorite')->assertNotFound();
    }

    public function test_recent_view_tracking_upserts_timestamp(): void
    {
        $f=$this->fixture();
        $this->actingAs($f['user'])->postJson('/files/'.$f['own']->id.'/recent')->assertOk();
        $this->actingAs($f['user'])->postJson('/files/'.$f['own']->id.'/recent')->assertOk();
        $this->assertSame(1,MediaRecentView::where('user_id',$f['user']->id)->where('media_file_id',$f['own']->id)->count());
    }
}
