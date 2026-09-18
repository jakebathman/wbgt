<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class NwsWeather
{
    // Gridpoint layers to pull, keyed by the name used in the hourly rows
    protected const LAYERS = [
        'temperature' => 'temperature',
        'feelsLike' => 'apparentTemperature',
        'humidity' => 'relativeHumidity',
        'wbgt' => 'wetBulbGlobeTemperature',
        'rainChance' => 'probabilityOfPrecipitation',
        'wind' => 'windSpeed',
    ];

    // U.S. Soccer "Recognize to Recover" heat guidelines for Category 3 regions (°F), which includes Texas
    protected const WBGT_LEVELS = [
        ['min' => 92.0, 'level' => 'black', 'range' => '≥ 92.0°', 'label' => 'No outdoor training; delay until cooler or cancel'],
        ['min' => 90.1, 'level' => 'red', 'range' => '90.1–91.9°', 'label' => 'Max 1 hour, four 4 min breaks, no extra conditioning'],
        ['min' => 87.1, 'level' => 'orange', 'range' => '87.1–90.0°', 'label' => 'Max 2 hours, four 4 min breaks per hour (or 10 min every 30)'],
        ['min' => 82.2, 'level' => 'yellow', 'range' => '82.2–87.0°', 'label' => 'Three 4 min breaks per hour (or 12 min every 40)'],
        ['min' => null, 'level' => 'green', 'range' => '≤ 82.1°', 'label' => 'Normal activity, three 3 min breaks per hour (or 10 min every 40)'],
    ];

    /**
     * Hourly forecast rows grouped by local date (Y-m-d), from the current hour on.
     */
    public function hourlyByDay(int $days = 3, int $firstHour = 6, int $lastHour = 22): array
    {
        $timezone = config('services.nws.timezone');
        $now = CarbonImmutable::now($timezone)->startOfHour();
        $end = $now->startOfDay()->addDays($days);

        $hours = [];
        foreach ($this->gridData() as $key => $layer) {
            foreach ($layer['values'] as $entry) {
                [$start, $duration] = explode('/', $entry['validTime']);
                $time = CarbonImmutable::parse($start)->setTimezone($timezone);
                $until = $time->add(CarbonInterval::make($duration));

                // A single value can cover several hours
                for (; $time < $until; $time = $time->addHour()) {
                    if ($time < $now || $time >= $end || $time->hour < $firstHour || $time->hour > $lastHour) {
                        continue;
                    }

                    $hours[$time->timestamp] ??= ['time' => $time];
                    $value = $this->convert($entry['value'], $layer['uom']);
                    $hours[$time->timestamp][$key] = $value === null ? null : (int) round($value);

                    // Risk comes from the unrounded value so 87.4° isn't shown a level too low
                    if ($key === 'wbgt' && $value !== null) {
                        $hours[$time->timestamp]['risk'] = self::wbgtRisk($value);
                    }
                }
            }
        }

        ksort($hours);

        $byDay = [];
        foreach ($hours as $hour) {
            $hour['risk'] ??= null;

            // What a league would get from the U.S. Soccer temperature + humidity chart
            $hour['chartWbgt'] = isset($hour['temperature'], $hour['humidity'])
                ? WbgtChart::lookup($hour['temperature'], $hour['humidity'])
                : null;
            $hour['chartRisk'] = $hour['chartWbgt'] === null ? null : self::wbgtRisk($hour['chartWbgt']);
            $byDay[$hour['time']->format('Y-m-d')][] = $hour;
        }

        return $byDay;
    }

    public static function wbgtRisk(float $wbgt): array
    {
        foreach (self::WBGT_LEVELS as $level) {
            if ($level['min'] === null || $wbgt >= $level['min']) {
                return $level;
            }
        }
    }

    public static function wbgtLevels(): array
    {
        return self::WBGT_LEVELS;
    }

    protected function gridData(): array
    {
        return Cache::remember('nws.grid-data.'.$this->locationKey(), now()->addMinutes(20), function () {
            $properties = $this->get($this->gridDataUrl())['properties'];

            return collect(self::LAYERS)
                ->map(fn ($layer) => [
                    'uom' => $properties[$layer]['uom'] ?? null,
                    'values' => $properties[$layer]['values'] ?? [],
                ])
                ->all();
        });
    }

    // The grid URL for a lat/lon never changes, so look it up once
    protected function gridDataUrl(): string
    {
        return Cache::rememberForever('nws.grid-url.'.$this->locationKey(), function () {
            $point = config('services.nws.latitude').','.config('services.nws.longitude');

            return $this->get("https://api.weather.gov/points/{$point}")['properties']['forecastGridData'];
        });
    }

    protected function get(string $url): array
    {
        return Http::withUserAgent(config('services.nws.user_agent'))
            ->accept('application/geo+json')
            ->timeout(10)
            ->retry(3, 500)
            ->get($url)
            ->throw()
            ->json();
    }

    protected function locationKey(): string
    {
        return md5(config('services.nws.latitude').','.config('services.nws.longitude'));
    }

    protected function convert(?float $value, ?string $uom): ?float
    {
        if ($value === null) {
            return null;
        }

        return match ($uom) {
            'wmoUnit:degC' => $value * 9 / 5 + 32,
            'wmoUnit:km_h-1' => $value * 0.621371,
            default => $value,
        };
    }
}
