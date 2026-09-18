<?php

use App\Services\NwsWeather;
use App\Services\PerryWeather;
use Illuminate\Support\Facades\Route;

Route::get('/', function (NwsWeather $weather, PerryWeather $perry) {
    try {
        $days = $weather->hourlyByDay();
        $hazards = $weather->hazardsByDay();
        $updatedAt = $weather->updatedAt();
    } catch (Throwable $e) {
        report($e);
        $days = $hazards = $updatedAt = null;
    }

    // The league's field station forecast is a nice-to-have, so the page still works without it
    $msa = rescue(fn () => $perry->hourlyWbgt(), []);

    foreach ($days ?? [] as $date => $hours) {
        foreach ($hours as $i => $hour) {
            $wbgt = $msa[$hour['time']->timestamp] ?? null;
            $days[$date][$i]['msaWbgt'] = $wbgt;
            $days[$date][$i]['msaRisk'] = $wbgt === null ? null : NwsWeather::wbgtRisk($wbgt);
        }
    }

    return view('weather', [
        'days' => $days,
        'hazards' => $hazards,
        'updatedAt' => $updatedAt,
        'levels' => NwsWeather::wbgtLevels(),
        'locationName' => config('services.nws.location_name'),
    ]);
})->name('home');
