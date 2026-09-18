<?php

use App\Services\NwsWeather;
use Illuminate\Support\Facades\Route;

Route::get('/', function (NwsWeather $weather) {
    try {
        $days = $weather->hourlyByDay();
        $hazards = $weather->hazardsByDay();
    } catch (Throwable $e) {
        report($e);
        $days = $hazards = null;
    }

    return view('weather', [
        'days' => $days,
        'hazards' => $hazards,
        'levels' => NwsWeather::wbgtLevels(),
        'locationName' => config('services.nws.location_name'),
    ]);
})->name('home');
