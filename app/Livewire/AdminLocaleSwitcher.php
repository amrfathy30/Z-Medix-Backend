<?php

namespace App\Livewire;

use App\Models\Admin;
use Illuminate\View\View;
use Livewire\Component;

class AdminLocaleSwitcher extends Component
{
    private const SUPPORTED_LOCALES = ['ar', 'en'];

    public function switchLocale(string $locale): void
    {
        if (! in_array($locale, self::SUPPORTED_LOCALES, strict: true)) {
            return;
        }

        session(['admin_locale' => $locale]);

        /** @var Admin|null $admin */
        $admin = auth('admin')->user();
        $admin?->update(['locale' => $locale]);

        $this->redirect(request()->header('Referer') ?: filament()->getHomeUrl());
    }

    public function render(): View
    {
        return view('livewire.admin-locale-switcher');
    }
}
