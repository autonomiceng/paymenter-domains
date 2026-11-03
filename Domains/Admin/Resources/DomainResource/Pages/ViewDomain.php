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
use Paymenter\Extensions\Servers\Domains\Services\{ManagedDnsSetup,OwnedDomains,StaffWatchWrites};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
class ViewDomain extends ViewRecord
{
    public function getHeader(): ?\Illuminate\Contracts\View\View { return view('domains::staff.domain-header'); }
    public function pollManagement(): void { Staff::requireView(); $this->getRecord()->refresh(); $this->edits=$this->facts=$this->domainStatus=null; }
    public function managementProgress(): array
    { return \Paymenter\Extensions\Servers\Domains\Services\ManagementProgress::for($this->getRecord()); }

    protected static string $resource = DomainResource::class;
    protected Width|string|null $maxContentWidth = Sheet::WIDTH;
    /** Rows that keep their button, blocked, while changes are on hold. */
    private const BLOCKED = ['Transfer lock','Email auth code','Contacts','Nameservers','DNS records'];
    /** The section that says why, when the head doesn't. */
    private const HELD_SECTION = 'Contacts and DNS';
    /** One request's edits and facts: the head and the rows both read them. */
    private ?array $edits = null;
    private ?array $facts = null;
    private ?array $domainStatus = null;
    public function getTitle(): string { Staff::requireView(); return (string)$this->getRecord()->domain; }
    public function getHeading(): HtmlString
    {
        $d=$this->getRecord(); $test=DomainFacts::test($d);
        return new HtmlString('<span class="font-mono domains-mono">'.DomainName::html($this->getTitle())->toHtml().'</span>'.($test||DemoData::is($d)?DemoData::tag(inline:true,test:$test)->toHtml():''));
    }
    public function getSubheading(): string|Htmlable|null
    {
        $d=$this->getRecord(); $status=$this->status();
        $head=DomainFacts::head($d,$status,$this->facts(),$this->edits()['request']);
        return StatusMark::line($head['tone'],$status['label'],'domain-'.$d->id,$head['step']);
    }
    private function edits(): array { return $this->edits??=DomainEditActions::for($this->getRecord()); }
    private function status(): array { return $this->domainStatus??=DomainFacts::status($this->getRecord()); }
    private function facts(): array
    {
        if ($this->facts!==null) { return $this->facts; }
        $d=$this->getRecord(); $facts=self::withDnsSetup($d,DomainFacts::for($d)); $progress=$this->managementProgress();
        if ($this->edits()['authCode'] || isset($progress['auth_code_email'])) { $facts['Email auth code']=['value'=>'Goes to the '.OwnedDomains::authCodeRecipient($d),'sub'=>'Never shown here.','url'=>null,'tone'=>null,'help'=>'The code moves this domain to another registrar. We email it and never show it here.','demo'=>false]; }
        if ($this->edits()['held']) { $facts=DomainFacts::whileHeld($facts,$this->edits()['first']??null); }
        return $this->facts=DomainFacts::withProgress($facts,$progress,DomainFacts::headRequest($d,$this->status(),$this->edits()['request']));
    }
    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);
        $this->edits=$this->facts=$this->domainStatus=null;
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
    { return Sheet::button(OperationActions::scheduledReads($this->getRecord())[0]??Action::make('resume_observation')->hidden()); }
    public function notice_reviewAction(): Action
    { return Sheet::button(NoticeReview::action($this->getRecord())); }
    // ViewRecord otherwise fills public form data with the entire model's attributes.
    protected function fillForm(): void { $this->data=[]; }
    public function infolist(Schema $schema): Schema
    {
        $this->cacheHoldingActions();
        $d=$this->getRecord(); $facts=$this->facts(); $edits=$this->edits();
        // WHOIS privacy stays stored and read, but the page does not show it (PrivacyVisibility).
        if (!\Paymenter\Extensions\Servers\Domains\Presentation\PrivacyVisibility::SHOWN) { unset($facts['WHOIS privacy']); }
        // Reminder issues sit on Renewal invoices, then on Open alerts, which they open when neither row exists.
        $review=$this->getAction('notice_review',false); $button=$review?->isVisible()?$review->toHtml():null;
        if ($button&&!isset($facts['Renewal invoices'])) { $facts['Open alerts']??=['value'=>['A reminder needs a check.'],'sub'=>null,'url'=>null,'tone'=>'warning','help'=>null,'demo'=>false]; }
        $slot=isset($facts['Renewal invoices'])?'Renewal invoices':'Open alerts';
        $placed=array_filter(DomainFacts::SECTIONS,fn(array $labels)=>array_intersect($labels,array_keys($facts))!==[]);
        // The rows that stay blocked are said once, at the head of their section. A request's head line names it, so only a page with no such section and no request needs the note elsewhere.
        $held=isset($placed[self::HELD_SECTION])?self::HELD_SECTION:($edits['request']===null?array_key_first($placed):null);
        $sections=[];
        foreach ($placed as $title=>$labels) {
            $rows=[];
            foreach (array_intersect($labels,array_keys($facts)) as $label) {
                $rows[]=$this->row($label,$facts[$label],$this->side($label,$facts[$label],$edits,$label===$slot?$button:null));
            }
            $sections[]=Sheet::section($title,$rows,$title===$held?$edits['note']:null);
        }
        return $schema->components([Sheet::split([...$this->notices($d),...$sections],[$this->activity($d)])]);
    }
    /** @return list<Callout> */
    private function notices(Domain $d): array
    {
        $out=[];
        if(\Paymenter\Extensions\Servers\Domains\Services\ManagedDnsSetup::affected(time())->whereKey($d->id)->exists()){
            $out[]=Callout::make('Set a default DNS template on the registrar account to start DNS monitoring.')->status('info')->columnSpanFull()
                ->footer(Staff::can(\Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites::VIEW) && Staff::canEditDefaultDns()?new HtmlString('<a class="fi-link domains-link" href="'.e(\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::getUrl(['account'=>$d->server_id]).'#switch-template').'">Set account DNS template</a>'):null);
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
    /** A row's action column: its edits or its link. Renewal invoices and Last sync draw the page's own actions. */
    private function side(string $label, array $fact, array $edits, ?string $review): array|Html
    {
        if ($label==='Renewal invoices') {
            // On a row that says Off, Stop shows only when the row says what it still stops.
            $buttons=array_filter(['stop','resume'],fn(string $name)=>(($this->cachedActions[$name]??null)?->isVisible()??false)&&($name!=='stop'||$fact['value']!=='Off'||str_contains((string)$fact['sub'],'Stop renewal')));
            $html=[...array_map(fn(string $name)=>$this->cachedActions[$name]->toHtml(),$buttons),...($review?[$review]:[])];
            return $html?Html::make(Sheet::buttons($html)):[];
        }
        if ($label==='Open alerts') { return $review?Html::make(Sheet::buttons([$review])):[]; }
        if ($label==='Last sync') { $resume=$this->getAction('resume_observation',false); return $resume?->isVisible()?Html::make(Sheet::buttons([$resume->toHtml()])):[]; }
        $side=array_map(fn(Action $action)=>in_array($action->getLabel(),['Edit','Change'],true)?Sheet::edit($action,$label):Sheet::button($action),$edits['rows'][$label]??[]);
        if ($side===[]&&$edits['held']&&in_array($label,self::BLOCKED,true)) { $side[]=Sheet::blocked('held_'.md5($label),self::blockedLabel($label,$fact),$label,$edits['held']); }
        if (($fact['setup']??false)&&Staff::can(StaffWatchWrites::VIEW)) { $side[]=Sheet::link('dns_setup','Choose DNS template',WatchResource::getUrl('dns-setup')); }
        if ($fact['url']&&isset(DomainFacts::LINKS[$label])) { $side[]=Sheet::link('open_'.md5($label),DomainFacts::LINKS[$label],$fact['url']); }
        return $side;
    }
    /** A blocked button keeps the label the real one would have. */
    public static function blockedLabel(string $label, array $fact): string
    { return match ($label) { 'Transfer lock'=>DomainEditActions::lockLabel(['On'=>true,'Off'=>false][$fact['value']]??null),'Nameservers'=>'Change','Email auth code'=>'Email code',default=>'Edit' }; }
    /** The row's words, after when it was read when the row has a read time. */
    private static function below(array $fact): string|HtmlString|null
    {
        $words=self::words($fact);
        if ($words!==null&&isset($fact['request'])) { $words=DomainFacts::linkRequest($words,$fact['request']); }
        if (isset($fact['progress'])) {
            $text=$fact['progress']['text'];
            if (($fact['progress']['tone']??null)==='warning') { $text=RegistrarFacts::tone($text,'warning'); }
            $line=$fact['progress']['request']===null?$text:DomainFacts::linkRequest($text,$fact['progress']['request']);
            $html=fn(string|HtmlString $text)=>$text instanceof HtmlString?$text->toHtml():e($text);
            $words=$words===null?$line:new HtmlString($html($words).'<br>'.$html($line));
        }
        if (!isset($fact['asOf'])) { return $words; }
        $read=Sheet::moment($fact['asOf'],'As of')->toHtml();
        return new HtmlString($words===null?$read:$read.'. '.($words instanceof HtmlString?$words->toHtml():e($words)));
    }
    /** An open alert's cause takes the tone and its value stays plain, so the row says what to do, not that the value is wrong. Words that were on the row before the alert stay plain too. */
    private static function words(array $fact): string|HtmlString|null
    {
        if ($fact['value']===null||$fact['sub']===null||!in_array($fact['tone'],['danger','warning'],true)) { return $fact['sub']; }
        $plain=$fact['plain']??null; if ($plain===null) { return RegistrarFacts::tone($fact['sub'],$fact['tone']); }
        return new HtmlString(e($plain).' '.RegistrarFacts::tone(substr($fact['sub'],strlen($plain)+1),$fact['tone'])->toHtml());
    }
    private function row(string $label, array $fact, array|Html $side): Flex
    {
        $waiting=$fact['progress']['waiting']??false;
        if ($label==='Transfer lock'&&in_array($fact['value'],['On','Off'],true)) { $fact=self::lockMark($fact,$waiting); }
        $entry=TextEntry::make('value_'.md5($label))->label($label)->state($fact['value'])
            ->belowContent(self::below($fact));
        if (is_array($fact['value'])) { $entry->listWithLineBreaks(); }
        if ($label==='Nameservers'&&$fact['value']!==null) { $entry->fontFamily(FontFamily::Mono); }
        if ($label==='Service'&&$fact['value']!==null) { $entry->formatStateUsing(fn()=>new HtmlString('<span class="font-mono domains-mono">'.DomainName::html($this->getTitle())->toHtml().'</span> registration')); }
        if (isset($fact['at'])) { $entry->formatStateUsing(fn()=>Sheet::moment($fact['at'])); }
        if ($fact['help']) { $entry->hintAction(HelpHint::make($fact['help'])); }
        if ($fact['demo']) { $entry->suffix(DemoData::tag(inline:true)); }
        $entry=MissingEntry::apply($entry,cause:$fact['value']===null?$fact['sub']:null,color:$fact['value']!==null&&($fact['alert']??false)?null:$fact['tone']);
        if (isset($fact['mark'])) { $entry=Sheet::state($entry,$fact['mark'],'lock-'.$this->getRecord()->id); }
        return Sheet::row($entry,$side,pending:$waiting,key:'fact_'.md5($label));
    }
    /** The lock reads as a mark: On, a hollow Off, or the change on its way with what it was. */
    public static function lockMark(array $fact, bool $waiting): array
    {
        if (!$waiting) { return [...$fact,'mark'=>$fact['value']==='On'?'success':'gray']; }
        return [...$fact,'value'=>$fact['progress']['text'],'sub'=>'Was '.strtolower($fact['value']).'.','mark'=>'info','progress'=>null];
    }
}
