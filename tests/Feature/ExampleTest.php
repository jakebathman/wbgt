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
        'widget.api.perryweather.com/*' => Http::response(['data' => [
            ['observationTime' => '2026-09-18T17:00:00Z', 'wbgt' => ['value' => 87.06, 'unit' => 'F']],
            ['observationTime' => '2026-09-18T18:00:00Z', 'wbgt' => ['value' => 90.14, 'unit' => 'F']],
        ]]),
        'api.weather.gov/points/*' => Http::response(['properties' => ['forecastGridData' => 'https://api.weather.gov/gridpoints/FWD/95,122']]),
        'api.weather.gov/gridpoints/*' => Http::response(['properties' => [
            'updateTime' => '2026-09-18T15:00:00+00:00',
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
        ->assertSeeInOrder(['3 Major', 'Heat Advisory', '1pm–6pm', 'Ozone Action Day', '12pm', '100°', '87.1&deg;', '87&deg;', '93.2&deg;', 'aria-label="45% chance of rain" data-level="2"', 'aria-label="35% chance of thunder" data-level="1"'], false)
        ->assertSeeInOrder(['Last updated', '0 seconds ago', 'NWS forecast issued', '2 hours ago'])
        ->assertSee('2pm')
        ->assertDontSee('3pm')
        ->assertDontSee('Wind chill (Feels)');
});

test('the forecast page shades cold wind chills with the U.S. Soccer cold alert levels', function () {
    Carbon::setTestNow('2027-02-27 07:00:00 America/Chicago');

    // One value per hour from 7am local
    $hourly = fn (array $values) => [
        'uom' => 'wmoUnit:degC',
        'values' => collect($values)->map(fn ($value, $i) => ['validTime' => '2027-02-27T'.(13 + $i).':00:00+00:00/PT1H', 'value' => $value])->all(),
    ];
    $windChill = $hourly([-9.4444, -6.6667, -0.5556]); // 15°F, 20°F, 31°F

    Http::fake([
        'widget.api.perryweather.com/*' => Http::response(['data' => []]),
        'api.weather.gov/points/*' => Http::response(['properties' => ['forecastGridData' => 'https://api.weather.gov/gridpoints/FWD/95,122']]),
        'api.weather.gov/gridpoints/*' => Http::response(['properties' => [
            'temperature' => $hourly([-1.1111, -1.1111, -1.1111]), // 30°F
            'apparentTemperature' => $windChill,
            'windChill' => $windChill,
            'wetBulbGlobeTemperature' => $hourly([0, 0, 0]),
            'hazards' => ['values' => [
                ['validTime' => '2027-02-27T13:00:00+00:00/PT2H', 'value' => [
                    ['phenomenon' => 'WC', 'significance' => 'Y', 'event_number' => 1],
                ]],
            ]],
        ]]),
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSeeInOrder(['Wind chill (Feels)', '16–24°', 'Extra layers, cover skin, rewarm'])
        ->assertSeeInOrder(['border-red-600', 'Wind Chill Advisory', '7am–9am'], false)
        ->assertSeeInOrder(['7am', 'bg-red-500 text-white', '15&deg;', '8am', 'bg-orange-300 text-orange-950', '20&deg;', '9am', '31°', 'Peak', '15&deg;<br>7am'], false);
});

test('the forecast page shows an error when NWS is down', function () {
    Http::fake(['*' => Http::response(null, 500)]);

    $this->get('/')->assertOk()->assertSee("Couldn't load the forecast", false);
});

test('WBGT levels follow the U.S. Soccer category 3 ranges', function (float $wbgt, string $level) {
    expect(NwsWeather::wbgtRisk($wbgt)['level'])->toBe($level);
})->with([
    [82.1, 'green'], [82.2, 'yellow'], [87.0, 'yellow'], [87.1, 'orange'], [90.0, 'orange'],
    [90.1, 'red'], [91.9, 'red'], [92.0, 'black'],
]);

test('wind chill levels follow the U.S. Soccer cold weather guidelines', function (float $windChill, string $level) {
    expect(NwsWeather::windChillRisk($windChill)['level'])->toBe($level);
})->with([
    [31.0, 'green'], [30.4, 'yellow'], [25.0, 'yellow'], [24.0, 'orange'], [16.0, 'orange'],
    [15.4, 'red'], [1.0, 'red'], [0.4, 'black'], [-10.0, 'black'],
]);

test('the chart lookup snaps to the nearest cell', function () {
    expect(WbgtChart::lookup(94, 45))->toBe(91.4)
        ->and(WbgtChart::lookup(68, 0))->toBe(59.0)
        ->and(WbgtChart::lookup(60, 50))->toBeNull()
        ->and(WbgtChart::lookup(110, 90))->toBeNull();
});

test('the page still loads when the MSA station forecast is unavailable', function () {
    Carbon::setTestNow('2026-09-18 12:00:00 America/Chicago');

    Http::fake([
        'widget.api.perryweather.com/*' => Http::response(null, 500),
        'api.weather.gov/points/*' => Http::response(['properties' => ['forecastGridData' => 'https://api.weather.gov/gridpoints/FWD/95,122']]),
        'api.weather.gov/gridpoints/*' => Http::response(['properties' => [
            'wetBulbGlobeTemperature' => ['uom' => 'wmoUnit:degC', 'values' => [['validTime' => '2026-09-18T17:00:00+00:00/PT1H', 'value' => 30]]],
        ]]),
    ]);

    $this->get('/')->assertOk()->assertSee('86&deg;', false)->assertDontSee("Couldn't load the forecast", false);
});
