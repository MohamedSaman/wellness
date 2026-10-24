{{-- Topbar "Open POS": icon only on small screens (with a tooltip). Alt+P is in open-pos-shortcut. --}}
<x-filament::button
    tag="a"
    :href="route('pos.index')"
    :icon="\App\Filament\Support\PosIcon::cashRegister()"
    color="primary"
    labeled-from="md"
    tooltip="Open POS (Alt+P)"
    data-test="open-pos"
>
    Open POS
</x-filament::button>
