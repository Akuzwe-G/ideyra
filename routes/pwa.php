<?php

use App\Http\Controllers\FoundationController;
use Illuminate\Support\Facades\Route;

Route::get('/manifest.webmanifest', [FoundationController::class, 'manifest'])->name('manifest');
Route::get('/sw.js', [FoundationController::class, 'worker'])->name('worker');
Route::get('/offline.html', [FoundationController::class, 'offline'])->name('offline');
