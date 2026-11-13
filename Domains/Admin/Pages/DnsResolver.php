<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Head;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\SettingsPage;
use Paymenter\Extensions\Servers\Domains\Presentation\Reader;
/** The DNS resolver moved to Domains > Monitoring settings; this address redirects there, so it shows no sections. */
class DnsResolver extends SettingsPage
{
    protected static string|\UnitEnum|null $navigationGroup = 'Domains';
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $slug = 'dns-resolver';
    public static function canAccess(): bool { return Staff::can(PublicSuffixList::VIEW); }
    public function mount(): void { abort_unless(self::canAccess(), 403); $this->redirect(WatchResource::getUrl('settings')); }
    protected function head(): Head { return Head::make('DNS resolver', Reader::Staff); }
    protected function sections(): array { return []; }
}
