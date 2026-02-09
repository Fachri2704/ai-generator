<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LandingGenerateController;
use App\Http\Controllers\GeminiDebugController;

Route::post('/generate', [LandingGenerateController::class, 'generate']);
Route::get('/models', [GeminiDebugController::class, 'models']);