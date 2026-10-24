<?php

namespace App\Enums;

enum SaleStatus: string
{
    case Completed = 'completed';
    case PartiallyReturned = 'partially_returned';
    case Returned = 'returned';
    case Voided = 'voided';
}
