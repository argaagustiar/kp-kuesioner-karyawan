<?php

use App\Http\Controllers\Api\AttendaceRecordController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\PeriodController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::get('/attendance-records/template/download', [AttendaceRecordController::class, 'downloadTemplate']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth Routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // All Evaluation Status for HR
    Route::get('employees/hr-evaluation-status-all', [EmployeeController::class, 'hrEvaluationStatus']);

    // Resource Routes
    Route::apiResource('employees', EmployeeController::class);
    Route::get('employees/{id}/hr-evaluation-status', [EmployeeController::class, 'hrEvaluationStatusByEmployee']);
    Route::apiResource('departments', DepartmentController::class);
    Route::apiResource('positions', PositionController::class);
    Route::apiResource('periods', PeriodController::class);
    Route::apiResource('evaluations', EvaluationController::class);
    Route::apiResource('attendance-records', AttendaceRecordController::class);
    
    // Attendance Records Import
    Route::post('/attendance-records/import', [AttendaceRecordController::class, 'import']);
    
    // Attendance Records by Period and Employee
    Route::get('/attendance-records/period/{period_id}/employee/{employee_id}', 
        [AttendaceRecordController::class, 'showByPeriodEmployee']);

    // Reports
    Route::get('/reports/evaluation-summary', [EvaluationController::class, 'evaluationSummary']);
    Route::get('/reports/evaluation-summary/export', [EvaluationController::class, 'exportEvaluationSummary']);
    Route::get('/reports/comments-summary', [EvaluationController::class, 'commentsSummary']);
    Route::get('/reports/comments-summary/export', [EvaluationController::class, 'exportCommentsSummary']);
    Route::get('/reports/evaluation-breakdown', [EvaluationController::class, 'evaluationBreakdown']);
    Route::get('/reports/evaluation-breakdown/export', [EvaluationController::class, 'exportEvaluationBreakdown']);
});