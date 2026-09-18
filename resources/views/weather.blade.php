<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >
    <title>WBGT Forecast</title>

    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <meta name="apple-mobile-web-app-title" content="WBGT">
    <meta name="theme-color" content="#ffffff">

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
@endphp

<body class="p-6 font-mono">
    <div class="mx-auto max-w-2xl">
        <h1 class="text-2xl font-bold">{{ $locationName }}</h1>
        <div class="text-sm text-gray-500">Hourly WBGT forecast from the National Weather Service</div>

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
                        <span>{{ $level['short'] }}</span>
                    </div>
                @endforeach
            </div>

            @foreach ($days as $date => $hours)
                @php
                    $day = $hours[0]['time'];
                    $peak = collect($hours)->whereNotNull('wbgt')->sortByDesc('wbgt')->first();
                    $chartPeak = collect($hours)->whereNotNull('chartWbgt')->sortByDesc('chartWbgt')->first();
                    $heatRisk = \App\Services\NwsWeather::heatRisk(collect($hours)->max('heatRisk'));
                @endphp

                <section class="mt-8 overflow-hidden rounded-xl border border-gray-200 shadow-xs">
                    <header class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                        <div class="flex items-start justify-between gap-4">
                            <h2>
                                <div class="text-xl font-bold leading-tight">{{ $day->isToday() ? 'Today' : ($day->isTomorrow() ? 'Tomorrow' : $day->format('l')) }}</div>
                                <div class="text-sm text-gray-500">{{ $day->format($day->isToday() || $day->isTomorrow() ? 'l, M j' : 'M j') }}</div>
                            </h2>
                            @if ($heatRisk)
                                <div class="text-right text-xs text-gray-500">
                                    <div>NWS HeatRisk</div>
                                    <span class="{{ $riskClasses[$heatRisk['level']] }} mt-0.5 inline-block rounded px-1.5 py-0.5 text-sm font-semibold">{{ $heatRisk['value'] }} {{ $heatRisk['label'] }}</span>
                                </div>
                            @endif
                        </div>

                        @foreach ($hazards[$date] ?? [] as $hazard)
                            <div @class([
                                'mt-3 rounded border-l-4 px-3 py-1.5 text-sm',
                                'border-red-600 bg-red-100 text-red-900' => $hazard['isHeat'] || $hazard['isWarning'],
                                'border-amber-500 bg-amber-100 text-amber-900' => !($hazard['isHeat'] || $hazard['isWarning']),
                            ])>
                                <span class="font-semibold">{{ $hazard['name'] }}</span>
                                <span class="opacity-75">{{ $hazard['when'] }}</span>
                            </div>
                        @endforeach
                    </header>

                    <table class="w-full table-fixed text-center text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-xs text-gray-500">
                                <th class="w-[18%] py-2 pl-4 text-left font-normal">Time</th>
                                <th class="w-[18%] font-normal">Feels</th>
                                <th class="w-[24%] font-normal">WBGT</th>
                                <th class="w-[24%] font-normal">Chart</th>
                                <th class="w-[16%]"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($hours as $hour)
                                <tr class="border-b border-gray-100 last:border-0">
                                    <td class="py-1.5 pl-4 text-left text-gray-600">{{ $hour['time']->format('ga') }}</td>
                                    <td>{{ isset($hour['feelsLike']) ? $hour['feelsLike'] . '°' : '–' }}</td>
                                    <td class="px-1">
                                        @if ($hour['risk'])
                                            <span
                                                class="{{ $riskClasses[$hour['risk']['level']] }} block rounded py-0.5 font-semibold"
                                                title="{{ $hour['risk']['label'] }}"
                                            >{{ $hour['wbgt'] }}&deg;</span>
                                        @else
                                            –
                                        @endif
                                    </td>
                                    <td class="px-1">
                                        @if ($hour['chartRisk'])
                                            <span
                                                class="{{ $riskClasses[$hour['chartRisk']['level']] }} block rounded py-0.5 font-semibold"
                                                title="{{ $hour['chartRisk']['label'] }} ({{ $hour['humidity'] }}% humidity)"
                                            >{{ number_format($hour['chartWbgt'], 1) }}&deg;</span>
                                        @else
                                            –
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap pr-4 text-right">
                                        @if ($hour['rainIcons'])
                                            <span title="{{ $hour['rainChance'] }}% chance of rain">{{ str_repeat('💧', $hour['rainIcons']) }}</span>
                                        @endif
                                        @if ($hour['thunderIcons'])
                                            <span title="{{ $hour['thunder'] }}% chance of thunder">{{ str_repeat('⚡️', $hour['thunderIcons']) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        @if ($peak || $chartPeak)
                            <tfoot>
                                <tr class="border-t border-gray-200 bg-gray-50 text-xs text-gray-500">
                                    <td class="py-2 pl-4 text-left" colspan="2">Peak</td>
                                    <td class="whitespace-nowrap">{{ $peak ? $peak['wbgt'] . '° · ' . $peak['time']->format('ga') : '' }}</td>
                                    <td class="whitespace-nowrap">{{ $chartPeak ? number_format($chartPeak['chartWbgt'], 1) . '° · ' . $chartPeak['time']->format('ga') : '' }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </section>
            @endforeach

            <details class="mt-10 text-xs text-gray-500">
                <summary class="cursor-pointer text-sm">About these numbers</summary>
                <ul class="mt-2 list-disc space-y-1.5 pl-4">
                    <li><span class="font-semibold">WBGT</span> is the NWS wet bulb globe temperature forecast, which accounts for sun, clouds, and wind.</li>
                    <li><span class="font-semibold">Chart</span> is the U.S. Soccer temperature + humidity lookup table (nearest cell). It assumes full sun and light wind, so it usually reads hotter.</li>
                    <li>Colors are the U.S. Soccer heat guideline alert levels for Category 3 regions:
                        <ul class="mt-1 space-y-0.5">
                            @foreach (array_reverse($levels) as $level)
                                <li>{{ $level['range'] }}: {{ $level['label'] }}</li>
                            @endforeach
                        </ul>
                    </li>
                    <li>Matches get a 4 min hydration break per 30 min of play at 89.6&deg;+.</li>
                    <li>💧 rain and ⚡️ thunder chance: one icon at 15%+, two at 40%+, three at 70%+. Hover for the percent. NWS often has no thunder forecast for the first day or two.</li>
                    <li><span class="font-semibold">HeatRisk</span> is the NWS daily 0–4 index, which also considers how unusual the heat is and overnight lows.</li>
                </ul>
            </details>
        @endif
    </div>
</body>

</html>
