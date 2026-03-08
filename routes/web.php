<?php

use Illuminate\Support\Facades\Route;

// Minimal home (no welcome view) so / works in Docker/Coolify even if session or view fails
Route::get('/', function () {
    return response()->view('worker-home', [], 200)
        ->header('Content-Type', 'text/html; charset=UTF-8');
});
