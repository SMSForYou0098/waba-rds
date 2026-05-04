<?php

use App\Events\ReportUpdated;
use App\Events\TestEvent;
use App\Models\Report;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
     return redirect('https://web.smsforyou.biz');
});

Route::get('/test', function () {
    // event(new TestEvent());
    // dd('done...');
    $report = Report::all()->toArray();

    // Trigger event using Pusher
    event(new ReportUpdated($report));
    dd($report);
});
