{{-- The clinic dashboard's greeting (App\Filament\Widgets\Clinic\GreetingHero). --}}
<x-filament-widgets::widget>
    <div class="pos-card flex flex-col gap-6 overflow-hidden sm:flex-row sm:items-center" data-test="greeting-hero">
        <div class="min-w-0 flex-1 space-y-3">
            <p class="pos-primary-text text-xs font-semibold tracking-wider uppercase">{{ $greeting }}</p>
            <h2 class="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">Here's how the clinic stands, {{ $firstName }}.</h2>

            @if ($summary !== '')
                <p class="pos-muted text-sm" data-test="greeting-summary">{{ $summary }}</p>
            @endif

            <div class="flex flex-wrap gap-3 pt-1">
                @if ($posUrl)
                    <x-filament::button tag="a" :href="$posUrl" icon="heroicon-o-shopping-cart" size="lg">Open POS</x-filament::button>
                @endif

                @if ($expiringUrl)
                    <x-filament::button tag="a" :href="$expiringUrl" icon="heroicon-o-clock" size="lg" outlined>Expiring stock</x-filament::button>
                @endif
            </div>
        </div>

        {{-- A stethoscope, a capsule and a clipboard in line, in tints of the theme's primary colour. --}}
        <svg class="pos-primary-text hidden h-32 w-44 shrink-0 sm:block" viewBox="0 0 220 160" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
            <circle cx="120" cy="82" r="70" class="pos-hero-blob" stroke="none" />
            <rect x="92" y="34" width="66" height="88" rx="10" class="pos-hero-paper" />
            <rect x="110" y="26" width="30" height="16" rx="5" class="pos-hero-paper" />
            <path d="M106 64h38M106 80h38M106 96h24" stroke-width="2.5" opacity="0.6" />
            <path d="M44 38v26a26 26 0 0 0 52 0V38" />
            <path d="M40 34h8M92 34h8" />
            <path d="M70 90v18a22 22 0 0 0 44 0v-6" />
            <circle cx="114" cy="96" r="7" class="pos-hero-paper" />
            <g transform="rotate(-35 170 118)">
                <rect x="148" y="106" width="46" height="22" rx="11" class="pos-hero-paper" />
                <path d="M171 106v22" />
                <rect x="148" y="106" width="23" height="22" rx="11" class="pos-hero-fill" stroke="none" />
            </g>
        </svg>
    </div>
</x-filament-widgets::widget>
