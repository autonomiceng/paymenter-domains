<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page;
use Filament\Resources\Pages\Page;
/** A resource record's Detail page, such as a monitored domain. See Concerns\DetailType. */
abstract class RecordDetailPage extends Page implements PageType
{
    use Concerns\DetailType;
    use Concerns\ForRecord;
    protected const WIDE = false;
    protected const PAGE_CLASSES = ['domains-page-detail', 'fi-resource-view-record-page'];
}
