<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class EnsurePermission { public function handle(Request $request,Closure $next,string $permission):mixed { $user=$request->user(); if(!$user||($user->status??'active')!=='active'||!$user->hasPermission($permission))abort(403,'Your role/permission set does not allow this action.'); return $next($request); } }
