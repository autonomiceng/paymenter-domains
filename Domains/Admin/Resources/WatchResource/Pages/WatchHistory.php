<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages;
use Filament\Pages\Enums\SubNavigationPosition;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{WatchFacts, WatchPage};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\RecordHistoryPage;
/** The History tab of a monitored domain, newest first. */
class WatchHistory extends RecordHistoryPage
{
    use WatchPage;
    protected static string $resource = WatchResource::class;
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
    protected function events(): ?array
    {
        $rows = WatchFacts::history($this->getRecord());
        return $rows === null ? null : array_map(static fn (array $r): array => ['time' => $r['time'], 'change' => $r['event'], 'reason' => (string) $r['note'], 'by' => $r['by']], $rows);
    }
}
