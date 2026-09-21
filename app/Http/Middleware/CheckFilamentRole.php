<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class CheckFilamentRole { public function handle(Request $request, Closure $next, string $role): mixed { $user = Auth::user(); if (!$user || !method_exists($user,'hasRole') || !$user->hasRole($role)) { abort(403,'Access denied: '.$role.' role required.'); } return $next($request); } }