<?php

namespace App\Filament\Support;

use Illuminate\Support\HtmlString;

/** Icons the bundled Heroicons set does not have, drawn in its outline style. */
class PosIcon
{
    /** A till: display on a stand, keypad body and cash drawer. */
    public static function cashRegister(): HtmlString
    {
        return new HtmlString(
            '<svg style="width:100%;height:100%" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" data-icon="cash-register">'
            .'<rect x="10" y="2.5" width="9" height="4.5" rx="1"/>'
            .'<path d="M14.5 7v3"/>'
            .'<path d="M4.5 10h15l1.5 6h-18z"/>'
            .'<path d="M8 13h.01M12 13h.01M16 13h.01"/>'
            .'<rect x="3" y="16" width="18" height="5.5" rx="1"/>'
            .'<path d="M10 18.75h4"/>'
            .'</svg>'
        );
    }
}
