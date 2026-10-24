<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * assertSame for a JSON object read back from the database (audit log
     * values, settings): MySQL's JSON type does not keep the order keys were
     * written in (it sorts them), SQLite does. Keys, values and types must
     * still match exactly; lists keep their order.
     *
     * @param  array<array-key, mixed>  $expected
     * @param  array<array-key, mixed>|null  $actual
     */
    protected function assertSameIgnoringKeyOrder(array $expected, ?array $actual, string $message = ''): void
    {
        $this->assertSame(self::sortKeys($expected), $actual === null ? null : self::sortKeys($actual), $message);
    }

    /**
     * @param  array<array-key, mixed>  $array
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $array): array
    {
        if (! array_is_list($array)) {
            ksort($array);
        }

        return array_map(fn (mixed $value): mixed => is_array($value) ? self::sortKeys($value) : $value, $array);
    }
}
