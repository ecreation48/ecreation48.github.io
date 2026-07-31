<?php
use App\Http\Controllers\Api\V1\BotController;
use Illuminate\Support\Facades\Route;
Route::prefix('v1/internal')->middleware(['service','throttle:120,1'])->group(function (): void {
    Route::get('/bots', [BotController::class, 'index']);
    Route::get('/bots/{discordBot}/credentials', [BotController::class, 'credentials']);
    Route::post('/bots/{discordBot}/heartbeat', [BotController::class, 'heartbeat']);
});
