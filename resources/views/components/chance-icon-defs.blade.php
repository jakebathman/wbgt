{{-- Hatch fills for level 2 <x-chance-icon />s. Render once per page. --}}
<svg class="absolute size-0" aria-hidden="true">
    <defs>
        @foreach (['rain' => 'stroke-sky-500', 'thunder' => 'stroke-amber-500'] as $kind => $stroke)
            <pattern id="hatch-{{ $kind }}" patternUnits="userSpaceOnUse" width="4" height="4" patternTransform="rotate(45)">
                <line class="{{ $stroke }}" x1="0" y1="0" x2="0" y2="4" stroke-width="1.6" />
            </pattern>
        @endforeach
    </defs>
</svg>
