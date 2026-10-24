<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Pages\Page;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
/** The DNS resolver moved to Domain monitoring > Settings; this address redirects there. */
class DnsResolver extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Domains';
    protected static ?string $title = 'DNS resolver';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $slug = 'dns-resolver';
    public static function canAccess(): bool { return Staff::can(PublicSuffixList::VIEW); }
    public function mount(): void { abort_unless(self::canAccess(), 403); $this->redirect(WatchResource::getUrl('settings')); }
}
