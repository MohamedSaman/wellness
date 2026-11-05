<?php

namespace App\Filament\Resources\Products\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Form;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;
use Illuminate\Support\Str;

/** What the product's Create and Edit pages share around the form. */
trait ProductFormPage
{
    public static bool $formActionsAreSticky = true;

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * novalidate: the browser would otherwise stop a missing required field
     * with its own bubble, one at a time, before the server ever saw the form.
     * This way every error comes back at once, on its field, and can be
     * counted. The rules are the same, they are the server's.
     *
     * Filament opens only the section holding the first error, so this opens
     * all of them, by the same `expand` event.
     */
    public function getFormContentComponent(): Component
    {
        $component = parent::getFormContentComponent();

        if (! $component instanceof Form) {
            return $component;
        }

        return $component->extraAttributes([
            'novalidate' => true,
            'x-data' => '{}',
            'x-on:form-validation-error.window' => <<<'JS'
                $nextTick(() => $el.querySelectorAll('[data-validation-error]').forEach((error) => {
                    for (let element = error; element; element = element.parentNode) {
                        element.dispatchEvent(new CustomEvent('expand'))
                    }
                }))
                JS,
        ]);
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [...parent::getFormActions(), $this->getJumpToFirstErrorAction()];
    }

    /** Filament's own handler for a failed save opens the first error and scrolls to it. */
    protected function getJumpToFirstErrorAction(): Action
    {
        return Action::make('jumpToFirstError')
            ->label(function (): string {
                $errors = $this->getErrorBag()->count();

                return $errors.' '.Str::plural('error', $errors).' · Jump to first error';
            })
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('danger')
            ->link()
            ->visible(fn (): bool => $this->getErrorBag()->isNotEmpty())
            ->alpineClickHandler('$dispatch(\'form-validation-error\', { livewireId: '.Js::from($this->getId()).' })');
    }
}
