<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * "/": nothing of its own. A guest signs in; anyone signed in goes where
     * signing in takes them (User::homePath(): owners to the admin panel,
     * cashiers to the till, anyone else to /dashboard). Every shop alike.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        return redirect($user instanceof User ? $user->homePath() : route('login'));
    }
}
