<?php

use Illuminate\Support\Facades\Route;
use Prism\Prism\Http\Controllers\PrismChatController;
use Prism\Prism\Http\Controllers\PrismModelController;

Route::prefix('/prism/openai/v1')
    ->group(function (): void {
        Route::post('/chat/completions', PrismChatController::class);
        Route::get('/models', PrismModelController::class);
    });
