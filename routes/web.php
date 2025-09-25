<?php

use Illuminate\Support\Facades\Route;

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

use App\Http\Controllers\BidController;
use App\Http\Controllers\AuthController;

// Auth
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Protected
Route::middleware('auth')->group(function () {
    Route::get('/', [BidController::class, 'index'])->name('bids.index');
    Route::post('/bids', [BidController::class, 'store'])->name('bids.store');
    Route::get('/bids/{bid}', [BidController::class, 'show'])->name('bids.show');
});
