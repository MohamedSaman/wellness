<?php

namespace App\Enums;

enum AttributeType: string
{
    case Select = 'select'; // fixed options: Size S/M/L, Colour Black/Red
    case Text = 'text';     // free text: Material
    case Number = 'number'; // numeric: Weight (g)
}
