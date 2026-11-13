<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Panel;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{RegistrarPage, Staff, TldFacts};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\HistoryPage;
/** The History tab of a registrar account: its settings and price changes, newest first. */
class RegistrarHistory extends HistoryPage
{
    use RegistrarPage;
    protected static ?string $slug = 'registrar-account-history';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
    public static function canAccess(): bool { return Staff::can(Staff::POLICY); }
    public static function getRoutePath(Panel $panel): string { return '/registrar-account/{account}/history'; }
    protected function events(): ?array { return TldFacts::accountHistory($this->account); }
}
