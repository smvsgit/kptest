<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('media');
        $department = Department::create(['name'=>'Audio Video','is_active'=>true,'is_system'=>false]);
        $admin = User::factory()->create(['role'=>'department-admin','department_id'=>$department->id]);
        $super = User::factory()->create(['role'=>'super-admin']);
        Storage::disk('media')->put('uploads/documents/original.pdf', 'version-one');
        $media = MediaFile::create([
            'name'=>'document.pdf','type'=>'document','size'=>11,'department_id'=>$department->id,
            'access_policy'=>'public','download_allowed'=>true,'tags'=>[],'file_path'=>'uploads/documents/original.pdf','uploaded_by'=>$admin->id,
        ]);
        return compact('department','admin','super','media');
    }

    public function test_initial_upload_has_version_one_history(): void
    {
        $f=$this->fixture();
        $this->assertSame(1,$f['media']->versions()->count());
        $this->assertDatabaseHas('media_file_versions',['media_file_id'=>$f['media']->id,'version_number'=>1]);
    }

    public function test_normal_delete_moves_file_to_recycle_bin_without_deleting_bytes(): void
    {
        $f=$this->fixture();
        $this->actingAs($f['admin'])->delete('/files/bulk-delete',['ids'=>[$f['media']->id]])->assertRedirect();
        $this->assertSoftDeleted('media_files',['id'=>$f['media']->id]);
        Storage::disk('media')->assertExists('uploads/documents/original.pdf');
    }

    public function test_department_admin_can_restore_own_recycle_bin_item(): void
    {
        $f=$this->fixture(); $f['media']->delete();
        $this->actingAs($f['admin'])->postJson('/files/recycle-bin/'.$f['media']->id.'/restore')->assertOk();
        $this->assertDatabaseHas('media_files',['id'=>$f['media']->id,'deleted_at'=>null]);
    }

    public function test_only_super_admin_can_permanently_delete_recycled_asset(): void
    {
        $f=$this->fixture(); $f['media']->delete();
        $this->actingAs($f['admin'])->deleteJson('/files/recycle-bin/'.$f['media']->id.'/permanent')->assertForbidden();
        $this->actingAs($f['super'])->deleteJson('/files/recycle-bin/'.$f['media']->id.'/permanent')->assertOk();
        $this->assertDatabaseMissing('media_files',['id'=>$f['media']->id]);
        Storage::disk('media')->assertMissing('uploads/documents/original.pdf');
    }

    public function test_department_admin_can_archive_and_unarchive_own_asset(): void
    {
        $f=$this->fixture();
        $this->actingAs($f['admin'])->patchJson('/files/'.$f['media']->id.'/archive',['archived'=>true])->assertOk();
        $this->assertSame('archived',$f['media']->fresh()->asset_status);
        $this->actingAs($f['admin'])->patchJson('/files/'.$f['media']->id.'/archive',['archived'=>false])->assertOk();
        $this->assertSame('active',$f['media']->fresh()->asset_status);
    }
}
