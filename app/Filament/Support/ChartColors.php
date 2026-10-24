<?php

namespace App\Filament\Support;

use App\Models\Location;

/**
 * The dashboard charts' colours: one per counter (location), the same in
 * every chart, and a few with a fixed meaning. Mid-lightness values that
 * keep at least 3:1 against the panel's white and its dark surface, and
 * stay apart for colour-blind readers when side by side (checked with the
 * dataviz palette validator, 2026-10-22). Never the brand or status colours,
 * which mean something else.
 */
final class ChartColors
{
    /** Counters, in the shop's location order: blue, orange, aqua, ochre, magenta, green. */
    public const COUNTERS = ['#2a78d6', '#d95926', '#199e70', '#c98500', '#d55181', '#008300'];

    /** Consultation fees (services) and medicines (goods), apart from the counters' first colours. */
    public const SERVICES = '#7c6ae0';

    public const GOODS = '#c98500';

    /** A single measure across categories (payment methods, stock by category). */
    public const NEUTRAL = '#64748b';

    /**
     * Each location's colour: by its place among all the shop's locations
     * (inactive ones too), so a counter keeps its colour whatever a chart
     * shows. Past the sixth, the neutral grey: a seventh hue would not be
     * told apart.
     *
     * @return array<int, string> location id => colour
     */
    public static function locations(): array
    {
        $colors = [];

        foreach (Location::query()->ordered()->pluck('id')->values() as $index => $id) {
            $colors[(int) $id] = self::COUNTERS[$index] ?? self::NEUTRAL;
        }

        return $colors;
    }
}
