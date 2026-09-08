<?php

namespace App\Http\Controllers;

use App\Models\FontFamily;
use App\Models\FontFile;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FontController extends Controller
{
    public function upload(Request $request, AuditService $audit)
    {
        $data = $request->validate([
            'family_name' => 'required|string|max:120',
            'weight' => 'required|integer|in:100,200,300,400,500,600,700,800,900',
            'style' => 'required|in:normal,italic',
            'scripts' => 'nullable|array',
            'scripts.*' => 'in:latin,gujarati,devanagari',
            'font' => 'required|file|max:8192',
        ]);

        $fontUpload = $request->file('font');
        $ext = strtolower($fontUpload->getClientOriginalExtension());
        if (!in_array($ext, ['woff2', 'woff', 'ttf', 'otf'], true)) {
            return back()->withErrors(['font' => 'Only WOFF2, WOFF, TTF and OTF font files are allowed.']);
        }
        $head = file_get_contents($fontUpload->getRealPath(), false, null, 0, 4) ?: '';
        $validMagic = in_array($head, ["wOFF", "wOF2", "OTTO"], true) || $head === "\x00\x01\x00\x00";
        if (!$validMagic) {
            return back()->withErrors(['font' => 'The uploaded file does not have a recognized font signature.']);
        }

        $family = FontFamily::query()->whereRaw('LOWER(name) = ?', [strtolower($data['family_name'])])->first();
        if ($family?->is_system) {
            return back()->withErrors(['family_name' => 'System font families cannot be overwritten. Use a different custom family name.']);
        }
        if (!$family) {
            $family = FontFamily::create([
                'name' => $data['family_name'],
                'slug' => FontFamily::uniqueSlug($data['family_name']),
                'css_family' => $data['family_name'],
                'source' => 'custom',
                'scripts' => $data['scripts'] ?? ['latin'],
                'is_system' => false,
                'is_active' => true,
            ]);
        }

        else {
            $family->update([
                'scripts' => array_values(array_unique(array_merge($family->scripts ?? [], $data['scripts'] ?? []))),
            ]);
        }

        $file = $request->file('font');
        $path = $file->storeAs('custom-fonts/'.$family->slug, $data['weight'].'-'.$data['style'].'-'.uniqid().'.'.$ext, 'public');

        $existing = $family->files()->where('weight', $data['weight'])->where('style', $data['style'])->first();
        if ($existing) {
            Storage::disk('public')->delete($existing->path);
            $existing->update(['path' => $path, 'original_name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType()]);
        } else {
            $family->files()->create(['weight' => $data['weight'], 'style' => $data['style'], 'path' => $path, 'original_name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType()]);
        }

        $audit->log($request,'font.uploaded',$family,'Custom font uploaded.',['family'=>$family->name,'weight'=>$data['weight'],'style'=>$data['style'],'original_name'=>$file->getClientOriginalName()]);
        return back()->with('success', 'Custom font uploaded successfully.');
    }


    public function setStatus(FontFamily $fontFamily, Request $request, AuditService $audit)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);
        if (!$data['is_active']) {
            $assignments = \App\Models\SystemSetting::valueFor('appearance.fonts', []);
            if (in_array($fontFamily->slug, array_values($assignments), true)) {
                return back()->withErrors(['font' => 'This font is currently assigned. Select another font before deactivating it.']);
            }
        }
        $before=$fontFamily->is_active;$fontFamily->update(['is_active' => $data['is_active']]);
        $audit->log($request,'font.status.updated',$fontFamily,'Font status updated.',['before'=>$before,'after'=>$data['is_active']]);
        return back()->with('success', $data['is_active'] ? 'Font activated.' : 'Font deactivated.');
    }

    public function destroy(Request $request, FontFamily $fontFamily, AuditService $audit)
    {
        abort_if($fontFamily->is_system, 422, 'System fonts cannot be deleted.');
        $assignments = \App\Models\SystemSetting::valueFor('appearance.fonts', []);
        abort_if(in_array($fontFamily->slug, array_values($assignments), true), 422, 'This font is currently assigned. Select another font before deleting it.');
        $audit->log($request,'font.deleted',$fontFamily,'Custom font deleted.',['name'=>$fontFamily->name]);
        foreach ($fontFamily->files as $file) Storage::disk('public')->delete($file->path);
        $fontFamily->delete();
        return back();
    }

    public function asset(FontFile $fontFile)
    {
        abort_unless(Storage::disk('public')->exists($fontFile->path), 404);
        return Storage::disk('public')->response($fontFile->path, $fontFile->original_name, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Content-Type' => $fontFile->mime ?: 'application/octet-stream',
        ]);
    }
}
