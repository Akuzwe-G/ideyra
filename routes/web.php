<?php

use App\Http\Controllers\FoundationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::get('/preview/{layout}', [FoundationController::class, 'preview'])
    ->whereIn('layout', ['auth', 'personal', 'institution', 'components'])->name('preview');
