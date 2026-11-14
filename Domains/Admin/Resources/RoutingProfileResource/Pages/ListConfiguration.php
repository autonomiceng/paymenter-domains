<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages;
use Filament\Actions\Action;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DefaultNameservers,Sheet,Staff,StaffTabs,WatchActions};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head,Row,SettingsRow,Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{Column,RecordListPage,Section};
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact,Reader,State};
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,StaffWatchWrites as Writes};
/** Domains > DNS templates, a List page: the default nameservers for new domains on top, the templates they come from below. */
class ListConfiguration extends RecordListPage
{
    protected static string $resource=RoutingProfileResource::class;
    /** The default template and the revision this page shows; an edit from a stale page is refused. */
    #[Locked] public int $defaultId=0;
    #[Locked] public int $defaultRevision=0;
    /** Request-scoped marks of the templates the site default can't use; protected, so never part of the Livewire snapshot. */
    protected ?array $marks=null;
    public function mount(): void{parent::mount();$this->loadDefault();}
    private function loadDefault(): void{$p=SiteDnsDefault::profile();$this->defaultId=(int)($p?->id??0);$this->defaultRevision=(int)($p?->revision??0);}
    public function getSubNavigation(): array{return StaffTabs::domains('DNS templates');}
    public function getBreadcrumbs(): array{return [];}
    protected function head(): Head{return Head::make('Domains',Reader::Staff);}
    protected function actions(): array{return [WatchActions::configuration(null)];}
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
    /** The nameservers new domains get and the template they come from, with the two ways to change them. */
    protected function summary(): array
    {
        $default=$this->defaultId?WatchProfile::find($this->defaultId):null;$staff=Reader::Staff;
        $canEdit=Staff::canEditDefaultDns();$refusal=SiteDnsDefault::refusal($canEdit);
        $problem=$refusal??($default?null:($canEdit?SiteDnsDefault::MISSING:'Default nameservers are Missing. New domains have no nameservers to use. '.SiteDnsDefault::ASK));
        $who='Set by staff in Domains > DNS templates.';
        // A default a registrar refuses still shows its nameservers and Edit, which is one of its two fixes.
        $names=SettingsRow::setting('Nameservers',$default?Value::fact(Fact::lines((array)$default->nameservers),$refusal?null:'New registrations get these. Transfers keep their own. Monitored domains without a template of their own are checked against them.'):Value::state(State::NotSet,$staff),$who);
        if($default){$names->actions($this->editDefaultAction($default));}
        $template=SettingsRow::setting('From template',$default?Value::fact(Fact::names([$default->name])):Value::state(State::NotSet,$staff),$who)->actions($this->siteDefaultAction());
        return [Section::make(DefaultNameservers::TITLE,[
            ...($problem?[Row::notice('default-nameservers',Value::state(State::NotSet,$staff,$problem))]:[]),
            $names,$template,
        ],null,'default-nameservers')];
    }
    private function mark(int $id): ?string{$this->marks??=SiteDnsDefault::marks();return $this->marks[$id]??null;}
    protected function columns(): array
    {
        // The detail page has the monitoring switch. Only the single site choice supplies new registrations.
        // Saved references that cannot be used are marked too, matching the guard that keeps them.
        return [
            Column::value('name','Template',fn(WatchProfile $p): Value=>Value::fact(Fact::names([$p->name]),$this->mark((int)$p->id))),
            // A retained template the editor would refuse reads Couldn't read, the same check as its detail page.
            Column::value('nameservers','Nameservers',fn(WatchProfile $p): Value=>($r=$p->readProfile())&&($lines=Fact::lines($r->nameservers))?Value::fact($lines):Value::state(State::CouldntRead,Reader::Staff)),
            Column::value('enabled','Status',fn(WatchProfile $p): Value=>Value::state($p->enabled?State::Active:State::Paused,Reader::Staff,null,'template-'.$p->id)),
        ];
    }
    protected function emptyLine(): array{return ['No DNS templates','Add one with Add DNS template.','No DNS templates match'];}
}
