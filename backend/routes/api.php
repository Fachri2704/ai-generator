<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LandingGenerateController;
use App\Http\Controllers\GeminiDebugController;
use App\Http\Controllers\AuthController;

Route::post('/generate', [LandingGenerateController::class, 'generate']);
Route::post('/generate-company-profile', [LandingGenerateController::class, 'generateCompanyProfile']);
Route::get('/models', [GeminiDebugController::class, 'models']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);
