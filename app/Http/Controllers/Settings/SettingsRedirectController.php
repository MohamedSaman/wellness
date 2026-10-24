<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class SettingsRedirectController extends Controller
{
    /**
     * Send /settings to the profile page.
     */
    public function __invoke(): RedirectResponse
    {
        return redirect()->route('profile.edit');
    }
}
