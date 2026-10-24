<?php

namespace App\Models;

use App\Exceptions\PosException;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * @property bool $opd_billing the till rings up OPD bills (consultation + one Medicines amount)
 * @property int|null $pending_uploads offline sales the till last said it holds to upload (System health)
 * @property int|null $clock_offset_seconds the till's clock against the server's when it last reported (+ = ahead)
 * @property Carbon|null $reported_at when the till last reported them
 *
 * A registered till (T1, T2 ...). Each device numbers its own invoices
 * (T1-000245) so tills working offline can never produce the same number.
 * A till registered for offline use holds a Sanctum token with the
 * `pos:sync` ability; the owner revokes it in the admin panel.
 */
class Device extends Model
{
    use BelongsToShop, HasApiTokens, HasPublicUuid;

    public const SYNC_ABILITY = 'pos:sync';

    protected $guarded = ['id', 'next_invoice_number'];

    /**
     * Mirror the column defaults so a new instance reads them before a reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'opd_billing' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'pending_uploads' => 'integer',
            'clock_offset_seconds' => 'integer',
            'reported_at' => 'datetime',
            'next_invoice_number' => 'integer',
            'opd_billing' => 'boolean',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<InvoiceNumberBlock, $this> */
    public function invoiceBlocks(): HasMany
    {
        return $this->hasMany(InvoiceNumberBlock::class);
    }

    /** A new sync token for this till. The plain-text token is only available on the result. */
    public function issueSyncToken(string $name): NewAccessToken
    {
        return $this->createToken($name, [self::SYNC_ABILITY]);
    }

    /** Stop every browser registered as this till from syncing. Returns how many tokens were revoked. */
    public function revokeSyncTokens(): int
    {
        return $this->tokens()->delete();
    }

    public function formatInvoiceNumber(int $number, int $padding = 6): string
    {
        return sprintf('%s-%s', $this->code, str_pad((string) $number, $padding, '0', STR_PAD_LEFT));
    }

    /** The number part of one of this till's invoice numbers ("T1-000245" -> 245), or null. */
    public function invoiceSequence(string $invoiceNo): ?int
    {
        return preg_match('/^'.preg_quote($this->code, '/').'-(\d{1,18})$/', $invoiceNo, $m) ? (int) $m[1] : null;
    }

    /**
     * Set aside the next $size invoice numbers for offline sales. Online sales
     * continue after the block. Must be called inside a DB transaction.
     */
    public function reserveInvoiceBlock(int $size): InvoiceNumberBlock
    {
        $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
        $first = (int) $locked->next_invoice_number;

        static::query()->whereKey($this->getKey())->update(['next_invoice_number' => $first + $size]);

        return $this->invoiceBlocks()->create(['first_number' => $first, 'last_number' => $first + $size - 1]);
    }

    /**
     * An offline till numbered its own invoice (e.g. T1-000245). Move the
     * server counter past it so online sales never reuse that number.
     * Must be called inside a DB transaction.
     */
    public function advanceInvoiceCounterPast(string $invoiceNo): void
    {
        $sequence = $this->invoiceSequence($invoiceNo)
            ?? throw new PosException("Invoice number {$invoiceNo} does not belong to till {$this->code}.");

        $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
        $next = max((int) $locked->next_invoice_number, $sequence + 1);

        static::query()->whereKey($this->getKey())->update(['next_invoice_number' => $next]);
    }

    /**
     * Reserve the next invoice number. Must be called inside a DB transaction;
     * the row lock stops two checkouts on one device getting the same number.
     */
    public function reserveInvoiceNumber(int $padding = 6): string
    {
        $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
        $number = $locked->next_invoice_number;

        static::query()->whereKey($this->getKey())->update(['next_invoice_number' => $number + 1]);

        return $this->formatInvoiceNumber((int) $number, $padding);
    }
}
