<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Navigation\NavigationItem;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarSetup,WhatYouSell};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource,OperationResource,RoutingProfileResource,TldResource};
use Paymenter\Extensions\Servers\Domains\Admin\Pages\DnsResolver;
/** The tabs under each of the three menu items. Explicit, because generateNavigationItems() skips pages left out of the menu. */
final class StaffTabs
{
    /** @return list<NavigationItem> */
    public static function domains(string $active): array
    {
        return self::items($active,['All domains'=>[DomainResource::canViewAny(),fn()=>DomainResource::getUrl('index')],
            'DNS templates'=>[RoutingProfileResource::canViewAny(),fn()=>RoutingProfileResource::getUrl('index')],
            'DNS resolver'=>[DnsResolver::canAccess(),fn()=>DnsResolver::getUrl()]]);
    }
    /** @return list<NavigationItem> */
    public static function registrars(string $active): array
    { return self::items($active,['All accounts'=>[RegistrarSetup::canAccess(),fn()=>RegistrarSetup::getUrl()],'Registrar requests'=>[OperationResource::canViewAny(),fn()=>OperationResource::getUrl('index')]]); }
    /** @return list<NavigationItem> */
    public static function tlds(string $active): array
    { return self::items($active,['All TLDs'=>[TldResource::canViewAny(),fn()=>TldResource::getUrl('index')],'What you sell'=>[WhatYouSell::canAccess(),fn()=>WhatYouSell::salesUrl()]]); }
    /** @return list<NavigationItem> */
    public static function tldCatalogue(string $active,array $scope): array
    {
        $allowed=TldResource::canViewAny();
        return self::items($active,[
            'All TLDs'=>[$allowed,fn()=>TldResource::getUrl('index',$scope)],
            'For sale'=>[$allowed,fn()=>TldResource::getUrl('index',[...$scope,'filters'=>[...($scope['filters']??[]),'for_sale'=>['isActive'=>1]]])],
        ]);
    }
    /** One tab left is no choice, so it draws no row. A page the panel never registered drops out. */
    private static function items(string $active, array $tabs): array
    {
        $items=[];
        foreach ($tabs as $label=>[$allowed,$url]) {
            if (!$allowed) { continue; }
            try { $item=NavigationItem::make($label)->url($url())->isActiveWhen(fn()=>$label===$active); } catch (\Throwable) { continue; }
            $items[]=$item;
        }
        return count($items)>1 && count(array_filter($items, static fn (NavigationItem $item): bool => $item->isActive())) === 1 ? $items : [];
    }
}
