<?php
namespace Tests\Feature;

use App\Models\Department;
use App\Models\IntegrationConnection;
use App\Models\MediaFile;
use App\Models\MediaSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IntegrationStorageHealthTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('media');
        $dept=Department::create(['name'=>'Audio Video','is_active'=>true,'is_system'=>false]);
        $super=User::factory()->create(['role'=>'super-admin','status'=>'active']);
        $admin=User::factory()->create(['role'=>'department-admin','department_id'=>$dept->id,'status'=>'active']);
        $operator=User::factory()->create(['role'=>'department-operator','department_id'=>$dept->id,'status'=>'active']);
        Storage::disk('media')->put('uploads/videos/a.mp4','video-bytes');
        $media=MediaFile::create(['name'=>'a.mp4','type'=>'video','size'=>11,'department_id'=>$dept->id,'access_policy'=>'public','download_allowed'=>true,'tags'=>[],'file_path'=>'uploads/videos/a.mp4','source_type'=>'local','uploaded_by'=>$operator->id,'owner_user_id'=>$operator->id]);
        return compact('dept','super','admin','operator','media');
    }

    public function test_only_super_admin_can_create_integration_and_credentials_are_encrypted(): void
    {
        $f=$this->fixture(); $payload=['name'=>'Private Drive','type'=>'google-drive','is_active'=>true,'root_path'=>null,'base_url'=>null,'api_key'=>'secret-key','sync_frequency_minutes'=>60,'storage_warning_percent'=>90];
        $this->actingAs($f['admin'])->post('/settings/integrations',$payload)->assertForbidden();
        $this->actingAs($f['super'])->post('/settings/integrations',$payload)->assertRedirect();
        $c=IntegrationConnection::where('name','Private Drive')->firstOrFail();
        $this->assertNotSame('secret-key',$c->credentials_encrypted);
        $this->assertSame('secret-key',json_decode(Crypt::decryptString($c->credentials_encrypted),true)['api_key']);
    }

    public function test_department_operator_can_attach_multiple_sources_only_to_own_asset(): void
    {
        $f=$this->fixture();
        $yt=IntegrationConnection::create(['name'=>'YouTube','type'=>'youtube','is_active'=>true,'sync_frequency_minutes'=>60,'storage_warning_percent'=>90,'status'=>'healthy']);
        $this->actingAs($f['operator'])->postJson('/files/'.$f['media']->id.'/sources',['type'=>'youtube','label'=>'Published video','locator'=>'https://www.youtube.com/watch?v=dQw4w9WgXcQ','integration_connection_id'=>$yt->id,'is_primary'=>false,'is_enabled'=>true])->assertCreated();
        $this->assertGreaterThanOrEqual(2,$f['media']->sources()->count());
        $otherDept=Department::create(['name'=>'Website','is_active'=>true,'is_system'=>false]);
        $other=MediaFile::create(['name'=>'other.pdf','type'=>'document','size'=>1,'department_id'=>$otherDept->id,'access_policy'=>'public','download_allowed'=>true,'tags'=>[],'uploaded_by'=>$f['operator']->id]);
        $this->actingAs($f['operator'])->postJson('/files/'.$other->id.'/sources',['type'=>'youtube','locator'=>'https://youtu.be/dQw4w9WgXcQ','integration_connection_id'=>$yt->id])->assertForbidden();
    }

    public function test_source_can_be_disabled_and_local_source_check_detects_missing_file(): void
    {
        $f=$this->fixture(); $source=$f['media']->sources()->firstOrFail();
        $this->actingAs($f['operator'])->patchJson('/files/'.$f['media']->id.'/sources/'.$source->id,['type'=>'local','label'=>'Primary','locator'=>$source->locator,'is_primary'=>true,'is_enabled'=>false])->assertOk();
        $this->assertSame('inactive',$source->fresh()->status);
        $source->update(['is_enabled'=>true,'status'=>'active']); Storage::disk('media')->delete($source->locator);
        $this->actingAs($f['operator'])->postJson('/files/'.$f['media']->id.'/sources/'.$source->id.'/check')->assertOk()->assertJson(['status'=>'missing']);
        $this->assertNotNull($source->fresh()->broken_detected_at);
    }

    public function test_department_admin_health_view_hides_connection_paths(): void
    {
        $f=$this->fixture(); IntegrationConnection::create(['name'=>'NAS','type'=>'nas','is_active'=>true,'root_path'=>'/secret/nas/path','sync_frequency_minutes'=>60,'storage_warning_percent'=>90,'status'=>'unknown']);
        $r=$this->actingAs($f['admin'])->getJson('/integrations/health')->assertOk();
        $nas=collect($r->json('connections'))->firstWhere('name','NAS');
        $this->assertNull($nas['root_path']);
    }
}
