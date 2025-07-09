<?php

use App\Http\Controllers\Admin\BenefitController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CourseController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TransactionController as TransactionControllerGlobal;
use App\Http\Controllers\UserController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\User\CourseController as UserCourseController;
use App\Http\Controllers\User\CategoryController as UserCategoryController;
use App\Http\Middleware\AdminOnly;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/login-admin', [AuthController::class, 'loginAdmin']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/payment_url', [TransactionControllerGlobal::class, 'createTransaction']);
Route::post('/notification', [TransactionControllerGlobal::class, 'callbackMidtrans']);
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

Route::middleware('auth:sanctum')->get('/users', [UserController::class, 'index']);

Route::get('/auth/redirect/google', [GoogleAuthController::class, 'redirect']);
Route::get('/auth/callback/google', [GoogleAuthController::class, 'callback']);

Route::middleware(['auth:sanctum', AdminOnly::class])->prefix('admin')->group(function () {
    Route::apiResource('users', AdminUserController::class);
//    Route::post('/courses', [CourseController::class, 'store']);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('benefits', BenefitController::class);
    Route::apiResource('courses', CourseController::class);
    Route::post('courses/{course}/publish', [CourseController::class, 'publish']);
    Route::get('courses/slug/{slug}', [CourseController::class, 'showBySlug']);
    Route::get('courses/title/{title}', [CourseController::class, 'showByTitle']);
    Route::get('dashboard', [DashboardController::class, 'index']);
});

Route::prefix('user')->group(function () {
    Route::apiResource('courses', UserCourseController::class);
    Route::apiResource('categories', UserCategoryController::class);
});
