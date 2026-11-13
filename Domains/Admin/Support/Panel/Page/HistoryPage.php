<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page;
use Filament\Pages\Page;
use Filament\Tables\Contracts\HasTable;
/** A History page that is not a resource page. See Concerns\HistoryType. */
abstract class HistoryPage extends Page implements PageType, HasTable
{
    use Concerns\HistoryType;
    protected const WIDE = false;
    protected const PAGE_CLASSES = ['domains-page-history'];
}
