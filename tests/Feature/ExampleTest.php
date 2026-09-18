<?php

use App\Services\NwsWeather;
use App\Services\WbgtChart;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

test('the forecast page shows NWS and chart WBGT values', function () {
    Carbon::setTestNow('2026-09-18 12:00:00 America/Chicago');

    $layer = fn (string $uom, float $value) => [
        'uom' => $uom,
        'values' => [['validTime' => '2026-09-18T17:00:00+00:00/PT3H', 'value' => $value]],
    ];

    Http::fake([
        'api.weather.gov/points/*' => Http::response(['properties' => ['forecastGridData' => 'https://api.weather.gov/gridpoints/FWD/95,122']]),
        'api.weather.gov/gridpoints/*' => Http::response(['properties' => [
            'temperature' => $layer('wmoUnit:degC', 35), // 95°F
            'apparentTemperature' => $layer('wmoUnit:degC', 38),
            'relativeHumidity' => $layer('wmoUnit:percent', 45),
            'wetBulbGlobeTemperature' => $layer('wmoUnit:degC', 30.5556), // 87°F
            'probabilityOfPrecipitation' => $layer('wmoUnit:percent', 45),
            'probabilityOfThunder' => $layer('wmoUnit:percent', 35),
            'heatRisk' => $layer('', 3),
            'hazards' => ['values' => [
                ['validTime' => '2026-09-18T18:00:00+00:00/PT5H', 'value' => [
                    ['phenomenon' => 'HT', 'significance' => 'Y', 'event_number' => 1],
                    ['phenomenon' => 'OzoneActionDay', 'significance' => null, 'event_number' => null],
                ]],
            ]],
        ]]),
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSeeInOrder(['3 Major', 'Heat Advisory', '1pm–6pm', 'Ozone Action Day', '12pm', '100°', '87&deg;', '93.2&deg;', '45% chance of rain', '💧💧', '35% chance of thunder', '⚡'], false)
        ->assertDontSee('⚡⚡')
        ->assertSee('2pm')
        ->assertDontSee('3pm');
});

test('the forecast page shows an error when NWS is down', function () {
    Http::fake(['api.weather.gov/*' => Http::response(null, 500)]);

    $this->get('/')->assertOk()->assertSee("Couldn't load the forecast", false);
});

test('WBGT levels follow the U.S. Soccer category 3 ranges', function (float $wbgt, string $level) {
    expect(NwsWeather::wbgtRisk($wbgt)['level'])->toBe($level);
})->with([
    [82.1, 'green'], [82.2, 'yellow'], [87.0, 'yellow'], [87.1, 'orange'], [90.0, 'orange'],
    [90.1, 'red'], [91.9, 'red'], [92.0, 'black'],
]);

test('the chart lookup snaps to the nearest cell', function () {
    expect(WbgtChart::lookup(94, 45))->toBe(91.4)
        ->and(WbgtChart::lookup(68, 0))->toBe(59.0)
        ->and(WbgtChart::lookup(60, 50))->toBeNull()
        ->and(WbgtChart::lookup(110, 90))->toBeNull();
});
