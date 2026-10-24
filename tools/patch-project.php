<?php

/**
 * Wires the POS foundation into a fresh Laravel 13 starter-kit project.
 * Safe to run more than once (it skips anything already done).
 *
 *   php tools/patch-project.php
 *
 * 1. Registers App\Providers\PosServiceProvider in bootstrap/providers.php
 * 2. Adds roles (Spatie HasRoles), shop relation and Filament panel access
 *    rules to app/Models/User.php
 */
$root = dirname(__DIR__);
$errors = 0;

function done(string $message): void
{
    echo "  \033[32m✔\033[0m {$message}\n";
}

function skip(string $message): void
{
    echo "  \033[33m•\033[0m {$message} (already done)\n";
}

function fail(string $message): void
{
    global $errors;
    $errors++;
    echo "  \033[31m✘\033[0m {$message}\n";
}

echo "\nPatching project in {$root}\n\n";

// ---------------------------------------------------------------- providers
$providersFile = "{$root}/bootstrap/providers.php";
$providers = @file_get_contents($providersFile);

if ($providers === false) {
    fail('bootstrap/providers.php not found. Run this from inside your Laravel project.');
} elseif (str_contains($providers, 'PosServiceProvider')) {
    skip('PosServiceProvider registered');
} else {
    $patched = preg_replace('/\n\];\s*$/', "\n    App\\Providers\\PosServiceProvider::class,\n];\n", $providers, 1, $count);

    if ($count === 1) {
        file_put_contents($providersFile, $patched);
        done('Registered PosServiceProvider in bootstrap/providers.php');
    } else {
        fail('Could not find the closing "];" in bootstrap/providers.php. Add App\\Providers\\PosServiceProvider::class manually.');
    }
}

// ---------------------------------------------------------------- User model
$userFile = "{$root}/app/Models/User.php";
$user = @file_get_contents($userFile);

if ($user === false) {
    fail('app/Models/User.php not found.');
} else {
    $original = $user;

    // Imports
    $imports = [
        'Filament\\Models\\Contracts\\FilamentUser',
        'Filament\\Panel',
        'Illuminate\\Database\\Eloquent\\Relations\\BelongsTo',
        'Spatie\\Permission\\Traits\\HasRoles',
    ];

    foreach ($imports as $import) {
        if (! preg_match('/^use\s+'.preg_quote($import, '/').';/m', $user)) {
            $user = preg_replace('/^namespace App\\\\Models;\s*$/m', "namespace App\\Models;\n\nuse {$import};", $user, 1);
        }
    }

    // implements FilamentUser
    if (! preg_match('/class\s+User\s+extends\s+\w+[^{]*\bFilamentUser\b/', $user)) {
        if (preg_match('/(class\s+User\s+extends\s+\w+\s+implements\s+)([^{]+?)(\s*\{)/', $user)) {
            $user = preg_replace('/(class\s+User\s+extends\s+\w+\s+implements\s+)([^{]+?)(\s*\{)/', '$1$2, FilamentUser$3', $user, 1);
        } else {
            $user = preg_replace('/(class\s+User\s+extends\s+\w+)(\s*\{)/', '$1 implements FilamentUser$2', $user, 1);
        }
    }

    // use HasRoles trait (added to the existing "use HasFactory, ..." line)
    if (! preg_match('/^[ \t]+use[ \t]+[^;]*\bHasRoles\b[^;]*;/m', $user)) {
        $user = preg_replace('/^([ \t]+use[ \t]+)(HasFactory\b)/m', '$1$2, HasRoles', $user, 1, $traitCount);

        if ($traitCount !== 1) {
            fail('Could not find "use HasFactory" in User.php. Add "use HasRoles;" inside the class manually.');
        }
    }

    // Methods
    if (! str_contains($user, 'function canAccessPanel')) {
        $methods = <<<'PHP'

    /**
     * The shop (tenant) this user works for.
     *
     * @return BelongsTo<\App\Models\Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Shop::class);
    }

    /**
     * Who may open the admin panel (/admin). Disabled users never can.
     * Each screen inside the panel is further limited by permissions.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) ($this->getAttribute('is_active') ?? true)
            && $this->getAttribute('shop_id') !== null
            && $this->hasAnyRole(['owner', 'cashier']);
    }

PHP;
        $lastBrace = strrpos($user, '}');
        $user = substr($user, 0, $lastBrace).$methods.substr($user, $lastBrace);
    }

    if ($user === $original) {
        skip('User model patched');
    } else {
        file_put_contents($userFile, $user);
        done('Added HasRoles, shop() and canAccessPanel() to app/Models/User.php');
    }
}

echo $errors === 0 ? "\nAll done.\n\n" : "\n{$errors} step(s) need manual attention (see above).\n\n";

exit($errors === 0 ? 0 : 1);
