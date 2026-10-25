<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * /dashboard: owners (anyone who may use the admin panel) go to the admin
     * dashboard; everyone else gets a short welcome, with a way to the till
     * when they may sell. Signing in still takes a cashier straight to the
     * till (User::homePath()).
     */
    public function __invoke(Request $request): RedirectResponse|Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->can('admin.access')) {
            return redirect('/admin');
        }

        return Inertia::render('Dashboard', [
            'firstName' => (string) strtok(trim($user->name), ' '),
            'greeting' => self::greeting($user),
            'canUsePos' => $user->can('pos.checkout'),
        ]);
    }

    /** Good morning, afternoon or evening by the shop's clock (Asia/Colombo for the clinic). */
    public static function greeting(User $user): string
    {
        $hour = now(app(CurrentShop::class)->get()->timezone ?? config('app.timezone'))->hour;

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }
}
