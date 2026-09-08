<?php

namespace Tests\Feature;

use App\Models\MasterDataValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetadataMasterDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_hierarchical_master_data(): void
    {
        $user = User::factory()->create(['role'=>'super-admin']);
        $country = MasterDataValue::create(['type'=>'country','name'=>'India','code'=>'IN','is_active'=>true]);
        $this->actingAs($user)->postJson('/settings/master-data', [
            'type'=>'state','name'=>'Gujarat','code'=>'GJ','parent_id'=>$country->id,'aliases'=>['ગુજરાત'],'is_active'=>true,
        ])->assertRedirect();
        $this->assertDatabaseHas('master_data_values',['type'=>'state','name'=>'Gujarat','parent_id'=>$country->id]);
    }

    public function test_non_super_admin_cannot_manage_master_data(): void
    {
        $user = User::factory()->create(['role'=>'department-admin']);
        $this->actingAs($user)->postJson('/settings/master-data', ['type'=>'event','name'=>'Test Event'])->assertForbidden();
    }

    public function test_metadata_rules_are_super_admin_configurable(): void
    {
        $user = User::factory()->create(['role'=>'super-admin']);
        $this->actingAs($user)->patchJson('/settings/metadata', [
            'required_fields'=>['year','event_id','city_id','language_id'],
            'person_required'=>false,'allow_free_tags'=>true,'years_min'=>2000,'years_max'=>2030,
        ])->assertRedirect();
        $this->assertDatabaseHas('system_settings',['key'=>'metadata.settings']);
    }
}
