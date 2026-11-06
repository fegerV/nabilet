<?php
// Создать HTTP-сессию администратора через прямой POST-логин (Filament), 
// сохранив cookie: используется для проверки, что админка открывается.
use Illuminate\Support\Facades\Auth;
use App\Models\User;

$user = User::where('email', 'admin@nabilet.local')->first();
if (!$user) { fwrite(STDERR, "no user\n"); exit(1); }

// эмулируем Filament login: session()->regenerate(); auth()->login
session()->regenerate();
Auth::guard('web')->login($user);

$cookie = cookie('nabilet_admin_' . Str::random(16), (string) $user->id, 60);
echo "LOGGED_IN=" . $user->id . "\n";
