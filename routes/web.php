<?php

use App\Http\Controllers\AcademicController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\FoundationController;
use App\Http\Controllers\IdentityController;
use App\Http\Controllers\InstitutionController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\SystemController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
foreach (['about', 'features', 'for-students', 'for-institutions', 'contact'] as $page) {
    Route::view('/'.$page, 'public.'.$page)->name($page);
}
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:contact')->name('contact.store');
Route::get('/apply', [ApplicationController::class, 'index'])->name('apply');
Route::get('/apply/{type}', [ApplicationController::class, 'form'])->whereIn('type', ['institution', 'student'])->name('apply.form');
Route::post('/apply/{type}', [ApplicationController::class, 'store'])->whereIn('type', ['institution', 'student'])->middleware('throttle:applications')->name('apply.store');
Route::get('/applications/{application}/receipt', [ApplicationController::class, 'receipt'])->middleware('signed')->name('applications.receipt');
Route::get('/applications/{application}/pdf', [ApplicationController::class, 'pdf'])->middleware('signed')->name('applications.pdf');

Route::middleware('guest')->group(function (): void {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.store');
    Route::get('/forgot-password', [IdentityController::class, 'forgot'])->name('password.request');
    Route::post('/forgot-password', [IdentityController::class, 'forgot'])->middleware('throttle:identity-links')->name('password.email');
    Route::get('/reset-password/{token}', [IdentityController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [IdentityController::class, 'reset'])->middleware('throttle:identity-links')->name('password.update');
});
Route::get('/activate/{invitation}', [IdentityController::class, 'activation'])->middleware(['signed', 'throttle:identity-links'])->name('activation.show');
Route::post('/activate/{invitation}', [IdentityController::class, 'accept'])->middleware(['signed', 'throttle:identity-links'])->name('activation.accept');
Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', [IdentityController::class, 'dashboard'])->name('dashboard');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/profile', [IdentityController::class, 'profile'])->name('profile');
    Route::post('/profile', [IdentityController::class, 'profile'])->name('profile.update');
    Route::get('/institutions/{institution}/workspace', [IdentityController::class, 'workspace'])->name('institution.workspace');
    Route::get('/institutions/{institution}/profile', [InstitutionController::class, 'edit'])->name('institutions.edit');
    Route::put('/institutions/{institution}/profile', [InstitutionController::class, 'update'])->name('institutions.update');
    Route::get('/institutions/{institution}/logo', [InstitutionController::class, 'logoFile'])->name('institutions.logo');
    Route::get('/institutions/{institution}/members', [MembershipController::class, 'index'])->name('members.index');
    Route::post('/institutions/{institution}/members', [MembershipController::class, 'store'])->name('members.store');
    Route::get('/institutions/{institution}/members/{member}', [MembershipController::class, 'edit'])->name('members.edit');
    Route::put('/institutions/{institution}/members/{member}', [MembershipController::class, 'update'])->name('members.update');
    Route::post('/institutions/{institution}/members/{member}/permissions', [MembershipController::class, 'permissions'])->name('members.permissions');
    Route::post('/institutions/{institution}/members/{member}/invitation', [MembershipController::class, 'resend'])->name('members.resend');
    Route::get('/institutions/{institution}/audit', [SystemController::class, 'institutionAudit'])->name('institution.audit');
    Route::prefix('/institutions/{institution}/academic/{type}')->whereIn('type', ['years', 'intakes', 'programmes', 'classes'])->group(function (): void {
        Route::get('/', [AcademicController::class, 'index'])->name('academic.index');
        Route::post('/', [AcademicController::class, 'save'])->name('academic.store');
        Route::get('/{record}/edit', [AcademicController::class, 'edit'])->whereNumber('record')->name('academic.edit');
        Route::put('/{record}', [AcademicController::class, 'save'])->whereNumber('record')->name('academic.update');
    });
    Route::get('/institutions/{institution}/classes/{class}/assignments', [AcademicController::class, 'assignments'])->name('classes.assignments');
    Route::post('/institutions/{institution}/classes/{class}/assignments', [AcademicController::class, 'assign'])->name('classes.assign');
    Route::delete('/institutions/{institution}/classes/{class}/assignments/{assignment}', [AcademicController::class, 'unassign'])->name('classes.unassign');
});
Route::middleware(['auth', 'can:system-access'])->prefix('system')->group(function (): void {
    Route::get('/', [SystemController::class, 'dashboard'])->name('system.dashboard');
    Route::get('/applications', [SystemController::class, 'applications'])->name('system.applications');
    Route::get('/applications/{application}', [SystemController::class, 'application'])->name('system.applications.show');
    Route::post('/applications/{application}/review', [SystemController::class, 'review'])->name('system.applications.review');
    Route::get('/applications/{application}/pdf', [ApplicationController::class, 'pdf'])->name('system.applications.pdf');
    Route::get('/institutions', [InstitutionController::class, 'index'])->name('institutions.index');
    Route::get('/institutions/create', [InstitutionController::class, 'create'])->name('institutions.create');
    Route::post('/institutions', [InstitutionController::class, 'store'])->name('institutions.store');
    Route::post('/institutions/{institution}/status', [InstitutionController::class, 'status'])->name('institutions.status');
    Route::get('/roles', [SystemController::class, 'roles'])->name('system.roles');
    Route::post('/roles', [SystemController::class, 'saveRole'])->name('system.roles.store');
    Route::put('/roles/{role}', [SystemController::class, 'saveRole'])->name('system.roles.update');
    Route::post('/permissions', [SystemController::class, 'savePermission'])->name('system.permissions.store');
    Route::put('/permissions/{permission}', [SystemController::class, 'updatePermission'])->name('system.permissions.update');
    Route::get('/audit', [SystemController::class, 'audit'])->name('system.audit');
    Route::get('/settings', [SystemController::class, 'settings'])->name('system.settings');
    Route::post('/settings', [SystemController::class, 'settings'])->name('system.settings.update');
});
Route::get('/preview/{layout}', [FoundationController::class, 'preview'])
    ->whereIn('layout', ['auth', 'personal', 'institution', 'components'])->name('preview');
