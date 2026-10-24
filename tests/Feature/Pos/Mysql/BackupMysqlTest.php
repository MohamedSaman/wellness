<?php

namespace Tests\Feature\Pos\Mysql;

use App\Models\Product;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupStatus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Pos\Concerns\UsesBackupSandbox;

/**
 * The backup round trip on MySQL (docs/deploy/CLOUD.md, Backups): mysqldump
 * --single-transaction, age, the restore into the scratch database
 * <database>_verify and the restore into the live one with the mysql client.
 * The test database's user needs that scratch database too:
 *   GRANT ALL ON `pos_pharmacy_test_verify`.* TO 'pos_user'@'localhost';
 */
#[Group('mysql')]
class BackupMysqlTest extends MysqlTestCase
{
    use UsesBackupSandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBackupSandbox();

        $verify = config('database.connections.mysql.database').'_verify';

        try {
            DB::statement("CREATE DATABASE IF NOT EXISTS `{$verify}`");
        } catch (\Throwable $exception) {
            $why = "The test database's user cannot create {$verify}: GRANT ALL ON `{$verify}`.* to it.";
            filter_var(getenv('POS_MYSQL_TESTS_REQUIRED'), FILTER_VALIDATE_BOOL) ? $this->fail($why) : $this->markTestSkipped($why);
        }
    }

    public function test_backup_verify_and_restore_on_mysql(): void
    {
        $products = Product::query()->count();
        $name = app(BackupService::class)->create();

        $this->artisan('pos:backup:verify')->assertSuccessful();
        $verify = app(BackupStatus::class)->lastOk('verify');
        $this->assertSame('restore', $verify['level']);
        $this->assertSame($name, $verify['name']);

        Product::query()->update(['name' => 'Changed after the backup']);

        $this->artisan('pos:backup:restore', ['--confirm' => (string) config('database.connections.mysql.database'), '--no-safety-backup' => true])->assertSuccessful();

        $this->assertSame($products, Product::query()->count());
        $this->assertSame(0, Product::query()->where('name', 'Changed after the backup')->count());
    }
}
