<?php

use App\Http\Controllers\WidgetController;
use App\Http\Middleware\ResolveWidgetBot;
use Illuminate\Support\Facades\Route;

/*
| Called by the website widget from other people's sites. No session, no
| cookies: every request is identified only by the bot's public key.
*/
Route::prefix('api/widget/{publicKey}')
    ->middleware(ResolveWidgetBot::class)
    ->group(function () {
        Route::get('config', [WidgetController::class, 'config']);
        Route::post('chat', [WidgetController::class, 'chat']);
        Route::get('conversations/{conversation}/messages', [WidgetController::class, 'messages'])
            ->whereUuid('conversation');

        // Browsers ask permission first when calling from another site.
        Route::options('{any}', fn () => response()->noContent())->where('any', '.*');
    });
