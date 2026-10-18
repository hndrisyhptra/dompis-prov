<?php

use App\Http\Controllers\AreaDashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BranchDashboardController;
use App\Http\Controllers\BranchMappingController;
use App\Http\Controllers\BranchProgressController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\WorkOrderController;
use App\Http\Middleware\ActiveUser;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(! auth()->check() ? 'login' : (auth()->user()->role->areaScope() ? 'area.dashboard' : 'branch.dashboard')));
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});
Route::middleware(['auth', ActiveUser::class])->group(function () {
    Route::get('/dashboard', [AreaDashboardController::class, 'index'])->middleware('can:area-dashboard')->name('area.dashboard');
    Route::get('/branch/dashboard', [BranchDashboardController::class, 'index'])->name('branch.dashboard');
    Route::get('/branch/material', [BranchDashboardController::class, 'material'])->name('branch.material');
    Route::post('/issues/{issue}/notes', [BranchProgressController::class, 'addIssueNote'])->name('issues.notes');
    Route::resource('users', UserManagementController::class)->only(['index', 'create', 'store', 'edit', 'update'])->middleware('can:administer');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/work-orders', [WorkOrderController::class, 'index'])->name('work-orders.index');
    Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show'])->name('work-orders.show');
    Route::patch('/work-orders/{workOrder}/progress', [BranchProgressController::class, 'update'])->name('work-orders.progress');
    Route::post('/work-orders/{workOrder}/issues', [BranchProgressController::class, 'storeIssue'])->name('work-orders.issues');
    Route::patch('/issues/{issue}/resolve', [BranchProgressController::class, 'resolveIssue'])->name('issues.resolve');
    Route::get('/sync', [SyncController::class, 'index'])->name('sync.index');
    Route::post('/sync/bima/preview', [SyncController::class, 'preview'])->middleware('throttle:6,1')->name('sync.preview');
    Route::post('/sync/monitoring/push', [SyncController::class, 'push'])->middleware('throttle:6,1')->name('sync.push');
    Route::get('/sync/jobs/{syncJob}', [SyncController::class, 'show'])->name('sync.show');
    Route::post('/sync/jobs/{syncJob}/commit', [SyncController::class, 'commit'])->name('sync.commit');
    Route::post('/sync/jobs/{syncJob}/retry', [SyncController::class, 'retry'])->middleware('throttle:6,1')->name('sync.retry');
    Route::get('/sync/jobs/{syncJob}/exceptions', [SyncController::class, 'errors'])->name('sync.errors');
    Route::get('/branch-mappings', [BranchMappingController::class, 'index'])->name('mappings.index');
    Route::post('/branches', [BranchMappingController::class, 'storeBranch'])->name('branches.store');
    Route::post('/branch-mappings', [BranchMappingController::class, 'store'])->name('mappings.store');
});
