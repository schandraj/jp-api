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
use App\Http\Controllers\User\UserController as UserUserController;
use App\Http\Middleware\AdminOnly;
use App\Http\Middleware\UserMiddleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/login-admin', [AuthController::class, 'loginAdmin']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/payment_url', [TransactionControllerGlobal::class, 'createTransaction']);
Route::post('/notification', [TransactionControllerGlobal::class, 'callbackMidtrans']);
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
Route::post('/transactions/check-status', [TransactionControllerGlobal::class, 'checkTransactionStatus']);
Route::post('/transactions/update-status', [TransactionControllerGlobal::class, 'updateTransaction']);

Route::middleware('auth:sanctum')->get('/users', [UserController::class, 'index']);

Route::get('/auth/redirect/google', [GoogleAuthController::class, 'redirect']);
Route::get('/auth/callback/google', [GoogleAuthController::class, 'callback']);

Route::get('/reset-password/{token}/{email}', [UserUserController::class, 'redirectToResetPassword']);

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
    Route::apiResource('transactions', \App\Http\Controllers\Admin\TransactionController::class);
});

Route::prefix('user')->group(function () {
    Route::get('/courses', [UserCourseController::class, 'index']);
    Route::get('/courses/{id}', [UserCourseController::class, 'show']);
    Route::get('/courses/login/{id}', [UserCourseController::class, 'showLogin'])->middleware('auth:api');
    Route::get('/courses/cbt/{id}', [UserCourseController::class, 'getCbt'])->middleware(['auth:api', UserMiddleware::class]);
    Route::get('/courses/purchased/all', [UserCourseController::class, 'getPurchasedCoursesByType'])->middleware(['auth:api', UserMiddleware::class]);
    Route::post('/courses/cbt/submit-answer', [UserUserController::class, 'submitAnswers'])->middleware('auth:api');
    Route::apiResource('categories', UserCategoryController::class);
    Route::get('/dashboard', [App\Http\Controllers\User\UserController::class, 'dashboard'])->middleware('auth:api');
    Route::get('/profile', [App\Http\Controllers\User\UserController::class, 'profile'])->middleware('auth:api');
    Route::post('/profile', [App\Http\Controllers\User\UserController::class, 'profileUpdate'])->middleware('auth:api');
    Route::post('/profile-picture', [App\Http\Controllers\User\UserController::class, 'updateProfilePicture'])->middleware('auth:api');
    Route::get('/transactions', [App\Http\Controllers\User\UserController::class, 'transactions'])->middleware('auth:api');
    Route::get('/transactions/{id}', [App\Http\Controllers\User\UserController::class, 'transactionDetails'])->middleware('auth:api');
    Route::post('/change-password', [App\Http\Controllers\User\UserController::class, 'changePassword'])->middleware('auth:api');
    Route::post('/change-email', [App\Http\Controllers\User\UserController::class, 'changeEmail'])->middleware('auth:api');
    Route::post('/send-email', [App\Http\Controllers\User\UserController::class, 'sendEmail']);
    Route::post('/password/reset-link', [App\Http\Controllers\User\UserController::class, 'sendPasswordResetLink']);
    Route::post('/password/reset', [App\Http\Controllers\User\UserController::class, 'resetPassword']);
});

