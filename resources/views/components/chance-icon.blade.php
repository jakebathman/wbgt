{{--
    A rain drop or lightning bolt whose fill shows how likely it is:
    level 1 is outlined, level 2 is part-filled (hatched drop, light bolt), level 3 is solid.
    Expects the hatch pattern from <x-chance-icon-defs /> to be on the page.
--}}
@props(['kind', 'level', 'percent'])

@php
    $icon = [
        'rain' => [
            'label' => 'rain',
            'color' => 'text-sky-500',
            'path' => 'M12 3C12 3 5.5 10.2 5.5 15a6.5 6.5 0 0 0 13 0C18.5 10.2 12 3 12 3Z',
            'partFill' => 'url(#hatch-rain)',
            'partClass' => '',
        ],
        'thunder' => [
            'label' => 'thunder',
            'color' => 'text-amber-500',
            'path' => 'M13.5 2.5 5 13.5h6l-1.5 8 9-11.5h-6l1-7.5Z',
            'partFill' => 'none',
            'partClass' => 'fill-amber-300',
        ],
    ][$kind];

    $fill = match ((int) $level) {
        3 => 'currentColor',
        2 => $icon['partFill'],
        default => 'none',
    };
    $fillClass = (int) $level === 2 ? $icon['partClass'] : '';
@endphp

<svg
    {{ $attributes->class(['inline-block size-4 align-middle', $icon['color'], $fillClass]) }}
    viewBox="0 0 24 24"
    fill="{{ $fill }}"
    stroke="currentColor"
    stroke-width="1.75"
    stroke-linejoin="round"
    role="img"
    aria-label="{{ $percent }}% chance of {{ $icon['label'] }}" data-level="{{ $level }}"
><title>{{ $percent }}% chance of {{ $icon['label'] }}</title><path d="{{ $icon['path'] }}" /></svg>
