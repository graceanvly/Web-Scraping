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
use App\Http\Controllers\BidUrlManualBidController;
use App\Http\Controllers\PendingBidController;

// Auth
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Protected
Route::middleware('auth')->group(function () {
    Route::get('/', [BidController::class, 'index'])->name('bids.index');
    Route::post('/bids', [BidController::class, 'store'])->name('bids.store');
    Route::get('/bids/{bid}/json', [BidController::class, 'showJson'])->name('bids.json');
    Route::get('/bids/{bid}/record', [BidController::class, 'showRecordJson'])->name('bids.record');
    Route::get('/bids/{bid}', [BidController::class, 'show'])->name('bids.show');
    Route::put('/bids/{bid}', [BidController::class, 'update'])->name('bids.update');
    Route::delete('/bids/{bid}', [BidController::class, 'destroy'])->name('bids.destroy');
    Route::get('/reference/entities', [BidController::class, 'referenceEntitiesSearch'])->name('bids.reference.entities');
    Route::get('/reference/states', [BidController::class, 'referenceStatesSearch'])->name('bids.reference.states');
    Route::get('/reference/categories', [BidController::class, 'referenceCategoriesSearch'])->name('bids.reference.categories');
    Route::get('/reference/bid-urls', [BidController::class, 'referenceBidUrlsSearch'])->name('bids.reference.bidUrls');
    Route::get('/reports/bids-added', [BidController::class, 'reportsBidsAdded'])->name('bids.reports.bidsAdded');
    Route::get('/bids/manual-bid/bid-urls', [BidUrlManualBidController::class, 'searchBidUrls'])->name('bids.manualBid.searchBidUrls');
    Route::post('/bids/manual-bid/start', [BidUrlManualBidController::class, 'startFromBids'])->name('bids.manualBid.start');
    Route::post('/bids/manual-bid', [BidUrlManualBidController::class, 'storeFromBids'])->name('bids.manualBid.store');
    Route::post('/bids/manual-bid/cancel', [BidUrlManualBidController::class, 'cancelFromBids'])->name('bids.manualBid.cancel');
    Route::post('/bidurl/scrape-all', [BidController::class, 'scrapeAll'])->name('bidurl.scrapeAll');
    Route::get('/scrape-stream', [BidController::class, 'scrapeStream'])->name('bidurl.scrapeStream');
    Route::get('/scrape-url-stream', [BidController::class, 'scrapeUrlStream'])->name('bids.scrapeUrlStream');
    // Pending approval queue (scraped bids land in bids_temp until approved)
    Route::get('/pending', [PendingBidController::class, 'index'])->name('pending.index');
    Route::get('/pending/similar', [PendingBidController::class, 'similar'])->name('pending.similar');
    Route::get('/pending/{pendingBid}/json', [PendingBidController::class, 'showJson'])->name('pending.json');
    Route::post('/pending/approve-all', [PendingBidController::class, 'approveAll'])->name('pending.approveAll');
    Route::post('/pending/reject-all', [PendingBidController::class, 'rejectAll'])->name('pending.rejectAll');
    Route::put('/pending/{pendingBid}', [PendingBidController::class, 'update'])->name('pending.update');
    Route::post('/pending/{pendingBid}/approve', [PendingBidController::class, 'approve'])->name('pending.approve');
    Route::delete('/pending/{pendingBid}', [PendingBidController::class, 'reject'])->name('pending.reject');

    Route::get('/issues', [BidController::class, 'issues'])->name('scrape.issues');
    Route::delete('/issues', [BidController::class, 'clearIssues'])->name('scrape.clearIssues');
    Route::delete('/issues/{scrapeLog}', [BidController::class, 'destroyIssue'])->name('scrape.destroyIssue');

    Route::get('/bidurl/upload', [BidUrlController::class, 'create'])->name('bidurl.create');
    Route::post('/bidurl/upload', [BidUrlController::class, 'store'])->name('bidurl.store');
    Route::post('/bidurl', [BidUrlController::class, 'storeSingle'])->name('bidurl.storeSingle');
    Route::post('/bidurl/set-last-scraped', [BidUrlController::class, 'setLastScraped'])->name('bidurl.setLastScraped');
    Route::post('/bidurl/unassigned/auto-assign', [BidUrlController::class, 'autoAssignUnassigned'])->name('bidurl.unassigned.autoAssign');
    Route::get('/bidurl', [BidUrlController::class, 'index'])->name('bidurl.index');
    Route::get('/bidurl/{bidUrl}', [BidUrlController::class, 'show'])->name('bidurl.show');
    Route::put('/bidurl/{bidUrl}', [BidUrlController::class, 'update'])->name('bidurl.update');
    Route::delete('/bidurl/{bidUrl}', [BidUrlController::class, 'destroy'])->name('bidurl.destroy');
    Route::post('/bidurl/{bidUrl}/manual-bid/start', [BidUrlManualBidController::class, 'startConfigured'])->name('bidurl.manualBid.start');
    Route::post('/bidurl/{bidUrl}/manual-bid', [BidUrlManualBidController::class, 'storeConfigured'])->name('bidurl.manualBid.store');
    Route::post('/bidurl/{bidUrl}/manual-bid/cancel', [BidUrlManualBidController::class, 'cancelConfigured'])->name('bidurl.manualBid.cancel');
    Route::post('/failed-bidurl/restore-all', [BidUrlController::class, 'restoreAllFailed'])->name('failed-bidurl.restoreAll');
    Route::post('/failed-bidurl/{failedBidUrl}/manual-bid/start', [BidUrlManualBidController::class, 'startFailed'])->name('failed-bidurl.manualBid.start');
    Route::post('/failed-bidurl/{failedBidUrl}/manual-bid', [BidUrlManualBidController::class, 'storeFailed'])->name('failed-bidurl.manualBid.store');
    Route::post('/failed-bidurl/{failedBidUrl}/manual-bid/cancel', [BidUrlManualBidController::class, 'cancelFailed'])->name('failed-bidurl.manualBid.cancel');
    Route::post('/failed-bidurl/{failedBidUrl}/restore', [BidUrlController::class, 'restoreFailed'])->name('failed-bidurl.restore');
    Route::delete('/failed-bidurl/{failedBidUrl}', [BidUrlController::class, 'destroyFailed'])->name('failed-bidurl.destroy');
});
