<?php

namespace App\Support;

use Illuminate\Database\Seeder;
use InvalidArgumentException;

/**
 * An install profile from config('pos.profiles'): what db:seed and
 * pos:install create for a new shop. Install-time only. Runtime code asks
 * the shop's Pennant state ($shop->hasModule()), never the profile.
 */
final class InstallProfile
{
    /** @param  array<string, mixed>  $config */
    private function __construct(
        public readonly string $name,
        private readonly array $config,
    ) {}

    /** The profile POS_PROFILE selects. */
    public static function current(): self
    {
        return self::named((string) config('pos.profile', 'retail'));
    }

    public static function named(string $name): self
    {
        $config = config("pos.profiles.{$name}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Unknown install profile [{$name}]. Available: ".implode(', ', self::names()).'.');
        }

        return new self($name, $config);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(strval(...), array_keys(config('pos.profiles', [])));
    }

    public function businessType(): string
    {
        return (string) $this->config['business_type'];
    }

    /** @return list<string> Modules a new shop starts with. */
    public function modules(): array
    {
        return array_values($this->config['modules'] ?? []);
    }

    /** @return list<string> */
    public function roles(): array
    {
        return array_values($this->config['roles'] ?? []);
    }

    /** @return array<string, mixed> Shop settings a new shop starts with (over pos.shop_defaults). */
    public function settings(): array
    {
        return (array) ($this->config['settings'] ?? []);
    }

    /** @return list<class-string<Seeder>> Shared reference data seeded on every install. */
    public function referenceSeeders(): array
    {
        return array_values($this->config['reference_seeders'] ?? []);
    }

    /** @return class-string<Seeder>|null Demo shop for local and testing only. */
    public function demoSeeder(): ?string
    {
        return $this->config['demo_seeder'] ?? null;
    }

    /** Whether db:seed under this profile runs the given seeder (reference or demo). */
    public function seeds(string $seeder): bool
    {
        return in_array($seeder, $this->referenceSeeders(), true) || $this->demoSeeder() === $seeder;
    }

    /**
     * The first is the default unless one says otherwise. A location sells unless it says
     * 'sellable' => false (a quarantine shelf).
     *
     * @return list<array{code: string, name: string, default: bool, sellable: bool}>
     */
    public function locations(): array
    {
        $locations = array_values($this->config['locations'] ?? []);
        $hasDefault = in_array(true, array_column($locations, 'default'), true);

        return array_map(fn (array $location, int $i): array => [
            'code' => (string) $location['code'],
            'name' => (string) $location['name'],
            'default' => $hasDefault ? (bool) ($location['default'] ?? false) : $i === 0,
            'sellable' => (bool) ($location['sellable'] ?? true),
        ], $locations, array_keys($locations));
    }
}
