<?php

namespace Tests\Feature\Pos\Mysql;

use Carbon\CarbonImmutable;
use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Pos\Concerns\SeedsDemoClinic;
use Tests\Feature\Pos\Concerns\WorksWithBatches;
use Tests\TestCase;

/**
 * The MySQL group (docs/PHARMACY.md §11): what SQLite can't show. SQLite
 * ignores row locks (lockForUpdate() is a no-op) and is loose with DECIMAL
 * and dates, so locking, races between tills and FEFO run here on a real
 * MySQL 8 database.
 *
 * The connection comes from .env.testing.mysql (gitignored; copy
 * .env.testing.mysql.example). Without it the tests are skipped, unless
 * POS_MYSQL_TESTS_REQUIRED=1 (CI), which makes that a failure. They only
 * ever run against a database whose name ends in "_test", because every
 * test truncates it; data is committed (no wrapping transaction) so that
 * forked workers see it. Run them with `composer test:mysql`; the default
 * run (phpunit.xml) excludes the group.
 */
#[Group('mysql')]
abstract class MysqlTestCase extends TestCase
{
    use DatabaseTruncation, SeedsDemoClinic, WorksWithBatches;

    public const ENV_FILE = '.env.testing.mysql';

    public function createApplication(): Application
    {
        $base = dirname(__DIR__, 4);

        if (! is_file($base.'/'.self::ENV_FILE)) {
            $this->cannotRun('No '.self::ENV_FILE.': copy '.self::ENV_FILE.'.example and point it at a MySQL test database.');
        }

        // Overrides phpunit.xml's SQLite settings before the application reads them.
        Dotenv::create(RepositoryBuilder::createWithDefaultAdapters()->addAdapter(PutenvAdapter::class)->make(), $base, self::ENV_FILE)->load();

        $app = parent::createApplication();
        $database = (string) $app['config']->get('database.connections.mysql.database');

        if ($app['config']->get('database.default') !== 'mysql' || ! str_ends_with($database, '_test')) {
            throw new \RuntimeException('The MySQL group only runs against a mysql database whose name ends in _test (got '.$app['config']->get('database.default')." / {$database}).");
        }

        return $app;
    }

    /**
     * Skipped locally when MySQL or the extensions are missing; a failure in
     * CI (POS_MYSQL_TESTS_REQUIRED=1), where a skip would look like a pass.
     */
    private function cannotRun(string $why): never
    {
        if (filter_var(getenv('POS_MYSQL_TESTS_REQUIRED'), FILTER_VALIDATE_BOOL)) {
            $this->fail($why);
        }

        $this->markTestSkipped($why);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A second connection to the same database, to hold locks as another till would.
        config(['database.connections.mysql_other' => config('database.connections.mysql')]);

        $this->seedDemoClinic();
        $this->travelTo(CarbonImmutable::parse('2026-10-18 04:30:00', 'UTC'));
    }

    /**
     * Run $work in $workers forked processes at the same moment, each on its
     * own database connection, and wait for them. Returns each worker's
     * outcome: "ok", or the class and message of what it threw.
     *
     * @param  callable(int): mixed  $work
     * @return list<string>
     */
    protected function race(int $workers, callable $work): array
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->cannotRun('Races need the pcntl and posix extensions.');
        }

        $dir = sys_get_temp_dir().'/pos-race-'.bin2hex(random_bytes(6));
        mkdir($dir);

        // Children must not share the parent's socket: close it, each process reconnects.
        DB::disconnect();
        $pids = [];

        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Could not fork a worker.');
            }

            if ($pid === 0) {
                $outcome = 'ok';

                try {
                    DB::purge();

                    while (! is_file("{$dir}/go")) {
                        usleep(500);
                    }

                    $work($i);
                } catch (\Throwable $exception) {
                    $outcome = $exception::class.': '.$exception->getMessage();
                }

                file_put_contents("{$dir}/{$i}", $outcome);

                // Leave without running PHPUnit's shutdown in the child.
                posix_kill((int) getmypid(), SIGKILL);
            }

            $pids[] = $pid;
        }

        touch("{$dir}/go");

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::purge();

        $outcomes = [];

        for ($i = 0; $i < $workers; $i++) {
            $outcomes[] = (string) @file_get_contents("{$dir}/{$i}");
            @unlink("{$dir}/{$i}");
        }

        @unlink("{$dir}/go");
        @rmdir($dir);

        return $outcomes;
    }
}
