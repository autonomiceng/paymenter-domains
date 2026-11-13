<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{WatchReadResource,WatchFindings};
use Filament\Pages\Enums\SubNavigationPosition;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,ListManagedDns,ListDnsFailures,MonitoringSettings,ViewWatch};
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
class WatchResource extends WatchReadResource
{
    protected static ?string $model=WatchName::class;
    /** A tab of the Domains area (StaffTabs::domains), not its own menu item. */
    protected static ?string $navigationLabel='Monitored domains';
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-eye';
    protected static bool $shouldRegisterNavigation=false;
    protected static ?string $modelLabel='monitored domain';
    protected static ?string $pluralModelLabel='Monitored domains';
    protected static ?string $breadcrumb='Monitored domains';
    protected static ?string $slug='monitored-domains';
    protected static ?SubNavigationPosition $subNavigationPosition=SubNavigationPosition::Top;
    public static function getEloquentQuery(): Builder{return parent::getEloquentQuery()->with(['delegation','registry','work']);}
    /** Active domains needing attention, first 1,000: the count on the Monitored domains tab. Null at zero, so the tab shows none. */
    public static function attentionCount(): ?string
    {
        if(!static::canViewAny()){return null;}
        return \Paymenter\Extensions\Servers\Domains\Admin\Support\NavigationCount::read('monitoring',fn()=>count(WatchFindings::statusIds('attention')));
    }
    /** Where the count leads: the list filtered to the domains that need attention. */
    public static function attentionUrl(): string{return static::getUrl('index',['filters'=>['status'=>['value'=>'attention']]]);}
    public static function getPages(): array{return ['index'=>ListWatches::route('/'),'dns-failures'=>ListDnsFailures::route('/affected-dns-checks'),'dns-setup'=>ListManagedDns::route('/dns-monitoring-setup'),'settings'=>MonitoringSettings::route('/monitoring-settings'),'view'=>ViewWatch::route('/{record}'),'history'=>WatchResource\Pages\WatchHistory::route('/{record}/history')];}
}
