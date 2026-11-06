<?php

namespace App\Filament\Support;

/**
 * For the input of a Barcode field: a scanner types the code and then presses
 * Enter, which would submit the form half filled in. Here Enter goes to the
 * next field instead, and never submits. Next is the next visible, enabled
 * input, select, toggle or searchable select (its button); with none left,
 * the cursor stays where it is.
 */
final class BarcodeInput
{
    /** @var array<string, string> */
    public const ENTER_MOVES_TO_NEXT_FIELD = [
        'x-on:keydown.enter.prevent' => "const fields = [...\$el.form.querySelectorAll('input:not([type=hidden]), select, textarea, [role=switch], .fi-select-input-btn')].filter((field) => field === \$el || (! field.matches(':disabled') && field.tabIndex >= 0 && field.offsetParent !== null)); fields[fields.indexOf(\$el) + 1]?.focus()",
    ];
}
