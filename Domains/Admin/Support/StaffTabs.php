<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Navigation\NavigationItem;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarSetup,WhatYouSell};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource,OperationResource,RoutingProfileResource,TldResource,WatchResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\MonitoringSettings;
/** The tabs under each of the four menu items. Explicit, because generateNavigationItems() skips pages left out of the menu. */
final class StaffTabs
{
    /** @return list<NavigationItem> */
    public static function domains(string $active): array
    { return self::items($active,['Domains'=>[DomainResource::canViewAny(),fn()=>DomainResource::getUrl('index')],'DNS templates'=>[RoutingProfileResource::canViewAny(),fn()=>RoutingProfileResource::getUrl('index')]]); }
    /** @return list<NavigationItem> */
    public static function registrars(string $active): array
    { return self::items($active,['Accounts'=>[RegistrarSetup::canAccess(),fn()=>RegistrarSetup::getUrl()],'Registrar requests'=>[OperationResource::canViewAny(),fn()=>OperationResource::getUrl('index')]]); }
    /** @return list<NavigationItem> */
    public static function tlds(string $active): array
    { return self::items($active,['TLDs'=>[TldResource::canViewAny(),fn()=>TldResource::getUrl('index')],'What you sell'=>[WhatYouSell::canAccess(),fn()=>WhatYouSell::getUrl()],'Price list'=>[Staff::can(Staff::COSTS),fn()=>TldResource::getUrl('price-list')]]); }
    /** @return list<NavigationItem> */
    public static function monitoring(string $active): array
    { return self::items($active,['Monitored domains'=>[WatchResource::canViewAny(),fn()=>WatchResource::getUrl('index')],'DNS monitoring setup'=>[WatchResource::canViewAny() && \Illuminate\Support\Facades\Schema::hasTable('domains_domains') && \Illuminate\Support\Facades\Schema::hasTable('domains_accounts') && \Paymenter\Extensions\Servers\Domains\Services\ManagedDnsSetup::affected(time())->exists(),fn()=>WatchResource::getUrl('dns-setup')],'Settings'=>[MonitoringSettings::canAccess(),fn()=>WatchResource::getUrl('settings')]]); }
    /** One tab left is no choice, so it draws no row. A page the panel never registered drops out. */
    private static function items(string $active, array $tabs): array
    {
        $items=[];
        foreach ($tabs as $label=>[$allowed,$url]) {
            if (!$allowed) { continue; }
            try { $items[]=NavigationItem::make($label)->url($url())->isActiveWhen(fn()=>$label===$active); } catch (\Throwable) { continue; }
        }
        return count($items)>1?$items:[];
    }
}
