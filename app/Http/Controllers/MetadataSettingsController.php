<?php
namespace App\Http\Controllers;
use App\Models\SystemSetting;
use App\Services\AuditService;
use Illuminate\Http\Request;
class MetadataSettingsController extends Controller
{
    public function update(Request $request, AuditService $audit)
    {
        $data=$request->validate(['required_fields'=>'required|array','required_fields.*'=>'in:year,country_id,state_id,city_id,mandir_id,event_id,person_id,language_id,media_type_id,description','person_required'=>'required|boolean','allow_free_tags'=>'required|boolean','years_min'=>'required|integer|min:1800|max:2500','years_max'=>'required|integer|min:1800|max:2500']);
        abort_if($data['years_max']<$data['years_min'],422,'Maximum year must be greater than or equal to minimum year.');
        $before=SystemSetting::valueFor('metadata.settings',[]);SystemSetting::put('metadata.settings',$data);
        $audit->log($request,'settings.metadata.updated',null,'Metadata rules updated.',['before'=>$before,'after'=>$data]);
        return back()->with('success','Metadata settings updated.');
    }
}
