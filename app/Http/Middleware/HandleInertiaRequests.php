<?php

namespace App\Http\Middleware;

use App\Models\FontFamily;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string { return parent::version($request); }

    public function share(Request $request): array
    {
        $assignments=['global'=>'hind-vadodara','gujarati'=>'hind-vadodara','hindi'=>'noto-sans-devanagari','english'=>'inter'];
        $selected=[];
        if (Schema::hasTable('system_settings') && Schema::hasTable('font_families')) {
            $assignments=SystemSetting::valueFor('appearance.fonts',$assignments);
            $slugs=array_values(array_unique(array_filter($assignments)));
            $selected=FontFamily::with('files')->whereIn('slug',$slugs)->get();
        }

        return [
            ...parent::share($request),
            'auth'=>['user'=>$request->user()],
            'flash'=>[
                'success'=>fn()=>session('success'),
                'warning'=>fn()=>session('warning'),
            ],
            'appearance'=>[
                'assignments'=>$assignments,
                'families'=>$selected,
            ],
            'branding'=>Schema::hasTable('system_settings') ? SystemSetting::valueFor('branding.settings',['portal_name'=>'Karyalay Portal','login_text'=>'Secure office media and document portal','default_language'=>'en','logo_path'=>null]) : ['portal_name'=>'Karyalay Portal','login_text'=>'Secure office media and document portal','default_language'=>'en','logo_path'=>null],
            'locale'=>$request->user()?->preferred_language ?? (Schema::hasTable('system_settings') ? (SystemSetting::valueFor('branding.settings',['default_language'=>'en'])['default_language']??'en') : 'en'),
        ];
    }
}
