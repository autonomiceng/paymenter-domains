<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\{EmbeddedTable,RenderHook};
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DefaultNameservers,Sheet,Staff,WatchActions};
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,StaffWatchWrites as Writes};
/** Domains > DNS templates: the default nameservers for new domains on top, the templates they come from below. */
class ListConfiguration extends ListRecords
{
    protected static string $resource=RoutingProfileResource::class;
    protected static ?\Filament\Pages\Enums\SubNavigationPosition $subNavigationPosition=\Filament\Pages\Enums\SubNavigationPosition::Top;
    /** The default template and the revision this page shows; an edit from a stale page is refused. */
    #[Locked] public int $defaultId=0;
    #[Locked] public int $defaultRevision=0;
    public function mount(): void{parent::mount();$this->loadDefault();}
    private function loadDefault(): void{$p=SiteDnsDefault::profile();$this->defaultId=(int)($p?->id??0);$this->defaultRevision=(int)($p?->revision??0);}
    public function getSubNavigation(): array { return \Paymenter\Extensions\Servers\Domains\Admin\Support\StaffTabs::domains('DNS templates'); }
    public function getTitle(): string{return 'DNS templates';}
    public function getBreadcrumbs(): array{return [];}
    public function getSubheading(): ?string{return 'Each template is a saved set of nameservers, website and email records. Monitoring can check domains against any template that is on.';}
    protected function getHeaderActions(): array{return [WatchActions::configuration(null)];}
    /** Called after the default or its template is saved here. */
    public function refreshTemplate(): void{$this->loadDefault();unset($this->cachedSchemas['content']);$this->cachedActions=[];$this->cachedHeaderActions=[];$this->cacheInteractsWithHeaderActions();}
    /** Edit the default template in place. The page's revision binds the write, like the template's own page. Hidden with no default.
     * Here it changes the default nameservers, so it also needs the right to change them. */
    public function editDefaultAction(?WatchProfile $p=null): Action
    {
        $p??=$this->defaultId?WatchProfile::find($this->defaultId):null;
        return $p?Sheet::edit(WatchActions::configuration($p,$this->defaultRevision,$this->defaultId)->name('editDefault'),'default nameservers')
            ->visible(fn()=>Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE)&&Staff::canEditDefaultDns()):Action::make('editDefault')->hidden();
    }
    public function siteDefaultAction(): Action{return DefaultNameservers::choose(fn()=>$this->refreshTemplate());}
    public function content(Schema $schema): Schema
    {
        $default=$this->defaultId?WatchProfile::find($this->defaultId):null;
        return $schema->components([
            DefaultNameservers::card($default,$default?$this->editDefaultAction($default):null,$this->siteDefaultAction()),
            $this->getTabsContentComponent(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }
}
