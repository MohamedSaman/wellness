<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A Sanctum token that lets one registered till sync while nobody's session
 * is involved. The till is looked up without the shop scope because the shop
 * is only known once the token has identified the till.
 */
class DeviceToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    /** @return MorphTo<Model, $this> */
    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable')->withoutGlobalScope('shop');
    }
}
