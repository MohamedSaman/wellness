<?php

namespace Tests\Feature\Pos\Concerns;

use App\Models\User;
use Filament\Widgets\Widget;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Finder\Finder;

/**
 * Every widget class under app/Filament/Widgets, booted and rendered: a
 * property or trait clash is a fatal error at class load, and an empty shop
 * must render too, so each one is mounted with whatever data the test has.
 */
trait BootsDashboardWidgets
{
    /** @return list<class-string<Widget>> */
    protected function dashboardWidgets(): array
    {
        $widgets = [];

        foreach (Finder::create()->files()->in(app_path('Filament/Widgets'))->name('*.php')->exclude('Concerns') as $file) {
            $class = 'App\\Filament\\Widgets\\'.Str::of($file->getRelativePathname())->replace(['/', '.php'], ['\\', ''])->toString();

            if (is_subclass_of($class, Widget::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                $widgets[] = $class;
            }
        }

        sort($widgets);

        return $widgets;
    }

    /** Renders every widget the user may see; returns how many rendered. */
    protected function bootEveryWidget(User $user): int
    {
        $this->actingAs($user);
        Livewire::withoutLazyLoading();
        $rendered = 0;

        foreach ($this->dashboardWidgets() as $widget) {
            if (! $widget::canView()) {
                continue;
            }

            Livewire::test($widget)->assertOk();
            $rendered++;
        }

        return $rendered;
    }
}
