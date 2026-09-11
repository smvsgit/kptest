<!DOCTYPE html>
@php
    $savedUiTheme = auth()->user()?->ui_theme ?? 'smvs-dark';
    $themeFamilies = ['smvs','slack','google','ocean','royal','forest','rose','amber'];
    $initialThemeMode = str_ends_with($savedUiTheme, '-light') ? 'light' : 'dark';
    $initialThemeFamily = preg_replace('/-(light|dark)$/', '', $savedUiTheme);
    if (! in_array($initialThemeFamily, $themeFamilies, true)) {
        $initialThemeFamily = in_array($savedUiTheme, ['smvs','slack','google'], true) ? $savedUiTheme : 'smvs';
    }
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $initialThemeMode }}" data-color-theme="{{ $initialThemeFamily }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name', 'SMVS Storage') }}</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
