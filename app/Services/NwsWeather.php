<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class NwsWeather
{
    // Gridpoint layers to pull, keyed by the name used in the hourly rows
    protected const LAYERS = [
        'temperature' => 'temperature',
        'feelsLike' => 'apparentTemperature',
        'humidity' => 'relativeHumidity',
        'wbgt' => 'wetBulbGlobeTemperature',
        'rainChance' => 'probabilityOfPrecipitation',
        'thunder' => 'probabilityOfThunder',
        'heatRisk' => 'heatRisk',
        'hazards' => 'hazards',
    ];

    // NWS HeatRisk daily index
    protected const HEAT_RISK_LEVELS = [
        0 => ['level' => 'green', 'label' => 'Little to none'],
        1 => ['level' => 'yellow', 'label' => 'Minor'],
        2 => ['level' => 'orange', 'label' => 'Moderate'],
        3 => ['level' => 'red', 'label' => 'Major'],
        4 => ['level' => 'magenta', 'label' => 'Extreme'],
    ];

    // VTEC phenomenon codes likely to matter for outdoor sports; anything else falls back to the raw code
    protected const HAZARD_PHENOMENA = [
        'HT' => 'Heat', 'EH' => 'Excessive Heat', 'XH' => 'Extreme Heat',
        'SV' => 'Severe Thunderstorm', 'TO' => 'Tornado', 'FF' => 'Flash Flood', 'FA' => 'Flood', 'FL' => 'Flood',
        'WI' => 'Wind', 'HW' => 'High Wind', 'LW' => 'Lake Wind', 'FG' => 'Dense Fog', 'FW' => 'Red Flag',
        'WC' => 'Wind Chill', 'CW' => 'Cold Weather', 'EC' => 'Extreme Cold', 'FZ' => 'Freeze', 'FR' => 'Frost',
        'WS' => 'Winter Storm', 'WW' => 'Winter Weather', 'IS' => 'Ice Storm', 'DU' => 'Blowing Dust', 'AQ' => 'Air Quality',
    ];

    protected const HAZARD_SIGNIFICANCE = ['W' => 'Warning', 'A' => 'Watch', 'Y' => 'Advisory', 'S' => 'Statement'];

    protected const HEAT_PHENOMENA = ['HT', 'EH', 'XH'];

    // U.S. Soccer "Recognize to Recover" heat guidelines for Category 3 regions (°F), which includes Texas
    protected const WBGT_LEVELS = [
        ['min' => 92.0, 'level' => 'black', 'short' => 'No outdoor training', 'range' => '≥ 92.0°', 'label' => 'No outdoor training; delay until cooler or cancel'],
        ['min' => 90.1, 'level' => 'red', 'short' => 'Max 1 hr, four 4 min breaks', 'range' => '90.1–91.9°', 'label' => 'Max 1 hour, four 4 min breaks, no extra conditioning'],
        ['min' => 87.1, 'level' => 'orange', 'short' => 'Max 2 hrs, four 4 min breaks/hr', 'range' => '87.1–90.0°', 'label' => 'Max 2 hours, four 4 min breaks per hour (or 10 min every 30)'],
        ['min' => 82.2, 'level' => 'yellow', 'short' => 'Three 4 min breaks/hr', 'range' => '82.2–87.0°', 'label' => 'Three 4 min breaks per hour (or 12 min every 40)'],
        ['min' => null, 'level' => 'green', 'short' => 'Normal, three 3 min breaks/hr', 'range' => '≤ 82.1°', 'label' => 'Normal activity, three 3 min breaks per hour (or 10 min every 40)'],
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
        foreach (Arr::except($this->gridData()['layers'], 'hazards') as $key => $layer) {
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

            $hour['rainIcons'] = self::percentIcons($hour['rainChance'] ?? 0);
            $hour['thunderIcons'] = self::percentIcons($hour['thunder'] ?? 0);
            $byDay[$hour['time']->format('Y-m-d')][] = $hour;
        }

        return $byDay;
    }

    /**
     * Active NWS hazards (advisories, watches, warnings) grouped by local date (Y-m-d).
     */
    public function hazardsByDay(int $days = 3): array
    {
        $timezone = config('services.nws.timezone');
        $now = CarbonImmutable::now($timezone)->startOfHour();

        $byDay = [];
        foreach ($this->gridData()['layers']['hazards']['values'] as $entry) {
            [$start, $duration] = explode('/', $entry['validTime']);
            $start = CarbonImmutable::parse($start)->setTimezone($timezone);
            $end = $start->add(CarbonInterval::make($duration));

            for ($day = $now->startOfDay(); $day < $now->startOfDay()->addDays($days); $day = $day->addDay()) {
                $from = $start->max($day)->max($now);
                $to = $end->min($day->addDay());

                if ($from >= $to) {
                    continue;
                }

                foreach ($entry['value'] as $hazard) {
                    $byDay[$day->format('Y-m-d')][] = [
                        'name' => self::hazardName($hazard['phenomenon'], $hazard['significance']),
                        'isHeat' => in_array($hazard['phenomenon'], self::HEAT_PHENOMENA),
                        'isWarning' => $hazard['significance'] === 'W',
                        'when' => $from->equalTo($day) && $to->equalTo($day->addDay())
                            ? 'all day'
                            : $from->format('ga').'–'.$to->format('ga'),
                    ];
                }
            }
        }

        return $byDay;
    }

    // Number of rain/thunder icons to show for a percent chance
    public static function percentIcons(int $percent): int
    {
        return match (true) {
            $percent >= 70 => 3,
            $percent >= 40 => 2,
            $percent >= 15 => 1,
            default => 0,
        };
    }

    /**
     * When we last pulled the forecast, and when NWS issued it.
     */
    public function updatedAt(): array
    {
        $data = $this->gridData();

        return [
            'fetched' => CarbonImmutable::parse($data['fetchedAt']),
            'issued' => $data['issuedAt'] ? CarbonImmutable::parse($data['issuedAt']) : null,
        ];
    }

    public static function hazardName(string $phenomenon, ?string $significance): string
    {
        // Non-VTEC hazards come through as CamelCase names, e.g. OzoneActionDay
        $name = self::HAZARD_PHENOMENA[$phenomenon] ?? Str::headline($phenomenon);

        return trim($name.' '.(self::HAZARD_SIGNIFICANCE[$significance] ?? ''));
    }

    public static function heatRisk(?int $value): ?array
    {
        return isset(self::HEAT_RISK_LEVELS[$value]) ? ['value' => $value] + self::HEAT_RISK_LEVELS[$value] : null;
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
        return Cache::remember('nws.forecast.'.$this->locationKey(), now()->addMinutes(20), function () {
            $properties = $this->get($this->gridDataUrl())['properties'];

            return [
                'fetchedAt' => now()->toIso8601String(),
                'issuedAt' => $properties['updateTime'] ?? null,
                'layers' => collect(self::LAYERS)
                    ->map(fn ($layer) => [
                        'uom' => $properties[$layer]['uom'] ?? null,
                        'values' => $properties[$layer]['values'] ?? [],
                    ])
                    ->all(),
            ];
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
