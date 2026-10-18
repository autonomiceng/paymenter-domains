<?php
// Adapted from Enom 444f31c DomainCounts; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages\Widgets;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainQueries, Staff};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Dormant until explicitly registered. No polling or provider calls. */
class DomainCounts extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;
    public static function canView(): bool { return Staff::can(); }
    protected function getStats(): array
    {
        Staff::requireView();
        return [Stat::make('Domains',(string)Domain::count()),
            Stat::make('Expiring in 30 days',(string)Domain::whereDate('provider_expires_at','>=',today())->whereDate('provider_expires_at','<=',today()->addDays(30))->count()),
            Stat::make('On hold or unconfirmed',(string)DomainQueries::attention(Domain::query())->count())];
    }
}
