<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
 */

Route::prefix('telegrambot')->group(function () {
    Route::get('/', 'TelegramBotController@test');
});

Route::prefix('telegram')->group(function () {
    //https://micalme.com/telegram/bot/GutoTradeBot
    //https://micalme.com/telegram/bot/ZentroTraderBot
    Route::post('/bot/{botname}/{instance?}', 'TelegramBotController@handle')->name('telegram-webhook');

});
