<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Paymenter\Extensions\Servers\Domains\Services\HookRegistration;
/** Layout Domains staff pages need on a plain Filament install, and the keyboard reveal of cut one-line text. Neutral
 * colours only, through a skin's tokens when it has them: the look belongs to a skin. */
final class StaffStyles
{
    public static function boot(): void
    {
        if (!defined(PanelsRenderHook::class.'::STYLES_AFTER') || !HookRegistration::claim(FilamentView::getFacadeRoot(), self::class)) { return; }
        FilamentView::registerRenderHook(PanelsRenderHook::STYLES_AFTER, static fn (): string => view('domains::partials.staff-styles')->render());
        FilamentView::registerRenderHook(PanelsRenderHook::BODY_END, static fn (): string => view('domains::partials.staff-reveal')->render());
    }
}
