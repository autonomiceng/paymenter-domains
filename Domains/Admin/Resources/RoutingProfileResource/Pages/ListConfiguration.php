<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages;
use Filament\Resources\Pages\ListRecords;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\WatchActions;
class ListConfiguration extends ListRecords
{
    protected static string $resource=RoutingProfileResource::class;
    protected static ?\Filament\Pages\Enums\SubNavigationPosition $subNavigationPosition=\Filament\Pages\Enums\SubNavigationPosition::Top;
    public function getSubNavigation(): array { return \Paymenter\Extensions\Servers\Domains\Admin\Support\StaffTabs::domains('DNS templates'); }
    public function getTitle(): string{return 'DNS templates';}
    public function getBreadcrumbs(): array{return [];}
    public function getSubheading(): ?string{return 'New domains use: '.(\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::profile()?->name??'Missing. Choose a template in Site DNS settings').'. On templates can be used for monitoring.';}
    protected function getHeaderActions(): array{return [WatchActions::configuration(null)];}
}
