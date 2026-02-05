<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BalancerController;
use App\Http\Controllers\NodeController;

Route::post('/press', [BalancerController::class, 'press']);
Route::post('/node', [NodeController::class, 'manage']);