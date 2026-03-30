<?php

use App\Http\Controllers\LoanAssessmentController;
use Illuminate\Support\Facades\Route;



use App\Http\Controllers\DahuaAutoRegistrationController;

Route::prefix('cgi-bin/api')->group(function () {
    Route::post('/autoRegist/connect', [DahuaAutoRegistrationController::class, 'connect']);
    Route::post('/global/login', [DahuaAutoRegistrationController::class, 'login']);
    Route::post('/global/keep-alive', [DahuaAutoRegistrationController::class, 'keepAlive']);
});


Route::prefix('v1')->group(function () {
    Route::get('assess',[LoanAssessmentController::class,'assess']);
});
