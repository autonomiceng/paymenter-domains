<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page;
use Filament\Pages\Page;
/** A Settings page that is not a resource page. See Concerns\SettingsType. */
abstract class SettingsPage extends Page implements PageType
{
    use Concerns\SettingsType;
    protected const WIDE = false;
    protected const PAGE_CLASSES = ['domains-page-settings'];
}
