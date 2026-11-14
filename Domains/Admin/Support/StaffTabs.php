<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Navigation\NavigationItem;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarSetup,WhatYouSell};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource,OperationResource,RoutingProfileResource,TldResource,WatchResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\MonitoringSettings;
/** The tabs under each of the three menu items. Explicit, because generateNavigationItems() skips pages left out of the menu. */
final class StaffTabs
{
    /** One Domains area: the domains, monitoring and DNS templates in one tab row. Each tab keeps its own URL, so a reload or
     * a bookmark opens the same tab. Monitored domains carries the count the old Domain monitoring menu item had. A tab
     * has one link, so while the count shows, the tab opens the domains it counts, as the old menu count did.
     * @return list<NavigationItem> */
    public static function domains(string $active): array
    {
        $setup=fn()=>WatchResource::canViewAny() && \Illuminate\Support\Facades\Schema::hasTable('domains_domains') && \Illuminate\Support\Facades\Schema::hasTable('domains_accounts') && \Paymenter\Extensions\Servers\Domains\Services\ManagedDnsSetup::affected(time())->exists();
        return self::items($active,['All domains'=>[DomainResource::canViewAny(),fn()=>DomainResource::getUrl('index')],
            'Monitored domains'=>[WatchResource::canViewAny(),fn()=>WatchResource::attentionCount()===null?WatchResource::getUrl('index'):WatchResource::attentionUrl(),fn()=>WatchResource::attentionCount()],
            'DNS monitoring setup'=>[$setup(),fn()=>WatchResource::getUrl('dns-setup')],
            'DNS templates'=>[RoutingProfileResource::canViewAny(),fn()=>RoutingProfileResource::getUrl('index')],
            'Monitoring settings'=>[MonitoringSettings::canAccess(),fn()=>WatchResource::getUrl('settings')]]);
    }
    /** @return list<NavigationItem> */
    public static function registrars(string $active): array
    { return self::items($active,['Accounts'=>[RegistrarSetup::canAccess(),fn()=>RegistrarSetup::getUrl()],'Registrar requests'=>[OperationResource::canViewAny(),fn()=>OperationResource::getUrl('index')]]); }
    /** @return list<NavigationItem> */
    public static function tlds(string $active): array
    { return self::items($active,['TLDs'=>[TldResource::canViewAny(),fn()=>TldResource::getUrl('index')],'What you sell'=>[WhatYouSell::canAccess(),fn()=>WhatYouSell::getUrl()]]); }
    /** One tab left is no choice, so it draws no row. A page the panel never registered drops out. A tab may carry a count. */
    private static function items(string $active, array $tabs): array
    {
        $items=[];
        foreach ($tabs as $label=>$tab) {
            [$allowed,$url]=$tab;$count=$tab[2]??null;
            if (!$allowed) { continue; }
            try { $item=NavigationItem::make($label)->url($url())->isActiveWhen(fn()=>$label===$active); } catch (\Throwable) { continue; }
            $items[]=$count===null?$item:$item->badge($count());
        }
        return count($items)>1 && count(array_filter($items, static fn (NavigationItem $item): bool => $item->isActive())) === 1 ? $items : [];
    }
}
