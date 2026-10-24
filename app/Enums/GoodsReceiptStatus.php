<?php

namespace App\Enums;

/** A goods received note is a draft until posted; posting moves stock and it never changes again. */
enum GoodsReceiptStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Posted => 'success',
        };
    }
}
