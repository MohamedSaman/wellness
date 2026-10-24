<?php

namespace App\Http\Requests\Pos\Sync;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

/** A request from a till authenticated by its device token (see AuthenticateTill). */
abstract class TillSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('device') instanceof Device;
    }

    public function device(): Device
    {
        $device = $this->attributes->get('device');
        abort_unless($device instanceof Device, 401);

        return $device;
    }
}
