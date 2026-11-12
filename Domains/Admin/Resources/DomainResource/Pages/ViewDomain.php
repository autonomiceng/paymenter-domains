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
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{AcquisitionActions, AcquisitionPaymentReview, DomainEditActions, DomainFacts, DomainHistory, HelpHint, HoldingActions, MissingEntry, NoticeReview, OperationActions, RegistrarFacts, Sheet, Staff, StatusMark};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Presentation\DomainName;
use Paymenter\Extensions\Servers\Domains\Services\{ManagedDnsSetup,OwnedDomains,StaffWatchWrites};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
class ViewDomain extends ViewRecord
{
    public function getHeader(): ?\Illuminate\Contracts\View\View { return view('domains::staff.domain-header'); }
    public function pollManagement(): void { Staff::requireView(); $this->getRecord()->refresh(); $this->edits=$this->facts=$this->domainStatus=$this->families=$this->head=null; $this->notice=false; }
    #[\Livewire\Attributes\Locked]
    public ?int $resumeStarted=null;
    public function syncPending(): bool
    {
        $now=app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp();
        if ($this->resumeStarted!==null&&$now<$this->resumeStarted+900) {
            $job=\Paymenter\Extensions\Servers\Domains\Services\HoldingObservationWork::current($this->getRecord(),true)->first();
            if ($job&&($job->safe_code===null||$job->lease_until>$now)) { return true; }
        }
        return \Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords::pending($this->getRecord());
    }
    public function managementProgress(): array
    { return \Paymenter\Extensions\Servers\Domains\Services\ManagementProgress::for($this->getRecord()); }
    public function syncRetryAt(): ?int
    {
        try {
            $d=$this->getRecord();
            if(!\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::retryableHold($d)){return null;}
            $read=\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::status($d);
            return $read['read_stopped']?null:$read['next_read'];
        } catch (\Throwable) { return null; }
    }
    /** How long the page keeps following waiting changes in the background. Sync now keeps its own window (syncPending). */
    public const FOLLOW_SECONDS=900;
    #[\Livewire\Attributes\Locked]
    public ?int $followStarted=null;
    /** @return array{every:?string,stopped:bool} How often the head reads stored progress again, and whether it stopped while a change still waits.
     * A change that never finishes stays shown as waiting; the page just stops reading it in the background. */
    public function managementPoll(): array
    {
        $pending=array_filter($this->managementProgress(),fn(array $row)=>$row['pending']);
        $now=app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp();
        $this->followStarted=$pending?($this->followStarted??$now):null;
        $following=$pending&&$now<$this->followStarted+self::FOLLOW_SECONDS;
        $retry=$this->syncRetryAt();
        $every=$following||$this->syncPending()||($retry!==null&&$now<$retry+self::FOLLOW_SECONDS)?(array_filter($pending,fn(array $row)=>$row['slow'])?'10s':'2s'):null;
        return ['every'=>$every,'stopped'=>$pending&&!$following];
    }

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
    private ?array $head = null;
    /** The head notice, read once per request (false: not read yet). Refresh and actions clear it. */
    private array|false|null $notice = false;
    #[\Livewire\Attributes\Locked]
    public string $complimentaryCostStamp='';
    public function getTitle(): string { Staff::requireView(); return (string)$this->getRecord()->domain; }
    public function getHeading(): HtmlString
    {
        $d=$this->getRecord(); $test=DomainFacts::test($d);
        return new HtmlString('<span class="font-mono domains-mono">'.DomainName::html($this->getTitle())->toHtml().'</span>'.($test||DemoData::is($d)?DemoData::tag(inline:true,test:$test)->toHtml():''));
    }
    public function getSubheading(): string|Htmlable|null
    {
        $d=$this->getRecord(); $head=$this->head();
        $line=StatusMark::line($head['tone'],$this->status()['label'],'domain-'.$d->id,$head['step'])->toHtml();
        $progress=$this->syncPending()?'Sync in progress; this page updates when the read finishes.':($this->syncRetryAt()!==null?'Sync will retry automatically.':null);
        return new HtmlString($line.($progress===null?'':' <span role="status" class="domains-hint">'.e($progress).'</span>'));
    }
    private function head(): array { return $this->head??=DomainFacts::head($this->getRecord(),$this->status(),$this->facts(),$this->edits()['request'],$this->notice()); }
    /** The head's step as plain text, so rows and Activity can leave out what it already says. */
    private function said(): ?string
    { $step=$this->head()['step']; return $step===null?null:html_entity_decode(strip_tags($step instanceof Htmlable?$step->toHtml():$step),ENT_QUOTES|ENT_HTML5); }
    /** History gets the request the head names and the head's words, so it leaves out the reason the head already says (DomainHistory::repeatsHead). */
    public function getRelationManagers(): array
    {
        return array_map(fn($manager)=>$manager===RequestsRelationManager::class?RequestsRelationManager::make([
            'headRequest'=>DomainFacts::headRequest($this->getRecord(),$this->status(),$this->edits()['request'],$this->notice()),'headSaid'=>$this->said()]):$manager,parent::getRelationManagers());
    }
    private function edits(): array { return $this->edits??=DomainEditActions::for($this->getRecord()); }
    private function status(): array { return $this->domainStatus??=DomainFacts::status($this->getRecord()); }
    private function notice(): ?array { if ($this->notice===false) { $this->notice=DomainFacts::notice($this->getRecord()); } return $this->notice; }
    private function facts(): array
    {
        if ($this->facts!==null) { return $this->facts; }
        $d=$this->getRecord(); $facts=self::withDnsSetup($d,DomainFacts::for($d,$this->notice(),$this->familyState())); $progress=$this->managementProgress();
        if ($this->edits()['authCode'] || isset($progress['auth_code_email'])) { $facts['Email auth code']=['value'=>'Goes to the '.OwnedDomains::authCodeRecipient($d),'sub'=>'Never shown here.','url'=>null,'tone'=>null,'help'=>'The code moves this domain to another registrar. We email it and never show it here.','demo'=>false]; }
        if ($this->edits()['held']) { $facts=DomainFacts::whileHeld($facts,$this->edits()['first']??null); }
        return $this->facts=DomainFacts::withProgress($facts,$progress,DomainFacts::headRequest($d,$this->status(),$this->edits()['request'],$this->notice()));
    }
    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);
        if ($action->getName()==='resume_observation') { $this->resumeStarted=app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp(); }
        // A new change starts a new follow window.
        $this->followStarted=null;
        $this->pollManagement();
        $this->cacheSchema('infolist');
    }
    public function getBreadcrumbs(): array { return [$this->getResourceUrl()=>'Domains',$this->getTitle()]; }
    public function hasCombinedRelationManagerTabsWithContent(): bool { return true; }
    public function getContentTabLabel(): ?string { return 'Overview'; }
    protected function getHeaderActions(): array
    {
        $d=$this->getRecord();
        // Import domain for a pending import sits in the page notice.
        return [$this->approveComplimentaryCost($d),self::complimentaryRenewal($d),$this->syncNow(),...AcquisitionActions::make($d),...AcquisitionPaymentReview::actions($d),$this->customerView($d)];
    }
    private function approveComplimentaryCost(Domain $d):Action
    {
        $op=null;$message=null;
        if(Staff::can('admin.domains.fund')&&Staff::can('admin.domains.manage')&&Staff::can(Staff::COSTS)){
            foreach($d->operations()->where('state','held')->where('safe_code','registrar_price_increased')->latest('id')->get() as $candidate){
                try{$message=\Paymenter\Extensions\Servers\Domains\Services\ComplimentaryCosts::message($candidate,true);}
                catch(\Throwable){continue;}
                if($message!==null){$op=$candidate;break;}
            }
        }
        return Action::make('approveComplimentaryCost')->label('Approve registrar cost')->color('danger')
            ->visible($op!==null)->modalHeading('Approve registrar cost')->modalDescription($message)
            ->schema([\Filament\Forms\Components\TextInput::make('reason')->label('Reason')->required()->maxLength(500)])
            ->mountUsing(function(?Schema $schema)use($op,$d):void {
                $this->complimentaryCostStamp=\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($op,$d);
                $schema?->fill();
            })
            ->action(function(array $data)use($op,$d):void {
                try{
                    if(!hash_equals($this->complimentaryCostStamp,\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($op,$d))){throw new \DomainException('The reviewed registrar cost changed.');}
                    \Paymenter\Extensions\Servers\Domains\Services\ComplimentaryCosts::approve($op->id,Auth::user(),$op->policy['complimentary_cost_review']['amount'],$data['reason'],app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class));
                    \Filament\Notifications\Notification::make()->success()->title('Registrar cost approved')->send();
                }catch(\Throwable){\Filament\Notifications\Notification::make()->danger()->title('Could not approve registrar cost')->body('Reload the domain and check the current registrar cost and hold.')->send();}
            });
    }
    /** Named as on the Users page, so a skin that records impersonation sees this one too. */
    private function customerView(Domain $d): Action
    {
        $user=$d->user;
        return Action::make('impersonate')->label('View as customer')->link()->color('gray')
            ->visible(fn()=>$user&&(int)$user->id!==(int)Auth::id()&&Auth::user()?->hasPermission('admin.users.impersonate'))
            ->action(function()use($user,$d):void { session()->put('impersonating',$user->id); $this->redirect(route('domains.domains.show',$d->id)); });
    }
    /** Stop and Resume sit on the Renewal row, and stay page actions so their dialogs need no row. */
    public function cacheInteractsWithHeaderActions(): void
    {
        parent::cacheInteractsWithHeaderActions();
        $this->cacheHoldingActions();
    }
    /** The infolist first builds during mount, before Filament caches page actions, and its Renewal row draws these. */
    private function cacheHoldingActions(): void
    {
        if (isset($this->cachedActions['stop'])) { return; }
        foreach (HoldingActions::make($this->getRecord()) as $action) { $this->cacheAction(Sheet::button($action,$action->getName()==='stop'?'danger':'gray')); }
    }
    private function syncNow(): Action
    {
        // Record reads use registrar calls; Sync now lets staff request them only when needed.
        return Action::make('sync_now')->label('Sync now')->color('gray')->disabled(fn()=>\Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords::pending($this->getRecord()))->visible(fn()=>DomainFacts::syncNow($this->getRecord()))
            ->action(function():void {
                if (!Staff::can('admin.domains.manage')) { abort(403); }
                try {
                    $queued=\Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords::enqueue((int)\Illuminate\Support\Facades\Auth::id(),$this->getRecord()->refresh());
                    if (!$queued) {
                        $minutes=(int)ceil(\Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords::UNIQUE_FOR/60);
                        \Filament\Notifications\Notification::make()->info()->title("A sync was already requested. If nothing changes in {$minutes} minutes, press Sync now again. If it still doesn't run, check the queue worker.")->send();
                        return;
                    }
                    \Filament\Notifications\Notification::make()->success()->title('Sync queued')->body('This page updates when the sync finishes.')->send();
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
        $d=$this->getRecord(); $facts=DomainFacts::besideHead($this->facts(),$this->said()); $edits=$this->edits();
        // WHOIS privacy stays stored and read, but the page does not show it (PrivacyVisibility).
        if (!\Paymenter\Extensions\Servers\Domains\Presentation\PrivacyVisibility::SHOWN) { unset($facts['WHOIS privacy']); }
        // Reminder issues sit on Renewal, then on Open alerts, which they open when neither row exists.
        $review=$this->getAction('notice_review',false); $button=$review?->isVisible()?$review->toHtml():null;
        if ($button&&!isset($facts['Renewal'])) { $facts['Open alerts']??=['value'=>['A reminder needs a check.'],'sub'=>null,'url'=>null,'tone'=>'warning','help'=>null,'demo'=>false]; }
        $slot=isset($facts['Renewal'])?'Renewal':'Open alerts';
        $placed=array_filter(DomainFacts::SECTIONS,fn(array $labels)=>array_intersect($labels,array_keys($facts))!==[]);
        // Contacts and DNS rows sit on their registrar record rows, with their edit buttons, as in the approved mock.
        $recordsHeld=isset($placed[self::HELD_SECTION]);unset($placed[self::HELD_SECTION]);
        // The rows that stay blocked are said once, at the head of their section. A request's head line names it, so only a page with no such section and no request needs the note elsewhere.
        $held=$recordsHeld?null:($edits['request']===null?array_key_first($placed):null);
        $sections=[];
        foreach ($placed as $title=>$labels) {
            $rows=[];
            foreach (array_intersect($labels,array_keys($facts)) as $label) {
                $rows[]=$this->row($label,$facts[$label],$this->side($label,$facts[$label],$edits,$label===$slot?$button:null));
            }
            $sections[]=Sheet::section($title,$rows,$title===$held?$edits['note']:null);
        }
        // Like the approved mock, an active domain leads with its registrar records. Other states lead with registration and billing.
        $records=[$this->recordFamilies($d,$facts,$edits,$recordsHeld?$edits['note']:null)];
        // The registrar records section above already shows each family's state and the last read, so Monitoring keeps only the public DNS evidence.
        $monitoring=Sheet::section('Monitoring',[\Filament\Schemas\Components\Html::make(fn()=>view('domains::admin.monitoring-evidence',['evidence'=>\Paymenter\Extensions\Servers\Domains\Presentation\MonitoringEvidence::read($d),'withRecords'=>true]))]);
        $sheet=$d->status==='active'?[...$records,...$sections]:[...$sections,...$records];
        return $schema->components([Sheet::split([...$this->notices($d),...$sheet,$monitoring],[$this->activity($d)])]);
    }
    /** @return list<Callout> */
    private function notices(Domain $d): array
    {
        $out=[];
        if(Staff::can('admin.domains.fund')||Staff::can(Staff::COSTS)){foreach($d->operations()->where('state','held')->get() as $op){
            try{if($message=\Paymenter\Extensions\Servers\Domains\Services\ComplimentaryCosts::message($op,Staff::can(Staff::COSTS)&&Staff::can('admin.domains.fund')&&Staff::can('admin.domains.manage'))){
                if(!Staff::can(Staff::COSTS)){$message='Registrar cost approval is needed. Ask staff with access to domain costs to review it.';}
                $out[]=Callout::make($message)->status('danger')->columnSpanFull();
            }}
            catch(\Throwable){$out[]=Callout::make('Registrar cost details are unavailable.')->status('warning')->columnSpanFull();}
        }}
        $sync=\Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords::result($d);
        $recordsHeld=false;
        if(($sync['title']??null)==="Couldn't read all records"){
            try{$recordsHeld=\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::held($d);}catch(\Throwable){}
        }
        if($sync!==null&&!in_array($sync['title'],['Domain synced','Sync retry scheduled'],true)
            &&!$recordsHeld){
            $out[]=Callout::make($sync['title'])->description($sync['body'])->status('warning')->columnSpanFull();
        }
        if(\Paymenter\Extensions\Servers\Domains\Services\ManagedDnsSetup::affected(time())->whereKey($d->id)->exists()){
            $out[]=Callout::make('Set the default nameservers in Domains > DNS templates to start DNS monitoring.')->status('info')->columnSpanFull()
                ->footer(Staff::can(\Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites::VIEW) && Staff::canEditDefaultDns()?new HtmlString('<a class="fi-link domains-link" href="'.e(\Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource::getUrl()).'">'.\Paymenter\Extensions\Servers\Domains\Admin\Support\DefaultNameservers::TITLE.'</a>'):null);
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
    /** One row per registrar record family. A family's saved values, its DNSSEC state and its edit buttons sit on the same row. */
    private function recordFamilies(Domain $d, array $facts, array $edits, string|Htmlable|null $note): Section
    {
        $state=$this->familyState();
        $resume=$this->getAction('resume_observation',false);
        $view=fn(string $part)=>Html::make(view('domains::partials.record-families',['recordFamilies'=>$state,'staff'=>true,'part'=>$part,'readScheduling'=>!DomainFacts::ended($d)]));
        $own=array_intersect_key($facts,array_flip(DomainFacts::SECTIONS[self::HELD_SECTION]));$own['DS records']=\Paymenter\Extensions\Servers\Domains\Presentation\DsState::staff($d);
        $rows=[$view('head')];$shown=[];
        // SRV has no row of its own in the facts, so its change line comes straight from the stored progress.
        $srv=DomainFacts::progress($this->managementProgress(),'srv',DomainFacts::headRequest($d,$this->status(),$edits['request'],$this->notice()));
        foreach (($state['cancelled_without_records']??false)||($state['not_at_registrar']??null)!==null?[]:$state['rows'] as $family) {
            $label=$family['label'];$fact=$own[$label]??null;$shown[]=$label;
            $rows[]=$this->familyRow($family,$fact,$fact!==null&&$label!=='DS records'?$this->side($label,$fact,$edits,null):[],$state['at']??null,$family['kind']==='srv'?$srv:null);
        }
        // Nothing is at the registrar yet: the head says so once, with no row per record.
        foreach (($state['not_at_registrar']??null)!==null?[]:array_diff_key($own,array_flip($shown)) as $label=>$fact) { $rows[]=$this->row($label,$fact,$label==='DS records'?[]:$this->side($label,$fact,$edits,null)); }
        $rows[]=$view('tail');
        return Sheet::section('Registrar records',$rows,$note,after:!DomainFacts::syncNow($d)&&$state['resume']&&$resume?->isVisible()?[$resume]:[]);
    }
    /** One registrar records read per render, shared by the records section and the Last sync row. */
    private ?array $families=null;
    private function familyState(): array
    { return $this->families??=\Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies::read($this->getRecord(),app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()); }
    /** The family's read state above the row's own value, which keeps its red Missing, Failed or Under review tone and demo tag.
     * A family with no read and a Missing value says Missing once. Families with no own fact (Domain settings, SRV) show the read state, and SRV its change line. */
    private function familyRow(array $family, ?array $fact, array|Html $side, mixed $readAt = null, ?array $progress = null): Flex
    {
        $status=new HtmlString('<div class="domains">'.view('domains::partials.record-family-status',['family'=>$family,'staff'=>true,'readAt'=>$readAt])->render().'</div>');
        if ($fact===null) {
            $entry=TextEntry::make('family_'.$family['kind'])->label($family['label'])->state($status);
            if ($progress!==null) { $entry->belowContent(self::below(['value'=>null,'sub'=>null,'progress'=>$progress])); }
            return Sheet::row($entry,$side,pending:$progress['waiting']??false,key:'fact_'.md5($family['label']));
        }
        $entry=$this->entry($family['label'],$fact);
        if ($family['label']==='DS records'&&$fact['value']!==null) { $entry->prefix('DNSSEC: '); }
        if (!($family['state']==='unknown'&&$fact['value']===null)) { $entry->aboveContent($status); }
        return Sheet::row($entry,$side,pending:$fact['progress']['waiting']??false,key:'fact_'.md5($family['label']));
    }
    /** The rail: the newest history, with the tab holding the rest. */
    private function activity(Domain $d): Section
    {
        $entries=DomainHistory::entries($d);
        $url=DomainResource::getUrl('view',['record'=>$d->id,'relation'=>'0']);
        return Sheet::section('Activity',[$entries===[]?Text::make('No history yet'):Sheet::trace(DomainHistory::steps($d,array_slice($entries,0,Sheet::RAIL_STEPS),DomainFacts::headRequest($d,$this->status(),$this->edits()['request'],$this->notice()),$this->said()))],
            after:$entries===[]?[]:[Sheet::link('view_history','View all '.count($entries),$url)]);
    }
    /** A row's action column: its edits or its link. Renewal and Last sync draw the page's own actions. */
    private function side(string $label, array $fact, array $edits, ?string $review): array|Html
    {
        if ($label==='Renewal') {
            // On a row that says Off, Stop shows only when the row says what it still stops.
            $buttons=array_filter(['stop','resume'],fn(string $name)=>(($this->cachedActions[$name]??null)?->isVisible()??false)&&($name!=='stop'||$fact['value']!=='Off'||str_contains((string)$fact['sub'],'blocks both')));
            $html=[...array_map(fn(string $name)=>$this->cachedActions[$name]->toHtml(),$buttons),...($review?[$review]:[])];
            return $html?Html::make(Sheet::buttons($html)):[];
        }
        if ($label==='Open alerts') { return $review?Html::make(Sheet::buttons([$review])):[]; }
        // One Resume sync per page: the Registrar records head draws it when the records show the stop.
        if ($label==='Last sync') { if (DomainFacts::syncNow($this->getRecord())) { return []; } $resume=$this->getAction('resume_observation',false); return $resume?->isVisible()&&!($this->familyState()['resume']??false)?Html::make(Sheet::buttons([$resume->toHtml()])):[]; }
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
        if (isset($fact['retry'])) {
            $retry=Sheet::moment($fact['retry'],'Next automatic retry:')->toHtml().'.';
            $words=new HtmlString($words===null?$retry:($words instanceof HtmlString?$words->toHtml():e($words)).' '.$retry);
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
        return Sheet::row($this->entry($label,$fact),$side,pending:$fact['progress']['waiting']??false,key:'fact_'.md5($label));
    }
    /** Row keys whose words differ on the page: renewal reads as the customer's Renewal invoices. */
    private const SHOWN=['Renewal'=>'Renewal invoices'];
    /** A fact's value with its words, tone, red Missing and demo tag. */
    private function entry(string $label, array $fact): TextEntry
    {
        $waiting=$fact['progress']['waiting']??false;
        if ($label==='Transfer lock'&&in_array($fact['value'],['On','Off'],true)) { $fact=self::lockMark($fact,$waiting); }
        $entry=TextEntry::make('value_'.md5($label))->label(self::SHOWN[$label]??$label)->state($fact['value'])
            ->belowContent(self::below($fact));
        if (is_array($fact['value'])) { $entry->listWithLineBreaks(); }
        if ($label==='Nameservers'&&$fact['value']!==null) { $entry->fontFamily(FontFamily::Mono); }
        if ($label==='Service'&&$fact['value']!==null) { $entry->formatStateUsing(fn()=>new HtmlString('<span class="font-mono domains-mono">'.DomainName::html($this->getTitle())->toHtml().'</span> registration')); }
        if (isset($fact['at'])) { $entry->formatStateUsing(fn()=>Sheet::moment($fact['at'])); }
        if ($fact['help']) { $entry->hintAction(HelpHint::make($fact['help'])); }
        if ($fact['demo']) { $entry->suffix(DemoData::tag(inline:true)); }
        $entry=MissingEntry::apply($entry,cause:$fact['value']===null?$fact['sub']:null,color:$fact['value']!==null&&($fact['alert']??false)?null:$fact['tone']);
        // A timed retry follows the words in the viewer's time zone, under a red Missing too.
        if (isset($fact['retry'])) { $entry->belowContent(self::below($fact)); }
        if (isset($fact['mark'])) { $entry=Sheet::state($entry,$fact['mark'],'lock-'.$this->getRecord()->id); }
        return $entry;
    }
    /** The lock reads as a mark: On, a hollow Off, or the change on its way with what it was. */
    public static function lockMark(array $fact, bool $waiting): array
    {
        if (!$waiting) { return [...$fact,'mark'=>$fact['value']==='On'?'success':'gray']; }
        return [...$fact,'value'=>$fact['progress']['text'],'sub'=>'Was '.strtolower($fact['value']).'.','mark'=>'info','progress'=>null];
    }
    private static function complimentaryTerms(Domain $d): array
    {
        if(!Staff::can('admin.domains.manage')||!Staff::can('admin.domains.fund')||!Staff::can(Staff::COSTS)||$d->status!=='active'||$d->service?->status!=='active'){return [];}
        // A renewal row that cannot be read is unsettled as far as this offer goes.
        try{if(self::renewalUnsettled($d)){return [];}
            if(\Paymenter\Extensions\Servers\Domains\Services\RenewalStop::stopped(\Paymenter\Extensions\Servers\Domains\Services\RenewalStop::read($d))||($d->renewal_policy['automatic_spend_paused']??false)){return [];}
            return array_filter(app(\Paymenter\Extensions\Servers\Domains\Services\RenewalCycles::class)->complimentaryOptions((int)$d->id,Auth::user())['terms'],fn($t)=>$t['cost']!==null);
        }catch(\Throwable){return [];}
    }
    /** A renewal of this domain is open or unconfirmed, or one already renewed the current expiry: a free one now could renew it twice at our cost. */
    public static function renewalUnsettled(Domain $d): bool
    {
        $expiry=$d->provider_expires_at?->format('Y-m-d');
        return $d->operations()->where('action','renew')->get(['id','state','base_expires_at'])->contains(fn($op)=>!in_array($op->state,\Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus::FINISHED,true)
            ||($op->state==='succeeded'&&$expiry!==null&&$op->base_expires_at?->format('Y-m-d')===$expiry));
    }
    private static function complimentaryRenewal(Domain $d): Action
    {
        return Action::make('complimentary_renewal')->label('Complimentary renewal')->color('gray')
            ->visible(fn()=>self::complimentaryTerms($d)!==[])
            ->modalHeading('Complimentary renewal')->modalSubmitActionLabel('Confirm complimentary renewal')->modalWidth('3xl')->modalFooterActionsAlignment(\Filament\Support\Enums\Alignment::End)
            ->schema(function()use($d):array {
                try{$options=app(\Paymenter\Extensions\Servers\Domains\Services\RenewalCycles::class)->complimentaryOptions((int)$d->id,Auth::user());}catch(\Throwable){$options=['terms'=>[]];}
                $terms=self::complimentaryTerms($d);$company=\Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels::company();
                // The domain's linked service owns its displayed annual price, even when owner requests are closed.
                try{$service=$d->service;$annual=$service?->currency_code==='USD'?\Paymenter\Extensions\Servers\Domains\Services\PriceCatalogue::retail((string)$service->price):null;}catch(\Throwable){$annual=null;}
                $term=fn(\Filament\Schemas\Components\Utilities\Get $get)=>$terms[(int)$get('years')]??null;
                $facts=static fn(array $rows)=>\Filament\Schemas\Components\Html::make(fn()=>view('domains::admin.renewal-facts',['facts'=>$rows]));
                return [$facts(['Domain'=>['value'=>$d->domain],'Expires'=>['value'=>$d->provider_expires_at?->format('M j, Y')??'Missing']]),
                    \Filament\Forms\Components\Select::make('years')->label('Years')->inlineLabel()->options(array_combine(array_keys($terms),array_map(fn($t)=>$t['years'].' '.($t['years']===1?'year':'years'),$terms)))->default(array_key_first($terms))->required()->live(),
                    \Filament\Schemas\Components\Html::make(fn(\Filament\Schemas\Components\Utilities\Get $get)=>view('domains::admin.renewal-facts',['facts'=>[
                        'New expiry'=>['value'=>isset($term($get)['expiry'])?\Carbon\CarbonImmutable::parse($term($get)['expiry'])->format('M j, Y'):'Missing','sub'=>'Up to '.($options['max_years']??0).(($options['max_years']??0)===1?' added year fits before ':' added years fit before ').(isset($options['horizon'])?\Carbon\CarbonImmutable::parse($options['horizon'])->format('M j, Y'):'the registration horizon').'.'],
                        'Total'=>['value'=>'$0.00','sub'=>isset($term($get)['cost'])?($company!==''?$company.' covers':'We cover').' the $'.number_format((float)$term($get)['cost'],2,'.','').' registrar renewal cost.':'Missing. Sync prices before renewing.'],
                        'Next annual renewal'=>['value'=>$annual===null?'Missing':('$'.$annual.'/yr'),'sub'=>'Renewal invoices stay annual. Renewal prices may change.'],
                    ]]))];
            })->action(function(array $data)use($d):void {
                try {
                    $cycles=app(\Paymenter\Extensions\Servers\Domains\Services\RenewalCycles::class);$options=$cycles->complimentaryOptions((int)$d->id,Auth::user());
                    $term=$options['terms'][(int)($data['years']??0)]??null;
                    if(!$term||$term['cost']===null){throw new \DomainException('Current registrar cost is missing. Sync prices.');}
                    $cycles->complimentary((int)$d->id,Auth::user(),$term['years'],new \Paymenter\Extensions\Servers\Domains\Data\Money($term['cost'],'USD'),'staff_complimentary_renewal');
                    \Filament\Notifications\Notification::make()->success()->title('Renewal queued')->send();
                } catch(\Throwable){\Filament\Notifications\Notification::make()->danger()->title('Could not renew')->body('Reload the domain and check its renewal terms and registrar cost.')->send();}
            });
    }
}
