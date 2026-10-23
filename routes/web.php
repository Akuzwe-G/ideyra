<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\FoundationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
foreach (['about', 'features', 'for-students', 'for-institutions', 'contact'] as $page) {
    Route::view('/'.$page, 'public.'.$page)->name($page);
}
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:contact')->name('contact.store');

Route::middleware('guest')->group(function (): void {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.store');
    Route::view('/register', 'auth.register')->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:registration')->name('register.store');
});
Route::view('/dashboard', 'dashboard')->middleware('auth')->name('dashboard');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::get('/preview/{layout}', [FoundationController::class, 'preview'])
    ->whereIn('layout', ['auth', 'personal', 'institution', 'components'])->name('preview');
