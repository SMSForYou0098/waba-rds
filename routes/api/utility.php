<?php

use App\Http\Controllers\Utility\RequestConverterController;
use Illuminate\Support\Facades\Route;

Route::get('req-converter', [RequestConverterController::class, 'convert']);
