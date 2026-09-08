<?php
namespace App\Http\Controllers;

use App\Models\MasterDataValue;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MasterDataController extends Controller
{
    private const TYPES=['country','state','city','mandir','event','person','language','media_type'];
    public function store(Request $request, AuditService $audit)
    {
        $data=$request->validate(['type'=>['required',Rule::in(self::TYPES)],'name'=>'required|string|max:180','code'=>'nullable|string|max:80','parent_id'=>'nullable|exists:master_data_values,id','aliases'=>'nullable|array','aliases.*'=>'string|max:180','is_active'=>'nullable|boolean']);
        abort_if(MasterDataValue::where('type',$data['type'])->whereRaw('LOWER(name)=LOWER(?)',[$data['name']])->exists(),422,'A master value with this name already exists for the selected type.');
        if(!empty($data['parent_id'])){$parent=MasterDataValue::findOrFail($data['parent_id']);$valid=match($data['type']){'state'=>$parent->type==='country','city'=>$parent->type==='state','mandir'=>$parent->type==='city',default=>false};abort_unless($valid,422,'Invalid parent type for this master value.');}elseif(in_array($data['type'],['state','city','mandir'],true))abort(422,'Parent is required for State, City and Mandir master values.');
        $row=MasterDataValue::create($data+['is_active'=>$request->boolean('is_active',true)]);$audit->log($request,'master-data.created',$row,'Master data value created.',['after'=>$row->only(['type','name','code','parent_id','aliases','is_active'])]);return back()->with('success','Master value created.');
    }
    public function update(Request $request, MasterDataValue $masterDataValue, AuditService $audit)
    {
        $data=$request->validate(['name'=>'required|string|max:180','code'=>'nullable|string|max:80','parent_id'=>'nullable|exists:master_data_values,id','aliases'=>'nullable|array','aliases.*'=>'string|max:180','is_active'=>'required|boolean','sort_order'=>'nullable|integer|min:0|max:100000']);
        abort_if(MasterDataValue::where('type',$masterDataValue->type)->where('id','!=',$masterDataValue->id)->whereRaw('LOWER(name)=LOWER(?)',[$data['name']])->exists(),422,'A master value with this name already exists for the selected type.');
        if(!empty($data['parent_id'])){$parent=MasterDataValue::findOrFail($data['parent_id']);$valid=match($masterDataValue->type){'state'=>$parent->type==='country','city'=>$parent->type==='state','mandir'=>$parent->type==='city',default=>false};abort_unless($valid,422,'Invalid parent type for this master value.');}
        $before=$masterDataValue->only(array_keys($data));$masterDataValue->update($data);$audit->log($request,'master-data.updated',$masterDataValue,'Master data value updated.',['before'=>$before,'after'=>$data]);return back()->with('success','Master value updated.');
    }
    public function destroy(Request $request, MasterDataValue $masterDataValue, AuditService $audit)
    {
        $used=\App\Models\MediaFile::where(function($q)use($masterDataValue){foreach(['country_id','state_id','city_id','mandir_id','event_id','person_id','language_id','media_type_id']as$col)$q->orWhere($col,$masterDataValue->id);})->exists();abort_if($used,422,'This master value is already used by media. Deactivate it instead of deleting it.');abort_if($masterDataValue->children()->exists(),422,'This master value has child values. Remove or reassign them first.');$audit->log($request,'master-data.deleted',$masterDataValue,'Master data value deleted.',['before'=>$masterDataValue->only(['type','name','code','parent_id','aliases','is_active'])]);$masterDataValue->delete();return back()->with('success','Master value deleted.');
    }
}
