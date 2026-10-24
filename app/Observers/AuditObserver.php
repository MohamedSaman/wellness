<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Shop;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

/**
 * Writes created / updated / deleted events for the models listed in
 * config('pos.audited_models') to audit_logs: price changes, product edits,
 * user changes, device changes ...
 */
class AuditObserver
{
    public function __construct(private readonly CurrentShop $currentShop) {}

    public function created(Model $model): void
    {
        $this->write('created', $model, null, $this->clean($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changes = $this->clean($model->getChanges());

        if ($changes === []) {
            return;
        }

        $this->write('updated', $model, Arr::only($this->clean($model->getOriginal()), array_keys($changes)), $changes);
    }

    public function deleted(Model $model): void
    {
        $this->write('deleted', $model, $this->clean($model->getOriginal()), null);
    }

    /**
     * A custom audit entry for something a service did on purpose (for example
     * a cost revaluation, with its reason), instead of a plain "updated".
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function record(string $event, Model $model, array $old, array $new, ?int $userId = null): AuditLog
    {
        return $this->write($event, $model, $old, $new, $userId);
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function write(string $event, Model $model, ?array $old, ?array $new, ?int $userId = null): AuditLog
    {
        $request = app()->runningInConsole() ? null : request();

        // Read raw attributes: not every audited model has a shop_id column,
        // and strict mode throws when a missing attribute is read.
        $shopId = $model instanceof Shop
            ? $model->getKey()
            : ($model->getAttributes()['shop_id'] ?? $this->currentShop->id());

        return AuditLog::query()->create([
            'shop_id' => $shopId,
            'user_id' => $userId ?? Auth::id(),
            'event' => $event,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function clean(array $attributes): array
    {
        $attributes = Arr::except($attributes, config('pos.audit_exclude', []));

        // Enums / casts -> plain scalars for JSON.
        return array_map(
            fn ($value) => $value instanceof \BackedEnum ? $value->value : $value,
            $attributes,
        );
    }
}
