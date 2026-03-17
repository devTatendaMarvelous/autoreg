<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DahuaAutoRegistrationController;

/*
|--------------------------------------------------------------------------
| Dahua CGI Auto-Registration Routes
|--------------------------------------------------------------------------
| These routes are placed in web.php to avoid the automatic /api prefix.
| The device expects these paths to be relative to the root URL.
*/
//
//Route::group(['prefix' => 'cgi-bin/api'], function () {
//
//    // 4.19.1 Auto Connection Device Interface [cite: 2132]
//    Route::post('/autoRegist/connect', [DahuaAutoRegistrationController::class, 'connect']);
//
//    // 4.19.2 Login Interface [cite: 2189]
//    Route::post('/global/login', [DahuaAutoRegistrationController::class, 'login']);
//
//    // 4.19.3 Heartbeat Interface [cite: 2221]
//    Route::post('/global/keep-alive', [DahuaAutoRegistrationController::class, 'keepAlive']);
//
//    // 4.20.1 Attendance Export Notification [cite: 2257, 2274]
//    Route::post('/AccessAppHelper/attachUSB', [DahuaAutoRegistrationController::class, 'attachUSBNotification']);
//
//});

