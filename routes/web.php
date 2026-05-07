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
use App\Http\Controllers\BidUrlController;

// Auth
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Protected
Route::middleware('auth')->group(function () {
    Route::get('/', [BidController::class, 'index'])->name('bids.index');
    Route::post('/bids', [BidController::class, 'store'])->name('bids.store');
    Route::get('/bids/{bid}', [BidController::class, 'show'])->name('bids.show');
    Route::put('/bids/{bid}', [BidController::class, 'update'])->name('bids.update');
    Route::delete('/bids/{bid}', [BidController::class, 'destroy'])->name('bids.destroy');
    Route::post('/bidurl/scrape-all', [BidController::class, 'scrapeAll'])->name('bidurl.scrapeAll');
    Route::get('/scrape-stream', [BidController::class, 'scrapeStream'])->name('bidurl.scrapeStream');
    Route::get('/scrape-url-stream', [BidController::class, 'scrapeUrlStream'])->name('bids.scrapeUrlStream');
    Route::get('/issues', [BidController::class, 'issues'])->name('scrape.issues');
    Route::delete('/issues', [BidController::class, 'clearIssues'])->name('scrape.clearIssues');
    Route::delete('/issues/{scrapeLog}', [BidController::class, 'destroyIssue'])->name('scrape.destroyIssue');

    Route::get('/bidurl/upload', [BidUrlController::class, 'create'])->name('bidurl.create');
    Route::post('/bidurl/upload', [BidUrlController::class, 'store'])->name('bidurl.store');
    Route::post('/bidurl', [BidUrlController::class, 'storeSingle'])->name('bidurl.storeSingle');
    Route::get('/bidurl', [BidUrlController::class, 'index'])->name('bidurl.index');
    Route::get('/bidurl/{bidUrl}', [BidUrlController::class, 'show'])->name('bidurl.show');
    Route::put('/bidurl/{bidUrl}', [BidUrlController::class, 'update'])->name('bidurl.update');
    Route::delete('/bidurl/{bidUrl}', [BidUrlController::class, 'destroy'])->name('bidurl.destroy');
    Route::post('/failed-bidurl/{failedBidUrl}/restore', [BidUrlController::class, 'restoreFailed'])->name('failed-bidurl.restore');
    Route::delete('/failed-bidurl/{failedBidUrl}', [BidUrlController::class, 'destroyFailed'])->name('failed-bidurl.destroy');
});
