<?php

namespace Tests\Feature\Pos;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The server scripts (docs/deploy/CLOUD.md): every one parses, and deploy.sh
 * and rollback.sh, in dry-run mode against a client folder in a temporary
 * directory, plan their steps in the right order and change nothing. The
 * provisioning scripts are only parsed, never run here.
 */
class DeployScriptsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/pos-deploy-test-'.Str::random(8);
        File::ensureDirectoryExists("{$this->root}/shared/storage");
        File::ensureDirectoryExists("{$this->root}/releases/20261001000000-v1.0.0");
        File::ensureDirectoryExists("{$this->root}/releases/20261015000000-v1.1.0");
        file_put_contents("{$this->root}/shared/.env", "APP_URL=https://pos.clinic.example\nDB_DATABASE=pos_clinic\n");
        symlink("{$this->root}/releases/20261015000000-v1.1.0", "{$this->root}/current");
        file_put_contents("{$this->root}/releases/20261015000000-v1.1.0/.deploy", "version=v1.1.0\nprevious=20261001000000-v1.0.0\nmigrations=2\nbackup=pos-backup-20261015-000000-pre-deploy.tar.gz.age\n");
        file_put_contents("{$this->root}/pos-v1.2.0.tar.gz", 'not really a package');

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->root));
    }

    /** @return array<string, array{0: string}> */
    public static function scripts(): array
    {
        return collect(['provision-server.sh', 'add-client.sh', 'deploy.sh', 'rollback.sh', 'package-release.sh', 'lib/common.sh'])
            ->mapWithKeys(fn (string $script): array => [$script => [$script]])
            ->all();
    }

    #[DataProvider('scripts')]
    public function test_every_server_script_parses(string $script): void
    {
        $result = Process::run(['bash', '-n', base_path("scripts/{$script}")]);

        $this->assertTrue($result->successful(), $result->errorOutput());
    }

    /** @param  list<string>  $arguments */
    private function script(string $script, array $arguments): ProcessResult
    {
        return Process::path(base_path())->run(['bash', "scripts/{$script}", "--root={$this->root}", ...$arguments]);
    }

    /** Asserts each step appears in the output, in this order. */
    private function assertInOrder(string $output, array $steps): void
    {
        $at = -1;

        foreach ($steps as $step) {
            $found = strpos($output, $step, $at + 1);
            $this->assertNotFalse($found, "\"{$step}\" is not in the plan (or not after the step before it):\n{$output}");
            $at = $found;
        }
    }

    public function test_deploy_backs_up_first_then_maintenance_migrate_cache_switch_restart_and_up(): void
    {
        $result = $this->script('deploy.sh', ["--release={$this->root}/pos-v1.2.0.tar.gz", '--maintenance=always', '--dry-run']);

        $this->assertTrue($result->successful(), $result->errorOutput().$result->output());
        $this->assertInOrder($result->output(), [
            "tar -xzf {$this->root}/pos-v1.2.0.tar.gz",
            "ln -s {$this->root}/shared/storage",
            'install --working-dir=',
            '--no-dev --optimize-autoloader',
            "{$this->root}/releases/20261015000000-v1.1.0/artisan pos:backup --label=pre-deploy",
            'artisan down',
            '-v1.2.0/artisan migrate --force',
            '-v1.2.0/artisan optimize',
            '-v1.2.0/artisan filament:optimize',
            "mv -Tf {$this->root}/current.next {$this->root}/current",
            "{$this->root}/current/artisan queue:restart",
            "{$this->root}/current/artisan up",
            'curl -fsS https://pos.clinic.example/up',
        ]);

        // A dry run changes nothing.
        $this->assertSame("{$this->root}/releases/20261015000000-v1.1.0", readlink("{$this->root}/current"));
        $this->assertCount(2, glob("{$this->root}/releases/*"));
    }

    public function test_the_first_deploy_has_nothing_to_back_up_and_no_maintenance_without_migrations_known(): void
    {
        unlink("{$this->root}/current");

        $result = $this->script('deploy.sh', ["--release={$this->root}/pos-v1.2.0.tar.gz", '--dry-run']);

        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertStringNotContainsString('pos:backup', $result->output());
        $this->assertStringNotContainsString('artisan down', $result->output());
        $this->assertStringContainsString('migrate --force', $result->output());
    }

    public function test_deploy_refuses_without_a_package_or_a_client(): void
    {
        $this->assertFalse($this->script('deploy.sh', ["--release={$this->root}/missing.tar.gz", '--dry-run'])->successful());

        unlink("{$this->root}/shared/.env");
        $result = $this->script('deploy.sh', ["--release={$this->root}/pos-v1.2.0.tar.gz", '--dry-run']);
        $this->assertFalse($result->successful());
        $this->assertStringContainsString('.env is missing', $result->errorOutput());
    }

    public function test_rollback_undoes_the_deploys_migrations_with_the_newer_code_then_switches_back(): void
    {
        $result = $this->script('rollback.sh', ['--dry-run']);

        $this->assertTrue($result->successful(), $result->errorOutput().$result->output());
        $this->assertInOrder($result->output(), [
            '-v1.1.0/artisan down',
            '-v1.1.0/artisan migrate:rollback --step=2 --force',
            "ln -sfn {$this->root}/releases/20261001000000-v1.0.0 {$this->root}/current.next",
            "mv -Tf {$this->root}/current.next {$this->root}/current",
            '-v1.0.0/artisan optimize',
            '-v1.0.0/artisan queue:restart',
            '-v1.0.0/artisan up',
        ]);
        $this->assertStringNotContainsString('pos:backup:restore', $result->output());
        $this->assertSame("{$this->root}/releases/20261015000000-v1.1.0", readlink("{$this->root}/current"));
    }

    public function test_rollback_with_restore_puts_the_pre_deploy_backup_back_with_the_older_code(): void
    {
        $result = $this->script('rollback.sh', ['--restore', '--dry-run']);

        $this->assertTrue($result->successful(), $result->errorOutput().$result->output());
        $this->assertInOrder($result->output(), [
            'artisan down',
            "mv -Tf {$this->root}/current.next {$this->root}/current",
            '-v1.0.0/artisan pos:backup:restore pos-backup-20261015-000000-pre-deploy.tar.gz.age --confirm=pos_clinic',
            '-v1.0.0/artisan up',
        ]);
        $this->assertStringNotContainsString('migrate:rollback', $result->output());
    }

    public function test_rollback_needs_an_earlier_release(): void
    {
        File::deleteDirectory("{$this->root}/releases/20261001000000-v1.0.0");

        $result = $this->script('rollback.sh', ['--dry-run']);

        $this->assertFalse($result->successful());
        $this->assertStringContainsString('No earlier release', $result->errorOutput());
    }

    public function test_a_release_needs_a_semantic_version(): void
    {
        $result = Process::path(base_path())->run(['bash', 'scripts/package-release.sh', '1.2']);

        $this->assertFalse($result->successful());
        $this->assertStringContainsString('semantic version', $result->errorOutput());
    }
}
