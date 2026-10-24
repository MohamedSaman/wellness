<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel only from the addresses in the shop's admin_ip_allowlist
 * (Administration → Security), when it has any. The client's address is
 * the one Caddy saw (TRUSTED_PROXIES). Locked out: pos:admin-ip --clear on
 * the server.
 */
class AdminIpAllowlist
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $shop = $user instanceof User && $user->shop_id !== null ? Shop::query()->find($user->shop_id) : null;

        if ($shop !== null && ! self::allows($shop, (string) $request->ip())) {
            abort(403, 'The admin panel cannot be opened from this network ('.$request->ip().').');
        }

        return $next($request);
    }

    public static function allows(Shop $shop, string $ip): bool
    {
        $allowed = self::normalize($shop->setting('admin_ip_allowlist'));

        return $allowed === [] || IpUtils::checkIp($ip, $allowed);
    }

    /**
     * The valid addresses and ranges in a list or a text of one per line /
     * comma separated, trimmed, without duplicates.
     *
     * @return list<string>
     */
    public static function normalize(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $item): string => trim((string) $item), (array) $items),
            fn (string $item): bool => self::isValid($item),
        )));
    }

    public static function isValid(string $item): bool
    {
        if ($item === '') {
            return false;
        }

        [$address, $bits] = array_pad(explode('/', $item, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $max = str_contains((string) $address, ':') ? 128 : 32;

        return $bits === null || (ctype_digit($bits) && (int) $bits >= 0 && (int) $bits <= $max);
    }
}
