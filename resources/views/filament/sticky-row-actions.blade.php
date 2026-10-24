{{--
    Row actions stay in view on every admin table: when a table is wider than
    the page it scrolls sideways, but the actions column is pinned to the
    right edge instead of being pushed off it.

    A pinned (sticky) cell always forms its own stacking context, one per row,
    so a ⋮ menu opened in one row would sit under the next row's pinned cell.
    The menu floats with position: fixed (Filament's dropdown "teleport"), so
    the scroll wrapper never clips it; and while it is open (its trigger has
    aria-expanded="true") that row's cell is lifted above every other row.
--}}
<style>
    .fi-ta-table td:has(> .fi-ta-actions),
    .fi-ta-table th.fi-ta-actions-header-cell {
        position: sticky;
        right: 0;
        z-index: 1;
        background-color: var(--color-white, #fff);
        box-shadow: -8px 0 8px -8px rgb(0 0 0 / 0.18);
    }

    .fi-ta-table td:has(> .fi-ta-actions [aria-expanded='true']) {
        z-index: 30;
    }

    .dark .fi-ta-table td:has(> .fi-ta-actions),
    .dark .fi-ta-table th.fi-ta-actions-header-cell {
        background-color: var(--color-gray-900, #18181b);
        box-shadow: -8px 0 8px -8px rgb(0 0 0 / 0.6);
    }
</style>
