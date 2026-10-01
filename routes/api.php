<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CollectorManagementController;
use App\Http\Controllers\CustomerCollectionController;
use App\Http\Controllers\ExcelProcessingController;
use App\Http\Controllers\LocationManagementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public Health & Database Status
Route::get('/health', function () {
    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        $dbName = \Illuminate\Support\Facades\DB::connection()->getDatabaseName();
        $userCount = \App\Models\User::count();
        return response()->json([
            'status' => 'connected',
            'database' => $dbName,
            'users_count' => $userCount,
            'server_time' => now()->toIso8601String(),
            'message' => 'Live database connection is active and healthy.'
        ], 200);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => 'disconnected',
            'database' => null,
            'error' => $e->getMessage(),
            'message' => 'Database connection failed.'
        ], 500);
    }
});

Route::get('/system/status', function () {
    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        $dbName = \Illuminate\Support\Facades\DB::connection()->getDatabaseName();
        $userCount = \App\Models\User::count();
        return response()->json([
            'status' => 'connected',
            'database' => $dbName,
            'users_count' => $userCount,
            'server_time' => now()->toIso8601String(),
            'message' => 'Live database connection is active and healthy.'
        ], 200);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => 'disconnected',
            'database' => null,
            'error' => $e->getMessage(),
            'message' => 'Database connection failed.'
        ], 500);
    }
});

// Public Auth Routes
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected Routes (Sanctum Authenticated)
Route::middleware('auth:sanctum')->group(function () {
    // Auth & Profile Management
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::put('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::get('/auth/users', [AuthController::class, 'getAllUsers']);
    Route::put('/auth/users/{id}/role', [AuthController::class, 'updateUserRole']);

    // Excel Engine & History & Interactive Editing
    Route::post('/excel/process', [ExcelProcessingController::class, 'process']);
    Route::get('/excel/history', [ExcelProcessingController::class, 'history']);
    Route::get('/excel/history-analytics', [ExcelProcessingController::class, 'historyAnalytics']);
    Route::get('/excel/history-report-export', [ExcelProcessingController::class, 'exportHistoryReport']);
    Route::get('/excel/target-months', [ExcelProcessingController::class, 'getTargetMonths']);
    Route::post('/excel/target-report', [ExcelProcessingController::class, 'generateCollectorTargetReport']);
    Route::get('/excel/download/{id}', [ExcelProcessingController::class, 'download']);
    Route::delete('/excel/destroy/{id}', [ExcelProcessingController::class, 'destroy']);
    Route::post('/excel/sample-generator', [ExcelProcessingController::class, 'generateSample']);
    
    // Trash & Soft Delete Management
    Route::get('/excel/trash', [ExcelProcessingController::class, 'trash']);
    Route::post('/excel/restore/{id}', [ExcelProcessingController::class, 'restore']);
    Route::delete('/excel/force-delete/{id}', [ExcelProcessingController::class, 'forceDelete']);
    
    // Interactive Cell Data Inspector & Editor & Summaries & Database Customer Search
    Route::get('/excel/file-data/{id}', [ExcelProcessingController::class, 'getFileData']);
    Route::post('/excel/update-data', [ExcelProcessingController::class, 'updateFileData']);
    Route::get('/excel/customer-summary/{id?}', [ExcelProcessingController::class, 'getCustomerSummary']);
    Route::get('/excel/monthly-comparison-report', [ExcelProcessingController::class, 'getMonthlyComparisonReport']);
    Route::get('/excel/export-comparison-report', [ExcelProcessingController::class, 'exportMonthlyComparisonReport']);
    Route::get('/excel/search-customers', [ExcelProcessingController::class, 'searchCustomers']);
    Route::post('/excel/update-customer-record', [ExcelProcessingController::class, 'updateCustomer']);
    Route::get('/excel/lookup-options', [ExcelProcessingController::class, 'getLookupOptions']);
    Route::get('/excel/customer-history/{recordId}', [ExcelProcessingController::class, 'getCustomerHistory']);
    Route::get('/excel/all-history-logs', [ExcelProcessingController::class, 'getAllHistoryLogs']);
    Route::get('/excel/customer-update-months', [ExcelProcessingController::class, 'getCustomerUpdateMonths']);
    Route::get('/excel/customer-update-report', [ExcelProcessingController::class, 'getCustomerUpdateReport']);
    Route::get('/excel/customer-update-export', [ExcelProcessingController::class, 'exportCustomerUpdateReport']);

    // Area CRUD Management
    Route::get('/locations/areas', [LocationManagementController::class, 'indexAreas']);
    Route::post('/locations/areas', [LocationManagementController::class, 'storeArea']);
    Route::put('/locations/areas/{id}', [LocationManagementController::class, 'updateArea']);
    Route::delete('/locations/areas/{id}', [LocationManagementController::class, 'destroyArea']);

    // Building CRUD Management
    Route::get('/locations/buildings', [LocationManagementController::class, 'indexBuildings']);
    Route::post('/locations/buildings', [LocationManagementController::class, 'storeBuilding']);
    Route::put('/locations/buildings/{id}', [LocationManagementController::class, 'updateBuilding']);
    Route::delete('/locations/buildings/{id}', [LocationManagementController::class, 'destroyBuilding']);

    // Collector CRUD & Area Assignment Management
    Route::get('/collectors', [CollectorManagementController::class, 'index']);
    Route::post('/collectors', [CollectorManagementController::class, 'store']);
    Route::put('/collectors/{id}', [CollectorManagementController::class, 'update']);
    Route::delete('/collectors/{id}', [CollectorManagementController::class, 'destroy']);
    Route::post('/collectors/{id}/assign-areas', [CollectorManagementController::class, 'assignAreas']);

    // Customer Collection Management & Excel Import
    Route::get('/collections', [CustomerCollectionController::class, 'index']);
    Route::post('/collections/upload', [CustomerCollectionController::class, 'uploadExcel']);
    Route::get('/collections/months', [CustomerCollectionController::class, 'months']);
    Route::delete('/collections/{id}', [CustomerCollectionController::class, 'destroy']);
    Route::post('/collections/clear-month', [CustomerCollectionController::class, 'clearMonth']);
    Route::get('/collections/sample-template', [CustomerCollectionController::class, 'downloadSampleTemplate']);
    Route::get('/collections/export', [CustomerCollectionController::class, 'export']);
    Route::get('/collections/achievement', [CustomerCollectionController::class, 'getAchievementReport']);
    Route::get('/collections/export-achievement', [CustomerCollectionController::class, 'exportAchievementReport']);
});
