<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business-rule failure the cashier/owner should see as a friendly message
 * (not enough stock, discount too high, payment short ...).
 */
class PosException extends RuntimeException
{
    public static function insufficientStock(string $item, float $available, float $requested): self
    {
        return new self(sprintf('Not enough stock for %s: %s available, %s requested.', $item, $available, $requested));
    }

    /**
     * Not enough sellable stock in a batch-tracked item's batches, saying what
     * is held back (expired, too close to expiry, quarantined) so the cashier
     * knows why the shelf and the system disagree.
     *
     * @param  array{expired: float, near_expiry: float, blocked: float}  $heldBack
     */
    public static function insufficientBatchStock(string $item, float $available, float $requested, array $heldBack): self
    {
        $parts = array_filter([
            $heldBack['expired'] > 0 ? self::number($heldBack['expired']).' expired' : null,
            $heldBack['near_expiry'] > 0 ? self::number($heldBack['near_expiry']).' too close to expiry to sell' : null,
            $heldBack['blocked'] > 0 ? self::number($heldBack['blocked']).' quarantined or recalled' : null,
        ]);

        return new self(sprintf('Not enough stock for %s: %s available, %s requested.', $item, self::number($available), self::number($requested))
            .($parts === [] ? '' : ' Held back: '.implode(', ', $parts).'.'));
    }

    private static function number(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
    }

    public static function discountTooHigh(float $percent, float $limit): self
    {
        return new self(sprintf('Discount of %.1f%% is above your limit of %.1f%%. Ask the owner to approve it.', $percent, $limit));
    }
}
