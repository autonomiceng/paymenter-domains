<?php
// Adapted from Enom 444f31c ViewDomain, split read-only support; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\{Callout, Flex, Html, Section, Text};
use Filament\Schemas\Schema;
use Filament\Support\Enums\{FontFamily, Width};
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource,WatchResource};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{AcquisitionActions, AcquisitionPaymentReview, DomainEditActions, DomainFacts, DomainHistory, HelpHint, HoldingActions, MissingEntry, NoticeReview, OperationActions, RegistrarFacts, Sheet, Staff, StatusMark};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Presentation\DomainName;
use Paymenter\Extensions\Servers\Domains\Services\{ManagedDnsSetup,StaffWatchWrites};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
class ViewDomain extends ViewRecord
{
    public function getHeader(): ?\Illuminate\Contracts\View\View { return view('domains::staff.domain-header'); }
    public function pollManagement(): void { Staff::requireView(); $this->getRecord()->refresh(); }
    public function managementProgress(): array
    { return \Paymenter\Extensions\Servers\Domains\Services\ManagementProgress::for($this->getRecord()); }

    protected static string $resource = DomainResource::class;
    protected Width|string|null $maxContentWidth = Sheet::WIDTH;
    /** Rows that keep their button, blocked, while changes are on hold. */
    private const BLOCKED = ['Transfer lock','Contacts','Nameservers','DNS records'];
    /** The section that says why, when the head doesn't. */
    private const HELD_SECTION = 'Contacts and DNS';
    /** One request's edits: the head and the rows both read them. */
    private ?array $edits = null;
    public function getTitle(): string { Staff::requireView(); return (string)$this->getRecord()->domain; }
    public function getHeading(): HtmlString
    {
        $d=$this->getRecord(); $test=DomainFacts::test($d);
        return new HtmlString('<span class="font-mono domains-mono">'.DomainName::html($this->getTitle())->toHtml().'</span>'.($test||DemoData::is($d)?DemoData::tag(inline:true,test:$test)->toHtml():''));
    }
    public function getSubheading(): string|Htmlable|null
    {
        $d=$this->getRecord(); $status=DomainFacts::status($d);
        return StatusMark::line($status['tone'],$status['label'],'domain-'.$d->id,$this->edits()['request']!==null?DomainFacts::holdHtml($status):DomainFacts::stepHtml($status));
    }
    private function edits(): array { return $this->edits??=DomainEditActions::for($this->getRecord()); }
    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);
        $this->edits=null;
    }
    public function getBreadcrumbs(): array { return [$this->getResourceUrl()=>'Domains',$this->getTitle()]; }
    public function hasCombinedRelationManagerTabsWithContent(): bool { return true; }
    public function getContentTabLabel(): ?string { return 'Overview'; }
    protected function getHeaderActions(): array
    {
        $d=$this->getRecord();
        // Import domain for a pending import sits in the page notice.
        return [$this->syncNow(),...AcquisitionActions::make($d),...AcquisitionPaymentReview::actions($d),$this->customerView($d)];
    }
    /** Named as on the Users page, so a skin that records impersonation sees this one too. */
    private function customerView(Domain $d): Action
    {
        $user=$d->user;
        return Action::make('impersonate')->label('View as customer')->link()->color('gray')
            ->visible(fn()=>$user&&(int)$user->id!==(int)Auth::id()&&Auth::user()?->hasPermission('admin.users.impersonate'))
            ->action(function()use($user,$d):void { session()->put('impersonating',$user->id); $this->redirect(route('domains.domains.show',$d->id)); });
    }
    /** Stop and Resume sit on the Renewal invoices row, and stay page actions so their dialogs need no row. */
    public function cacheInteractsWithHeaderActions(): void
    {
        parent::cacheInteractsWithHeaderActions();
        $this->cacheHoldingActions();
    }
    /** The infolist first builds during mount, before Filament caches page actions, and its Renewal invoices row draws these. */
    private function cacheHoldingActions(): void
    {
        if (isset($this->cachedActions['stop'])) { return; }
        foreach (HoldingActions::make($this->getRecord()) as $action) { $this->cacheAction(Sheet::button($action,$action->getName()==='stop'?'danger':'gray')); }
    }
    private function syncNow(): Action
    {
        // Record reads use registrar calls; Sync now lets staff request them only when needed.
        return Action::make('sync_now')->label('Sync now')->color('gray')->visible(fn()=>Staff::can('admin.domains.manage')&&$this->getRecord()->status==='active')
            ->action(function():void {
                if (!Staff::can('admin.domains.manage')) { abort(403); }
                try {
                    $queued=\Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords::enqueue((int)\Illuminate\Support\Facades\Auth::id(),$this->getRecord()->refresh());
                    if (!$queued) {
                        $minutes=(int)ceil(\Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords::UNIQUE_FOR/60);
                        \Filament\Notifications\Notification::make()->info()->title("A sync was already requested. If nothing changes in {$minutes} minutes, press Sync now again. If it still doesn't run, check the queue worker.")->send();
                        return;
                    }
                    \Filament\Notifications\Notification::make()->success()->title('Sync queued')->body('You will get a notification when it finishes. Refresh this page to see the records.')->send();
                } catch (\DomainException $error) { \Filament\Notifications\Notification::make()->warning()->title($error->getMessage())->send(); }
                catch (\Throwable) { \Filament\Notifications\Notification::make()->warning()->title("Couldn't queue sync. Ask your site admin to check the queue worker, then try again.")->send(); }
            });
    }
    /** Resume sync sits in the page notice and is mounted as a page action. */
    public function resume_observationAction(): Action
    { return OperationActions::scheduledReads($this->getRecord())[0]??Action::make('resume_observation')->hidden(); }
    public function notice_reviewAction(): Action
    { return Sheet::button(NoticeReview::action($this->getRecord())); }
    // ViewRecord otherwise fills public form data with the entire model's attributes.
    protected function fillForm(): void { $this->data=[]; }
    public function infolist(Schema $schema): Schema
    {
        $this->cacheHoldingActions();
        $d=$this->getRecord(); $facts=self::withDnsSetup($d,DomainFacts::for($d)); $edits=$this->edits(); $progress=$this->managementProgress();
        if (isset($edits['rows']['Auth code']) || isset($progress['auth_code_email'])) { $facts['Auth code']=['value'=>$d->registrar_key==='namesilo'?'Admin contact email':'Registrant email','sub'=>null,'url'=>null,'tone'=>null,'help'=>null,'demo'=>false]; }
        $facts=DomainFacts::withProgress($facts,$progress);
        // Reminder issues sit on Renewal invoices, then Open alerts, or alone when neither slot exists.
        $review=$this->getAction('notice_review',false); $button=$review?->isVisible()?$review->toHtml():null;
        $byRow=isset($facts['Renewal invoices']);
        $placed=array_filter(DomainFacts::SECTIONS,fn(array $labels)=>array_intersect($labels,array_keys($facts))!==[]);
        $held=isset($placed[self::HELD_SECTION])?self::HELD_SECTION:array_key_first($placed);
        $sections=[];
        foreach ($placed as $title=>$labels) {
            $rows=[];
            foreach (array_intersect($labels,array_keys($facts)) as $label) {
                $rows[]=$this->row($label,$facts[$label],$this->side($label,$facts[$label],$edits,$label==='Renewal invoices'?$button:null));
            }
            $sections[]=Sheet::section($title,$rows,$title===$held&&$edits['request']===null?$edits['held']:null);
        }
        return $schema->components([Sheet::split([...$this->notices($d,$facts,$byRow?null:$button),...$sections],[$this->activity($d)])]);
    }
    /** @return list<Callout|Html> */
    private function notices(Domain $d, array $facts, ?string $review): array
    {
        $notice=DomainFacts::notice($d); $out=[];
        if(\Paymenter\Extensions\Servers\Domains\Services\ManagedDnsSetup::affected(time())->whereKey($d->id)->exists()){
            $out[]=Callout::make('Set a default DNS template on the registrar account to start DNS monitoring.')->status('info')->columnSpanFull()
                ->footer(Staff::can(\Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites::VIEW) && Staff::canEditDefaultDns()?new HtmlString('<a class="fi-link domains-link" href="'.e(\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::getUrl(['account'=>$d->server_id]).'#switch-template').'">Set account DNS template</a>'):null);
        }
        if (isset($facts['Open alerts'])) {
            $alert=$facts['Open alerts'];
            $out[]=Callout::make('Open alerts')->description(implode(' ',$alert['value']))->status($alert['tone'])->columnSpanFull()
                ->footer($review?Sheet::buttons([$review]):null);
        } elseif ($review) { $out[]=Html::make(Sheet::buttons([$review])); }
        if ($notice) {
            $buttons=[];
            $resume=($notice['resume']??false)?$this->getAction('resume_observation',false):null;
            if ($resume?->isVisible()) { $buttons[]=$resume->label('Resume sync')->toHtml(); }
            foreach ([$notice['action']??null,$notice['link']??null] as $i=>$link) {
                if ($link&&$link['url']) { $buttons[]=view('filament::components.button.index',['tag'=>'a','href'=>$link['url'],'color'=>$i===0?'primary':'gray','size'=>'sm','slot'=>new HtmlString(e($link['label']))])->render(); }
            }
            $out[]=Callout::make($notice['title'])->description($notice['body']??null)->status($notice['tone'])->columnSpanFull()
                ->footer($buttons?Sheet::buttons($buttons):null);
        }
        return $out;
    }
    /** A domain monitoring can't compare says so on its DNS row, beside the way to fix it. */
    private static function withDnsSetup(Domain $d, array $facts): array
    {
        $row=isset($facts['DNS records'])?'DNS records':'Nameservers';
        if (!isset($facts[$row])||!ManagedDnsSetup::affected(time())->whereKey($d->id)->exists()) { return $facts; }
        $facts[$row]['sub']=trim(($facts[$row]['sub']??'').' DNS comparison not set up.');
        $facts[$row]['setup']=true;
        return $facts;
    }
    /** The rail: the newest history, with the tab holding the rest. */
    private function activity(Domain $d): Section
    {
        $entries=DomainHistory::entries($d);
        $url=DomainResource::getUrl('view',['record'=>$d->id,'relation'=>'0']);
        return Sheet::section('Activity',[$entries===[]?Text::make('No history yet'):Sheet::trace(DomainHistory::steps($d,array_slice($entries,0,Sheet::RAIL_STEPS)))],
            after:$entries===[]?[]:[Sheet::link('view_history','View all '.count($entries),$url)]);
    }
    /** A row's action column: its edits or its link. Renewal invoices draws the page's Stop and Resume. */
    private function side(string $label, array $fact, array $edits, ?string $review): array|Html
    {
        if ($label==='Renewal invoices') {
            $buttons=array_filter(['stop','resume'],fn(string $name)=>($this->cachedActions[$name]??null)?->isVisible()??false);
            $html=[...array_map(fn(string $name)=>$this->cachedActions[$name]->toHtml(),$buttons),...($review?[$review]:[])];
            return $html?Html::make(Sheet::buttons($html)):[];
        }
        $side=array_map(fn(Action $action)=>in_array($action->getLabel(),['Edit','Change'],true)?Sheet::edit($action,$label):Sheet::button($action),$edits['rows'][$label]??[]);
        if ($side===[]&&$edits['held']&&in_array($label,self::BLOCKED,true)) { $side[]=Sheet::blocked('held_'.md5($label),self::blockedLabel($label,$fact),$label,$edits['held']); }
        if (($fact['setup']??false)&&Staff::can(StaffWatchWrites::VIEW)) { $side[]=Sheet::link('dns_setup','Choose DNS template',WatchResource::getUrl('dns-setup')); }
        if ($fact['url']&&isset(DomainFacts::LINKS[$label])) { $side[]=Sheet::link('open_'.md5($label),DomainFacts::LINKS[$label],$fact['url']); }
        return $side;
    }
    private static function blockedLabel(string $label, array $fact): string
    { return $label==='Transfer lock'?($fact['value']==='On'?'Turn off':'Turn on'):'Edit'; }
    private function row(string $label, array $fact, array|Html $side): Flex
    {
        $entry=TextEntry::make('value_'.md5($label))->label($label)->state($fact['value'])
            // An open alert's cause takes the tone and its value stays plain, so the row says what to do, not that the value is wrong.
            ->belowContent($fact['value']!==null&&$fact['sub']!==null&&in_array($fact['tone'],['danger','warning'],true)?RegistrarFacts::tone($fact['sub'],$fact['tone']):$fact['sub']);
        if (is_array($fact['value'])) { $entry->listWithLineBreaks(); }
        if ($label==='Nameservers'&&$fact['value']!==null) { $entry->fontFamily(FontFamily::Mono); }
        if ($label==='Service'&&$fact['value']!==null) { $entry->formatStateUsing(fn()=>new HtmlString('<span class="font-mono domains-mono">'.DomainName::html($this->getTitle())->toHtml().'</span> registration')); }
        if (isset($fact['at'])) { $entry->formatStateUsing(fn()=>Sheet::moment($fact['at'])); }
        if ($fact['help']) { $entry->hintAction(HelpHint::make($fact['help'])); }
        if ($fact['demo']) { $entry->suffix(DemoData::tag(inline:true)); }
        return Sheet::row(MissingEntry::apply($entry,cause:$fact['value']===null?$fact['sub']:null,color:$fact['value']!==null&&($fact['alert']??false)?null:$fact['tone']),$side,key:'fact_'.md5($label));
    }
}
