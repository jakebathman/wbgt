<?php

use App\Services\NwsWeather;
use Illuminate\Support\Facades\Route;

Route::get('/', function (NwsWeather $weather) {
    try {
        $days = $weather->hourlyByDay();
    } catch (Throwable $e) {
        report($e);
        $days = null;
    }

    return view('weather', [
        'days' => $days,
        'levels' => NwsWeather::wbgtLevels(),
        'locationName' => config('services.nws.location_name'),
    ]);
})->name('home');
