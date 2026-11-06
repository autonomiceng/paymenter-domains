<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;
use App\Models\Server;
use Filament\Actions\{Action,ActionGroup};
use Filament\Infolists\Components\TextEntry;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\{Schema,Components\Actions,Components\Flex,Components\Html,Components\Text};
use Filament\Support\Enums\{FontFamily,FontWeight,Width};
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{CatalogueSalesNotice,AccountChecklist,BalanceCheck,HelpHint,LiveActivationAction,MissingEntry,OnOffSwitch,PslActions,RegistrarFacts,RequestFacts,SettingsActions,Sheet,Staff,TldFacts,StatusMark};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Tld};
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,StaffSettingsReview};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** One registrar account (a Domains server): status, balance and setup. Reached from the Registrars list. */
class RegistrarAccount extends Page
{
    protected static ?string $slug='registrar-account';
    protected static bool $shouldRegisterNavigation=false;
    protected static ?SubNavigationPosition $subNavigationPosition=SubNavigationPosition::Top;
    protected Width|string|null $maxContentWidth=Sheet::WIDTH;
    #[Locked] public int $account=0;
    public string $reviewStamp='';
    /** The switch a link came for (RegistrarFacts::SWITCHES). */
    #[Locked] public ?string $switch=null;
    private ?array $status=null;
    public static function canAccess(): bool { return Staff::can(); }
    public static function getRoutePath(Panel $panel): string { return '/'.static::getSlug($panel).'/{account}'; }
    public function mount(int $account): void
    {
        Staff::requireView();
        abort_unless(Server::where('extension','Domains')->whereKey($account)->exists(),404);
        $this->account=$account;$this->reviewStamp=StaffSettingsReview::stamp($account,null);
        $switch=request()->query('switch');$this->switch=is_string($switch)&&isset(RegistrarFacts::SWITCHES[$switch])?$switch:null;
    }
    protected function server(): Server { return Server::where('extension','Domains')->findOrFail($this->account); }
    private function record(): ?Account { return Account::where('server_id',$this->account)->first(); }
    /** @return array{label:string,tone:string,reason:?string} */
    private function status(): array { return $this->status??=RegistrarFacts::status($this->server(),$this->record()); }
    public function getTitle(): string { Staff::requireView(); return (string)$this->server()->name; }
    public function getHeading(): HtmlString
    {
        $a=$this->record();
        $test=$a&&RegistrarFacts::mode($a)==='Test';
        return new HtmlString(e($this->getTitle()).($test||($a&&DemoData::is($a))?DemoData::tag(inline:true,test:$test)->toHtml():''));
    }
    public function getSubheading(): string|Htmlable|null
    { $status=$this->status();return StatusMark::line($status['tone'],$status['label'],'registrar-'.$this->account,$status['reason']); }
    /** @return list<NavigationItem> */
    public function getSubNavigation(): array
    {
        $id=['account'=>$this->account];$history=$this instanceof RegistrarHistory;
        try { $url=RegistrarHistory::getUrl($id); } catch (\Throwable) { return []; }
        return [NavigationItem::make('Overview')->url(RegistrarAccount::getUrl($id))->isActiveWhen(fn()=>!$history),
            NavigationItem::make('History')->url($url)->isActiveWhen(fn()=>$history)->visible(RegistrarHistory::canAccess())];
    }
    public function getBreadcrumbs(): array { return [RegistrarSetup::getUrl()=>'Registrars',$this->getTitle()]; }
    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);
        // Writers load their own models. Rebuild the page so the next change needs no reload.
        $this->status=null;$this->reviewStamp=StaffSettingsReview::stamp($this->account,null);
        $this->resetContent();$this->cachedActions=[];$this->cachedHeaderActions=[];
        $this->cacheInteractsWithHeaderActions();$this->forceRender();
    }
    protected function getHeaderActions(): array
    {
        $id=$this->account;$setup=fn(string $name)=>SettingsActions::setup($id,$this->reviewStamp,$name);
        if (!$this->record()) { return [$setup('configure'),$setup('enroll_sandbox')]; }
        return [...($this->record()->environment==='test'&&!$this->record()->processing_enabled?[$setup('processing')->name('activate_test')->label('Activate')]:[]),ActionGroup::make([$setup('configure')])
            ->label('More')->button()->outlined()->color('gray')->size('sm')->icon('heroicon-m-chevron-down')->iconPosition('after')];
    }
    /** Rebuild the page after a System checks action changed what it shows. */
    private function resetContent(): void { unset($this->cachedSchemas['content']); }
    public function checkBalance(): void { BalanceCheck::run($this->account); }
    public function checkBalanceAction(): Action
    { return Sheet::button(Action::make('checkBalance')->label('Check balance')->visible(fn()=>RegistrarFacts::connected($this->record(),$this->status()))->action(fn()=>$this->checkBalance())); }
    public function content(Schema $schema): Schema
    {
        Staff::requireView();$s=$this->server();$a=$this->record();
        $sections=[];$checks=$this->checks();
        if ($a) {
            $id=(int)$s->id;
            $rows=array_column([...AccountChecklist::setup($a,$this->status()),...AccountChecklist::renewals($id),AccountChecklist::whois($a)??self::fact('WHOIS privacy','Free when supported by the TLD.')],null,'label');
            $pick=fn(string ...$labels)=>array_values(array_filter(array_map(fn(string $label)=>$rows[$label]??null,$labels)));
            $sections=[Sheet::section('Account',$this->rows('account',[...$this->accountRows($a),...$pick('API connection')]))];
            if(LiveActivationAction::offered($a)){
                $sections[]=Sheet::section('Live sales',[Html::make(fn()=>LiveActivationAction::summary($a)),Actions::make([LiveActivationAction::make($a,$this->reviewStamp)])],key:'live_sales');
            }
            $funds=RegistrarFacts::connected($a,$this->status())?RegistrarFacts::balance($a)['funds']:null;
            $sections[]=Sheet::section('Balance and usage',$this->rows('usage',$this->usageRows($s,$a)),after:$funds?[Sheet::link('add_funds','Add funds',$funds,external:true)]:[]);
            $sections[]=Sheet::section('Sales',$this->rows('sales',$pick('Orders','Registrations',AccountChecklist::TRANSFER_LABEL,'What you sell','TLD prices','Customer pays','Card fee')));
            $sections[]=Sheet::section('Renewals',$this->rows('renewal',$pick('Renewals','Late renewal','Customer can renew early','Invoice issued','Invoice due','Unpaid hold','Scheduled renewals','Selected TLD terms','Reminder emails','Reminder schedule')));
            $default=SiteDnsDefault::profile();$refusal=SiteDnsDefault::refusal();
            $sections[]=Sheet::section('Domain settings',$this->rows('settings',[...$pick('DNS, nameservers and contacts','WHOIS privacy'),
                self::fact('Site DNS default',$refusal?null:$default?->name,$refusal??($default?null:'Choose one in Domain monitoring settings.'))]));
        } else {
            $sections[]=Sheet::section('Account',$this->rows('account',[self::fact('Registrar',null,'Account not set up yet. Use Connect account.'),self::fact('Domains',(string)Domain::where('server_id',$s->id)->count())]));
        }
        $steps=array_map(fn(array $r)=>['label'=>$r['label'],'value'=>$r['value']??Missing::LABEL,'tone'=>$r['tone'],'detail'=>$r['cause'],'help'=>$r['help'],'buttons'=>$r['buttons']??[]],[...$checks['failing'],...$checks['passing']]);
        $ok=count($checks['passing']).' of '.count($steps).' OK';
        return $schema->components([...CatalogueSalesNotice::components(),Sheet::split($sections,[Sheet::section('System checks',[Sheet::trace($steps)],key:'system_checks',after:[Text::make($ok)])],'account_'.$this->account)]);
    }
    /** System checks for this account, failing first. @return array{failing:list<array>,passing:list<array>} */
    public function checks(): array
    {
        $system=AccountChecklist::system();$a=$this->record();
        if (Staff::can(PslActions::VIEW)) { $psl=PslActions::row(fn()=>$this->resetContent());if(in_array($psl['tone'],['danger','warning'],true)){$system['failing'][]=$psl;} }
        if ($a) {
            $runtime=AccountChecklist::runtime($a);$system[$runtime['value']===null?'failing':'passing'][]=$runtime;
            $alerts=AccountChecklist::alerts($a);$system[$alerts['tone']==='success'?'passing':'failing'][]=$alerts;

        }
        return $system;
    }
    /** A value with no fix of its own. */
    private static function fact(string $label,?string $value,string|Htmlable|null $cause=null,?string $help=null): array
    { return ['label'=>$label,'value'=>$value,'tone'=>null,'cause'=>$cause,'action'=>null,'help'=>$help]; }
    /** Rows with a value and their action column. A value of On or Off gets a switch. Missing shows in danger with its cause. */
    private function rows(string $prefix,array $rows): array

    {
        $out=[];
        foreach ($rows as $i=>$r) {
            $key=$prefix.'_'.$i;$entry=$this->entry($key,$r);
            $switch=in_array($r['value'],['On','Off'],true)&&(!empty($r['buttons'])||$r['action']||$r['cause']);
            $out[]=$this->anchored($switch?$this->switchRow($entry,$r):Sheet::row($entry,$this->side($r)),$r['label']);
        }
        return $out;
    }
    /** A switch row has its anchor. A link that came for it marks it and brings it into view, also when SPA navigation leaves :target unset. It waits two frames because SPA navigation scrolls to the top after Alpine starts. */
    private function anchored(Flex $row,string $label): Flex
    {
        $name=array_search($label,RegistrarFacts::SWITCHES,true);
        if ($name===false) { return $row; }
        return $row->extraAttributes(['id'=>'switch-'.$name,...($name===$this->switch?['class'=>'domains-row-target','x-init'=>"requestAnimationFrame(()=>requestAnimationFrame(()=>\$el.scrollIntoView({block:'center'})))"]:[])],merge:true);
    }
    /** A row's action column. Rows that change a saved value say Edit. */
    private function side(array $r): array
    {
        $edit=$r['edit']??false;
        if (!empty($r['buttons'])) { return $edit?[Sheet::edit($r['buttons'][0],$r['label'])]:$r['buttons']; }
        if (!$r['action']) { return []; }
        return [$edit||!in_array($r['action'],['probe','configure'],true)?Sheet::edit($this->setup($r['action']),$r['label']):Sheet::button($this->setup($r['action']))];
    }
    private function setup(string $name): Action { return SettingsActions::setup($this->account,$this->reviewStamp,$name); }
    private function entry(string $key,array $r): TextEntry
    {
        $word=in_array($r['value'],['On','Off'],true);
        $entry=MissingEntry::apply(TextEntry::make($key)->label($r['label'])->state($r['value']),cause:$r['value']===null?$r['cause']:null);
        if ($r['value']!==null&&$r['tone']!==null&&!$word) { Sheet::state($entry,$r['tone'],'registrar-'.$this->account.'-'.$key); }
        if ($r['value']!==null&&$r['cause']!==null) { $entry->belowContent(is_string($r['cause'])&&in_array($r['tone'],['danger','warning'],true)?RegistrarFacts::tone($r['cause'],$r['tone']):$r['cause']); }
        if ($r['help']) { $entry->hintAction(HelpHint::make($r['help'])); }
        if ($r['mono']??false) { $entry->fontFamily(FontFamily::Mono); }
        if ($r['copy']??false) { $entry->copyable(); }
        if ($r['money']??false) { $entry->weight(FontWeight::SemiBold)->extraAttributes(['class'=>'domains-money']); }
        return $entry;
    }
    /** One that can't be used here shows its state only, and says why under the value. */
    private function switchRow(TextEntry $entry,array $r): Flex
    {
        $on=$r['value']==='On';$action=$r['buttons'][0]??($r['action']?$this->setup($r['action']):null);
        return Sheet::row($entry,$action?->isVisible()?[OnOffSwitch::apply($action,$on,$r['label'],false)]:Html::make(OnOffSwitch::html($on,$r['label'],false)));
    }
    private function accountRows(Account $a): array
    {
        $rows=[self::fact('Registrar',TldFacts::registrar($a->registrar_key)),self::fact('Mode',RegistrarFacts::mode($a),null,RegistrarFacts::MODE_HELP)];
        if ($a->registrar_key==='enom') { $rows[]=[...self::fact('API user',$user=RegistrarFacts::apiUser($a),$user===null?'Identity not verified yet. Use Edit.':null),'action'=>'configure','edit'=>true,'mono'=>true]; }
        if ($a->registrar_key==='enom'&&$a->environment==='live') {
            try { $fingerprint=AccountBindings::binding($a)->fingerprint; } catch (\Throwable) { $fingerprint=null; }
            // Operators copy it into domains.enom_live.accounts (release-runbook.md).
            $rows[]=[...self::fact('Live account ID',$fingerprint,$fingerprint===null?"Saved credentials can't be read. Use More, then Edit API credentials.":null),'mono'=>true,'copy'=>true];
        }
        return $rows;
    }
    private function usageRows(Server $s,Account $a): array
    {
        $rows=[];
        if (RegistrarFacts::connected($a,$this->status())) {
            $b=RegistrarFacts::balance($a);
            // The fix for a Missing balance is the Check balance button on this row.
            $checked=$b['value']===null?$b['cause']:new HtmlString(implode('. ',array_filter([Sheet::moment((string)$b['checked'],'Checked')->toHtml(),$b['low']?RegistrarFacts::warn('Low balance')->toHtml():null])));
            $rows[]=[...self::fact('Balance',$b['value'],$checked),'money'=>true,'buttons'=>[$this->checkBalanceAction()]];
            $rows[]=self::fact('Due in 30 days',$b['due'],RegistrarFacts::dueLine(RegistrarFacts::dueGap($a,$b)),RegistrarFacts::DUE_HELP);
        }
        $filter=['filters'=>['server_id'=>['value'=>(string)$s->id]]];
        try { $domains=DomainResource::getUrl('index',$filter); } catch (\Throwable) { $domains=null; }
        $rows[]=[...self::fact('Domains',(string)Domain::where('server_id',$s->id)->count()),'buttons'=>[Sheet::link('view_domains','View domains',$domains)]];
        // Each count links to the list filtered to exactly its rows. On hold and Under review wait on staff, so they are amber.
        $requests=RegistrarFacts::requests((int)$s->id);$staff=array_filter($requests['staff']);
        $waiting=$staff===[]?null:RegistrarFacts::tone(implode(', ',array_map(fn(string $key,int $n)=>$n.' '.strtolower(RequestFacts::STATUSES[$key][0]),array_keys($staff),$staff)),'warning',RegistrarFacts::requestsUrl((int)$s->id,array_keys($staff)));
        $rows[]=[...self::fact('Registrar requests',$requests['open'].' open',$waiting),'buttons'=>[Sheet::link('view_requests','View requests',RegistrarFacts::requestsUrl((int)$s->id,RegistrarFacts::OPEN_REQUESTS))]];
        if ($calls=RegistrarFacts::calls($a)) { $rows[]=[...self::fact($a->environment==='live'?'API calls, today (UTC)':'API calls, last 24 hours',$calls['value'],$calls['sub'],$calls['help']),'tone'=>$calls['tone'],'edit'=>true,'buttons'=>[\Paymenter\Extensions\Servers\Domains\Admin\Support\ApiCallLimitAction::make($a,$this->reviewStamp)]]; }
        $rows[]=[...self::fact('Prices stay current for',\Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness::forAccount((int)$s->id)->hours.' hours',null,'Prices sync daily. Older prices stop new orders until checked again.'),'edit'=>true,'buttons'=>[\Paymenter\Extensions\Servers\Domains\Admin\Support\PriceFreshnessAction::make($a,$this->reviewStamp)]];
        return $rows;
    }
}
