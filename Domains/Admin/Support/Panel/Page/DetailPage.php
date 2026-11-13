<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page;
use Filament\Pages\Page;
/** A Detail page that is not a resource page, such as a registrar account. See Concerns\DetailType. */
abstract class DetailPage extends Page implements PageType
{
    use Concerns\DetailType;
    protected const WIDE = false;
    protected const PAGE_CLASSES = ['domains-page-detail', 'fi-resource-view-record-page'];
}
