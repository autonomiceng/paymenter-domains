<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page;
use Filament\Resources\Pages\Page;
use Filament\Tables\Contracts\HasTable;
/** A resource record's History tab. See Concerns\HistoryType. */
abstract class RecordHistoryPage extends Page implements PageType, HasTable
{
    use Concerns\HistoryType;
    use Concerns\ForRecord;
    protected const WIDE = false;
    protected const PAGE_CLASSES = ['domains-page-history'];
}
