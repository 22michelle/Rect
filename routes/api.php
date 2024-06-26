<?php

use App\Http\Controllers\RegisterController;
use App\Http\Controllers\TransactionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');*/

Route::post('/transfer', [TransactionController::class, 'transfer']);
Route::post('/register', [RegisterController::class, 'register']);
Route::post('/find/{id}', [RegisterController::class, 'find']);
Route::post('/all', [TransactionController::class, 'all']);
Route::post('/users', [RegisterController::class, 'list']);
Route::post('/reset', [RegisterController::class, 'reset']);
Route::post('/adjust-metabalances', [TransactionController::class, 'adjustAllMetabalances']);

