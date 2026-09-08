<?php

namespace App\Http\Controllers;

use App\Models\FontFamily;
use App\Models\SystemSetting;
use App\Services\AuditService;
use Illuminate\Http\Request;

class AppearanceSettingsController extends Controller
{
    public function update(Request $request, AuditService $audit)
    {
        $data=$request->validate(['global'=>'required|string|exists:font_families,slug','english'=>'required|string|exists:font_families,slug','hindi'=>'required|string|exists:font_families,slug','gujarati'=>'required|string|exists:font_families,slug']);
        foreach($data as $slug)abort_unless(FontFamily::query()->where('slug',$slug)->where('is_active',true)->exists(),422,'Selected font is inactive.');
        $before=SystemSetting::valueFor('appearance.fonts',[]);SystemSetting::put('appearance.fonts',$data);
        $audit->log($request,'settings.appearance.updated',null,'Font assignments updated.',['before'=>$before,'after'=>$data]);
        return back()->with('success','Font assignments applied.');
    }
}
