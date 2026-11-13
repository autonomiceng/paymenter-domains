<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page;
use Filament\Pages\Page;
use Filament\Tables\Contracts\HasTable;
/** A List page over rows the page reads. See Concerns\ListType. */
abstract class ListPage extends Page implements PageType, HasTable
{
    use Concerns\ListType;
    protected const WIDE = true;
    protected const PAGE_CLASSES = ['domains-page-list', 'fi-resource-list-records-page'];
}
