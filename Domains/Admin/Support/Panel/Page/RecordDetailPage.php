<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page;
use Filament\Resources\Pages\Concerns\HasRelationManagers;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs\Tab;
/** A resource record's Detail page, such as a monitored domain. See Concerns\DetailType.
 * Beyond the Detail type it can: keep the resource's relation managers (History) as tabs after the sheet, which stays
 * the Overview tab, unchanged and native, with their ?relation= links; poll at the page's own cadence (2 or 10 seconds,
 * kept alive while the page follows a change, then stopped); and place a shared partial view in a section (Panel\Partial). */
abstract class RecordDetailPage extends Page implements PageType
{
    use Concerns\DetailType;
    use Concerns\ForRecord;
    use HasRelationManagers;
    protected const WIDE = false;
    protected const PAGE_CLASSES = ['domains-page-detail', 'fi-resource-view-record-page'];
    private ?Component $overview = null;
    /** The sheet alone, or the sheet as the Overview tab beside the relation managers this reader can see. */
    final protected function withRelations(Component $sheet): Component
    {
        if (!isset($this->record) || $this->getCachedRelationManagers() === []) { return $sheet; }
        $this->overview = $sheet;
        return $this->getRelationManagersContentComponent();
    }
    final public function hasCombinedRelationManagerTabsWithContent(): bool { return true; }
    final public function getContentTabLabel(): ?string { return 'Overview'; }
    final public function getContentTabComponent(): Tab { return Tab::make($this->getContentTabLabel())->schema(fn (): array => $this->overview === null ? [] : [$this->overview]); }
}
