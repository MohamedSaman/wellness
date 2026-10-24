<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * /dashboard is only a signpost: owners go to the admin dashboard, cashiers
     * to the till, and anyone with neither role to their profile settings.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $home = $user->homePath();

        return redirect($home === '/dashboard' ? route('profile.edit') : $home);
    }
}
