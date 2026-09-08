<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Department;
use App\Models\MediaFile;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UploadBulkIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_configure_upload_policy(): void
    {
        $admin=User::factory()->create(['role'=>'super-admin']);
        $this->actingAs($admin)->patchJson('/settings/upload',[
            'allowed_extensions'=>['mp4','jpg','pdf'],'max_file_size_mb'=>500,'max_batch_count'=>50,
            'chunk_threshold_mb'=>40,'chunk_size_mb'=>10,'retry_count'=>4,
            'exact_duplicate_detection'=>true,'possible_duplicate_warning'=>true,
        ])->assertRedirect();
        $this->assertSame(500,SystemSetting::valueFor('upload.settings')['max_file_size_mb']);
    }

    public function test_preflight_hides_private_possible_duplicates_from_other_department(): void
    {
        $a=Department::create(['name'=>'A','is_active'=>true,'is_system'=>false]);
        $b=Department::create(['name'=>'B','is_active'=>true,'is_system'=>false]);
        $user=User::factory()->create(['role'=>'department-operator','department_id'=>$a->id]);
        MediaFile::create(['name'=>'secret.mp4','type'=>'video','size'=>1234,'department_id'=>$b->id,'access_policy'=>'private','download_allowed'=>true,'tags'=>[]]);
        $this->actingAs($user)->postJson('/files/upload-preflight',['files'=>[['name'=>'secret.mp4','size'=>1234]]])
            ->assertOk()->assertJsonPath('files.0.possible_duplicates',[]);
    }

    public function test_department_operator_bulk_edit_is_scoped_to_own_department(): void
    {
        $a=Department::create(['name'=>'A','is_active'=>true,'is_system'=>false]);
        $b=Department::create(['name'=>'B','is_active'=>true,'is_system'=>false]);
        $user=User::factory()->create(['role'=>'department-operator','department_id'=>$a->id]);
        $own=MediaFile::create(['name'=>'own.pdf','type'=>'document','size'=>10,'department_id'=>$a->id,'access_policy'=>'public','download_allowed'=>true,'tags'=>[]]);
        $other=MediaFile::create(['name'=>'other.pdf','type'=>'document','size'=>10,'department_id'=>$b->id,'access_policy'=>'public','download_allowed'=>true,'tags'=>[]]);
        $this->actingAs($user)->patchJson('/files/bulk-edit',['ids'=>[$own->id,$other->id],'access_policy'=>'protected'])->assertForbidden();
    }

    public function test_super_admin_can_bulk_transfer_department_ownership(): void
    {
        $a=Department::create(['name'=>'A','is_active'=>true,'is_system'=>false]);
        $b=Department::create(['name'=>'B','is_active'=>true,'is_system'=>false]);
        $admin=User::factory()->create(['role'=>'super-admin','department_id'=>$a->id]);
        $file=MediaFile::create(['name'=>'x.pdf','type'=>'document','size'=>10,'department_id'=>$a->id,'access_policy'=>'public','download_allowed'=>true,'tags'=>[]]);
        $this->actingAs($admin)->patchJson('/files/bulk-edit',['ids'=>[$file->id],'department_id'=>$b->id])->assertOk();
        $this->assertDatabaseHas('media_files',['id'=>$file->id,'department_id'=>$b->id]);
    }
}
