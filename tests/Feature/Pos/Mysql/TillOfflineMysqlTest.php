<?php

namespace Tests\Feature\Pos\Mysql;

use App\Services\TillOfflineService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Two browsers opening the same approved till at once: the row lock lets one
 * take the till's token and the other is refused (SQLite ignores the lock).
 */
#[Group('mysql')]
class TillOfflineMysqlTest extends MysqlTestCase
{
    public function test_two_browsers_claiming_one_till_get_one_token(): void
    {
        $outcomes = $this->race(4, fn (): mixed => app(TillOfflineService::class)->claim($this->till, $this->cashier, 'browser'));

        $this->assertSame(1, count(array_keys($outcomes, 'ok', true)), implode(' | ', $outcomes));
        $this->assertSame(1, $this->till->tokens()->count());
    }
}
