<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title>WBGT Forecast</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

@php
    $riskClasses = [
        'green' => 'bg-green-200 text-green-900',
        'yellow' => 'bg-yellow-200 text-yellow-900',
        'orange' => 'bg-orange-300 text-orange-950',
        'red' => 'bg-red-500 text-white',
        'black' => 'bg-black text-white',
        'magenta' => 'bg-fuchsia-700 text-white',
    ];

    // Number of rain/thunder icons to show for a percent chance
    $icons = fn (int $percent) => match (true) {
        $percent >= 70 => 3,
        $percent >= 40 => 2,
        $percent >= 15 => 1,
        default => 0,
    };
@endphp

<body class="p-6 font-mono">
    <div class="mx-auto max-w-2xl">
        <h1 class="text-2xl font-bold">{{ $locationName }}</h1>
        <div class="text-sm text-gray-500">Hourly forecast from the National Weather Service</div>

        @if ($days === null)
            <div class="mt-6 text-red-700">Couldn't load the forecast from weather.gov. Try again in a minute.</div>
        @else
            {{-- WBGT legend --}}
            <div class="mt-6 flex flex-col gap-1 text-xs">
                @foreach (array_reverse($levels) as $level)
                    <div class="flex items-center gap-2">
                        <span class="{{ $riskClasses[$level['level']] }} w-24 shrink-0 rounded px-2 py-0.5 text-center font-semibold">
                            {{ $level['range'] }}
                        </span>
                        <span>{{ $level['label'] }}</span>
                    </div>
                @endforeach
                <div class="mt-1 text-gray-500">WBGT is the NWS forecast, which accounts for sun, clouds, and wind. Chart is the U.S. Soccer temperature + humidity lookup table (nearest cell), which assumes full sun and light wind.</div>
                <div class="mt-1 text-gray-500">💧 rain and ⚡️ thunder chance: one icon at 15%+, two at 40%+, three at 70%+. NWS often has no thunder forecast for the first day or two.</div>
                <div class="mt-1 text-gray-500">Matches: 4 min hydration break per 30 min of play at 89.6&deg;+. Levels from U.S. Soccer heat guidelines (Category 3).</div>
            </div>

            @foreach ($days as $date => $hours)
                @php
                    $day = $hours[0]['time'];
                    $peak = collect($hours)->whereNotNull('wbgt')->sortByDesc('wbgt')->first();
                    $chartPeak = collect($hours)->whereNotNull('chartWbgt')->sortByDesc('chartWbgt')->first();
                    $heatRisk = \App\Services\NwsWeather::heatRisk(collect($hours)->max('heatRisk'));
                @endphp

                <div class="mt-10">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4">
                        <h2 class="text-xl font-bold">
                            <div>{{ $day->isToday() ? 'Today' : ($day->isTomorrow() ? 'Tomorrow' : $day->format('l')) }}</div>
                            <div class="text-base font-normal text-gray-500">{{ $day->format('M j') }}</div>
                        </h2>
                        <div class="flex flex-col items-end">
                            @if ($peak)
                                <div class="text-sm">
                                    Peak WBGT
                                    <span class="{{ $riskClasses[$peak['risk']['level']] }} rounded px-1.5 py-0.5 font-semibold">{{ number_format($peak['wbgt'], 1) }}&deg;</span>
                                    at {{ $peak['time']->format('ga') }}
                                </div>
                            @endif
                            @if ($chartPeak)
                                <div class="text-sm">
                                    Peak chart
                                    <span class="{{ $riskClasses[$chartPeak['chartRisk']['level']] }} rounded px-1.5 py-0.5 font-semibold">{{ number_format($chartPeak['chartWbgt'], 1) }}&deg;</span>
                                    at {{ $chartPeak['time']->format('ga') }}
                                </div>
                            @endif
                        </div>
                    </div>

                    @if ($heatRisk)
                        <div class="mt-1 text-sm">
                            NWS HeatRisk
                            <span class="{{ $riskClasses[$heatRisk['level']] }} rounded px-1.5 py-0.5 font-semibold">{{ $heatRisk['value'] }} {{ $heatRisk['label'] }}</span>
                        </div>
                    @endif

                    @foreach ($hazards[$date] ?? [] as $hazard)
                        <div @class([
                            'mt-2 rounded border-l-4 px-3 py-1.5 text-sm',
                            'border-red-600 bg-red-50 text-red-900' => $hazard['isHeat'] || $hazard['isWarning'],
                            'border-amber-500 bg-amber-50 text-amber-900' => !($hazard['isHeat'] || $hazard['isWarning']),
                        ])>
                            <span class="font-semibold">{{ $hazard['name'] }}</span>
                            <span class="opacity-75">{{ $hazard['when'] }}</span>
                        </div>
                    @endforeach

                    <table class="mt-3 w-full text-right text-sm">
                        <thead>
                            <tr class="border-b border-gray-300 text-xs text-gray-500">
                                <th class="py-1 text-left font-normal">Time</th>
                                <th class="font-normal">Feels</th>
                                <th class="font-normal">WBGT</th>
                                <th class="font-normal">Chart</th>
                                <th class="pl-3 text-left font-normal">Rain / Thunder</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($hours as $hour)
                                <tr class="border-b border-gray-100">
                                    <td class="py-1 text-left">{{ $hour['time']->format('ga') }}</td>
                                    <td>{{ isset($hour['feelsLike']) ? $hour['feelsLike'] . '°' : '–' }}</td>
                                    <td>
                                        @if ($hour['risk'])
                                            <span
                                                class="{{ $riskClasses[$hour['risk']['level']] }} inline-block w-12 rounded px-1.5 py-0.5 text-center font-semibold"
                                                title="{{ $hour['risk']['label'] }}"
                                            >{{ $hour['wbgt'] }}&deg;</span>
                                        @else
                                            –
                                        @endif
                                    </td>
                                    <td>
                                        @if ($hour['chartRisk'])
                                            <span
                                                class="{{ $riskClasses[$hour['chartRisk']['level']] }} inline-block w-14 rounded px-1.5 py-0.5 text-center font-semibold"
                                                title="{{ $hour['chartRisk']['label'] }} ({{ $hour['humidity'] }}% humidity)"
                                            >{{ number_format($hour['chartWbgt'], 1) }}&deg;</span>
                                        @else
                                            –
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap pl-3 text-left">
                                        @if ($icons($hour['rainChance'] ?? 0))
                                            <span title="{{ $hour['rainChance'] }}% chance of rain">{{ str_repeat('💧', $icons($hour['rainChance'])) }}</span>
                                        @endif
                                        @if ($icons($hour['thunder'] ?? 0))
                                            <span title="{{ $hour['thunder'] }}% chance of thunder">{{ str_repeat('⚡️', $icons($hour['thunder'])) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        @endif
    </div>
</body>

</html>
