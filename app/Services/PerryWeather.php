<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Forecast for the Perry Weather station at the fields, which is what McKinney Soccer Association
 * uses to make heat decisions. This is the undocumented API behind the widget on the city's park
 * pages, so treat it as optional: it may change or go away.
 */
class PerryWeather
{
    /**
     * Hourly WBGT forecast (°F, one decimal) keyed by the hour's unix timestamp. Covers roughly 36 hours.
     */
    public function hourlyWbgt(): array
    {
        $locationId = config('services.perry_weather.location_id');

        return Cache::remember("perry-weather.hourly-wbgt.{$locationId}", now()->addMinutes(20), function () use ($locationId) {
            $hours = Http::timeout(10)
                ->retry(2, 500)
                ->get(config('services.perry_weather.url')."/Widget/Forecast/{$locationId}", ['forecastType' => 0])
                ->throw()
                ->json('data');

            return collect($hours)
                ->filter(fn ($hour) => isset($hour['wbgt']['value']))
                ->mapWithKeys(fn ($hour) => [
                    CarbonImmutable::parse($hour['observationTime'], 'UTC')->timestamp => round($hour['wbgt']['value'], 1),
                ])
                ->all();
        });
    }
}
