<?php
// Adapted from Enom 444f31c ViewDomain, split read-only support; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{AcquisitionActions,AcquisitionPaymentReview,DepartedPage,DomainEditActions,DomainFacts,DomainRows,HoldingActions,NoticeReview,OperationActions,RegistrarRows,Sheet,Staff};
use Paymenter\Extensions\Servers\Domains\Staff\Holdings\DomainHistory;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head,Partial,Row,Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{RecordDetailPage,Section};
use Paymenter\Extensions\Servers\Domains\Presentation\{Reader,StaffTime,State};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{DepartureChecks,HoldingDeparture,HoldingReadRetry,OwnedDomains};
use Paymenter\Extensions\Servers\Domains\Holding\Observation\ObservationRecords;
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** One domain: its own state in the head, then its sections as rows, with Activity beside them and History as a tab.
 * Each notice sits first in the section it concerns, one per section. The page follows a change at its own cadence. */
class ViewDomain extends RecordDetailPage
{
    /** The page reads stored progress every 2 seconds, or 10 for a slow change, kept alive for a bounded time (managementPoll), then stops. */
    protected function poll(): ?array { $poll=$this->managementPoll(); return $poll['every']===null?null:['pollManagement',$poll['every']==='2s'?2:10,true]; }
    public function pollManagement(): void { Staff::requireView(); $this->getRecord()->refresh(); $this->edits=$this->facts=$this->domainStatus=$this->families=$this->head=null; $this->notice=false;
        // The sheet was built before the poll ran; rebuild it from the fresh facts.
        $this->cacheSchema('content'); }
    #[\Livewire\Attributes\Locked]
    public ?int $resumeStarted=null;
    public function syncPending(): bool
    {
        $now=app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp();
        if ($this->resumeStarted!==null&&$now<$this->resumeStarted+900) {
            $job=\Paymenter\Extensions\Servers\Domains\Services\HoldingObservationWork::current($this->getRecord(),true)->first();
            if ($job&&($job->safe_code===null||\Paymenter\Extensions\Servers\Domains\Services\HoldingObservationWork::reading($job,$now))) { return true; }
        }
        return \Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords::pending($this->getRecord());
    }
    public function managementProgress(): array
    { return \Paymenter\Extensions\Servers\Domains\Management\Changes\ManagementProgress::for($this->getRecord()); }
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
    /** The domain's own state, with its tag. What the state is waiting for opens its section, as a notice. */
    protected function head(): Head
    {
        Staff::requireView(); $d=$this->getRecord();
        $head=Head::make((string)$d->domain,Reader::Staff)->domain()->tag(DemoData::is($d),DomainFacts::test($d))
            ->state(State::tryFrom((string)($this->status()['key']??''))??State::UnderReview,'domain-'.$d->id);
        // A confirmed departure names its saved reason and who confirmed it (mock 07).
        if (($r=DepartedPage::record($d))!==null) { [$note,$confirmed]=DepartedPage::head($d,$r); $head->note($note); return $confirmed===null?$head:$head->detail($confirmed); }
        if (!$this->departureSuspected()) { return $head; }
        $at=$this->absenceReadAt();
        return $head->note('Not in our registrar account.')->detail(\Paymenter\Extensions\Servers\Domains\Management\Changes\ManagementNameservers::registrar($d).($d->server?->name?', '.$d->server->name:'').'.'.($at?' Read '.StaffTime::at($at).'.':''));
    }
    private function absenceReadAt(): ?int { return HoldingDeparture::absenceAt($this->getRecord()); }
    private function headLine(): array { return $this->head??=DomainFacts::head($this->getRecord(),$this->status(),$this->facts(),$this->edits()['request'],$this->notice()); }
    /** The head's step as plain text, so rows and Activity can leave out what it already says. */
    private function said(): ?string
    { $step=$this->headLine()['step']; return $step===null?null:html_entity_decode(strip_tags($step instanceof Htmlable?$step->toHtml():$step),ENT_QUOTES|ENT_HTML5); }
    /** History gets the request the head names and the head's words, so it leaves out the reason the head already says (DomainHistory::repeatsHead). */
    public function getRelationManagers(): array
    {
        return array_map(fn($manager)=>$manager===RequestsRelationManager::class?RequestsRelationManager::make([
            'headRequest'=>DomainFacts::headRequest($this->getRecord(),$this->status(),$this->edits()['request'],$this->notice()),'headSaid'=>$this->said()]):$manager,parent::getRelationManagers());
    }
    /** Unreadable evidence has its own notice; it cannot establish a departure finding. */
    private function departureSuspected(): bool
    { try { return isset($this->record)&&DepartureChecks::suspected($this->getRecord()); } catch (\Illuminate\Contracts\Encryption\DecryptException) { return false; } }
    private function edits(): array { return $this->edits??=DomainEditActions::for($this->getRecord()); }
    private function status(): array { return $this->domainStatus??=DomainFacts::status($this->getRecord()); }
    private function notice(): ?array { if ($this->notice===false) { $this->notice=DomainFacts::notice($this->getRecord()); } return $this->notice; }
    private function facts(): array
    {
        if ($this->facts!==null) { return $this->facts; }
        $d=$this->getRecord(); $facts=DomainFacts::for($d,$this->notice(),$this->familyState()); $progress=$this->managementProgress();
        if ($this->departureSuspected() || $this->edits()['authCode'] || isset($progress['auth_code_email'])) { $facts['Email auth code']=['value'=>'Goes to the '.OwnedDomains::authCodeRecipient($d),'sub'=>'Never shown here.','url'=>null,'tone'=>null,'help'=>'The code moves this domain to another registrar. We email it and never show it here.','demo'=>false]; }
        if ($this->departureSuspected()) {
            // Say which facts came from the absence read and which were kept from the last read while the domain was ours.
            if (isset($facts['In registrar account'])&&($at=$this->absenceReadAt())!==null) { $facts['In registrar account']['sub']='Last read '.StaffTime::at($at).'.'; }
            if (in_array($facts['Transfer lock']['value']??null,['On','Off'],true)) { $facts['Transfer lock']['sub']='Last read while in our registrar account.'; }
            // Staff see where DNS is served, since registrar DNS stops when a transfer completes.
            if (($case=DepartureChecks::staffDnsCase($d))!==null) {
                if (is_array($facts['Nameservers']['value']??null)&&$facts['Nameservers']['value']!==[]) { $facts['Nameservers']=[...$facts['Nameservers'],'value'=>$case[0],'sub'=>trim(implode(', ',$facts['Nameservers']['value']).(is_string($facts['Nameservers']['sub']??null)&&$facts['Nameservers']['sub']!==''?'. '.$facts['Nameservers']['sub']:''))]; }
                if (isset($facts['DNS records'])) { $sub=$facts['DNS records']['sub']??null;
                    $facts['DNS records']['sub']=$sub instanceof Htmlable?new \Illuminate\Support\HtmlString(e($case[1]).' '.$sub->toHtml()):trim($case[1].' '.(string)$sub); }
            }
            if (($facts['Renewal']['value']??null)==='On') { $facts['Renewal']=[...$facts['Renewal'],'value'=>'Paused','sub'=>'Set to On. Resumes if the domain is still ours.','tone'=>null,'alert'=>false]; } $facts['Next invoice']=['value'=>'Paused','sub'=>($facts['Next invoice']['value']??null)==='Paused'?$facts['Next invoice']['sub']:DepartureChecks::RENEWAL_WAITS,'url'=>null,'tone'=>null];
            // Rows that confirmation changes say so in their own helper, for staff who may confirm.
            if (\Paymenter\Extensions\Servers\Domains\Admin\Support\DepartureConfirm::allowed()) { $facts=\Paymenter\Extensions\Servers\Domains\Admin\Support\DepartureConfirm::pageFacts($d,$facts); } }
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
    }
    public function getBreadcrumbs(): array { return [$this->getResourceUrl()=>'Domains',$this->getTitle()]; }
    protected function actions(): array
    {
        $d=$this->getRecord();
        // Import domain for a pending import sits in the page notice.
        return [\Paymenter\Extensions\Servers\Domains\Admin\Support\DepartureConfirm::action($d),$this->approveComplimentaryCost($d),self::complimentaryRenewal($d),$this->syncNow(),...AcquisitionActions::make($d),...AcquisitionPaymentReview::actions($d),$this->customerView($d)];
    }
    /** A held order's Release hold and Cancel and refund, the same actions as its request page. They act on that request,
     * so they sit on its own row under the Registration notice, which keeps its one link (P-NTC-5, P-ACT-9, P-ACT-11). */
    private static function heldOrderRow(Domain $d): ?Row
    {
        $op=$d->operations()->whereIn('action',['register','transfer'])->where('authority_generation',$d->authority_generation)->where('account_fingerprint',$d->account_fingerprint)->where('state','held')->latest('id')->first();
        if(!$op){return null;}
        $op->setRelation('domain',$d);$stamp=\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::stamp($op,$d);
        $actions=array_values(array_filter(OperationActions::make($op,$stamp),fn(Action $a)=>in_array($a->getName(),['release','refund'],true)&&$a->isVisible()));
        if($actions===[]){return null;}
        return Row::make('Held order',Value::fact(\Paymenter\Extensions\Servers\Domains\Presentation\Fact::entered('Request #'.$op->id)))->key('held_order')->actions(...$actions);
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
        foreach (HoldingActions::make($this->getRecord()) as $action) { $this->cacheAction(Sheet::button($action)); /* Quiet, like every row button; red only in its confirm. */ }
        // Release hold sits in the Billing notice of the account hold it releases.
        foreach (\Paymenter\Extensions\Servers\Domains\Admin\Support\AccountMoneyHoldActions::actions($this->getRecord()) as $action) { $this->cacheAction(Sheet::button($action)); }
    }
    private function syncNow(): Action
    {
        // Record reads use registrar calls; Sync now lets staff request them only when needed. Under review it queues the
        // same departure read as Check again, so a grace or expired holding is admitted and the two share one pending read.
        return Action::make('sync_now')->label('Sync now')->color('gray')->disabled(fn()=>\Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords::pending($this->getRecord()))->visible(fn()=>DomainFacts::syncNow($this->getRecord())||(Staff::can('admin.domains.manage')&&$this->departureSuspected()))
            ->action(function():void {
                if (!Staff::can('admin.domains.manage')) { abort(403); }
                try {
                    $queued=\Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords::enqueue((int)\Illuminate\Support\Facades\Auth::id(),$this->getRecord()->refresh(),$this->departureSuspected());
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
    public function check_departureAction(): Action
    { return Sheet::button(Action::make('check_departure')->label('Check again')->visible(fn()=>Staff::can('admin.domains.manage')&&$this->departureSuspected())
        ->disabled(fn()=>\Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords::pending($this->getRecord()))->action(function():void{
            Staff::requireView();abort_unless(Staff::can('admin.domains.manage'),403);
            try{\Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords::enqueue((int)Auth::id(),$this->getRecord()->refresh(),true);}
            catch(\DomainException $error){\Filament\Notifications\Notification::make()->warning()->title($error->getMessage())->send();}
        })); }
    /** Resume sync sits in the page notice and is mounted as a page action. */
    public function resume_observationAction(): Action
    { return Sheet::button(OperationActions::scheduledReads($this->getRecord())[0]??Action::make('resume_observation')->hidden()); }
    public function notice_reviewAction(): Action
    { return Sheet::button(NoticeReview::action($this->getRecord())); }
    /** The sheet. An active domain leads with its registrar records, as in the approved mock; other states lead with
     * registration and billing. */
    protected function sections(): array
    {
        $this->cacheHoldingActions();
        $d=$this->getRecord(); $facts=DomainFacts::besideHead($this->facts(),$this->said()); $edits=$this->edits();
        // A confirmed departure reads from its saved record; the review's sections and notices no longer apply.
        if (($r=DepartedPage::record($d))!==null) {
            $customer=isset($facts['Customer'])?$this->row('Customer',$facts['Customer'],$this->side('Customer',$facts['Customer'],$edits,null)):null;
            // Live money, dispute and delivery alerts, and the reminder check, stay beside the saved facts until staff clear them.
            $review=$this->getAction('notice_review',false); $review=$review?->isVisible()?$review:null;
            $alerts=($fact=DepartedPage::alerts($d,$review!==null))===null?null:$this->row('Open alerts',$fact,$review?[$review]:[]);
            // A dispute lost after the departure still holds the account, so Billing keeps its notice and Release hold.
            return DepartedPage::sections($d,$r,$customer,$facts['Service']['url']??null,$alerts,$this->accountHold($d));
        }
        $suspected=$this->departureSuspected(); $late=!$suspected&&$this->recordsLate($d);
        // The notice above the sheet gives the cause and the next step once, so the rows it explains stay calm.
        $this->calmMissing=$late;
        // The summary above the sheet says once why values are missing, so rows drop the repeated cause.
        if ($late) { foreach ($facts as $label=>$fact) { if (is_string($fact['sub']??null)&&str_contains($fact['sub'],\Paymenter\Extensions\Servers\Domains\Staff\Holdings\DomainDecisions::NOT_RETURNED)) { $facts[$label]['sub']=trim(str_replace(\Paymenter\Extensions\Servers\Domains\Staff\Holdings\DomainDecisions::NOT_RETURNED,'',$fact['sub']))?:null; } } }
        // Values the registrar read fills in show Not read, in a neutral tone, until a read fails; Missing (red) is kept for that.
        if ($this->unread($d)) { foreach (self::READ_FACTS as $label) { if (isset($facts[$label])&&$facts[$label]['value']===null) { $facts[$label]=[...$facts[$label],'value'=>\Paymenter\Extensions\Servers\Domains\Presentation\Missing::NOT_READ,'tone'=>'gray','alert'=>false,
            'sub'=>is_string($facts[$label]['sub']??null)?(trim(str_replace(\Paymenter\Extensions\Servers\Domains\Staff\Holdings\DomainDecisions::NOT_RETURNED,'',$facts[$label]['sub']))?:null):($facts[$label]['sub']??null)]; } } }
        // WHOIS privacy stays stored and read, but the page does not show it (PrivacyVisibility).
        if (!\Paymenter\Extensions\Servers\Domains\Presentation\PrivacyVisibility::SHOWN) { unset($facts['WHOIS privacy']); }
        // Reminder issues sit on Renewal, then on Open alerts, which they open when neither row exists.
        $review=$this->getAction('notice_review',false); $review=$review?->isVisible()?$review:null;
        if ($review&&!isset($facts['Renewal'])) { $facts['Open alerts']??=['value'=>['A reminder needs a check.'],'sub'=>null,'url'=>null,'tone'=>'warning','help'=>null,'demo'=>false]; }
        $slot=isset($facts['Renewal'])?'Renewal':'Open alerts';
        // Late money's invoice is a second next step beside a read problem. A notice keeps one link, so the invoice gets its own row.
        $paid=$this->notice()['invoice']??null;
        if ($paid&&isset($this->notice()['link'])&&($url=\Paymenter\Extensions\Servers\Domains\Admin\Support\Facts::invoiceUrl((int)$paid))) { $facts['Invoice to check']=\Paymenter\Extensions\Servers\Domains\Admin\Support\Facts::row(\Paymenter\Extensions\Servers\Domains\Admin\Support\Facts::invoiceName((int)$paid),url:$url); }
        $placed=array_filter(DomainFacts::SECTIONS,fn(array $labels)=>array_intersect($labels,array_keys($facts))!==[]);
        // Contacts and DNS rows sit on their registrar record rows, with their edit buttons, as in the approved mock.
        $recordsHeld=isset($placed[self::HELD_SECTION]);unset($placed[self::HELD_SECTION]);
        // The rows that stay blocked are said once, in the notice of their section. A request's notice names it, so only a page with no such section and no request needs it elsewhere.
        $heldIn=$recordsHeld?'Registrar records':($edits['request']===null?array_key_first($placed):null);
        // A request's hold blocks Registrar records and Registration, so it is one notice under the head, said once.
        $this->pageHold=$suspected?null:$this->requestHold($d,$edits['request']);
        if ($this->pageHold!==null) { $heldIn=null; }
        $items=$this->noticeItems($d,$late,$heldIn);
        // The status notice opens Registration, or the first section when there is none.
        $statusIn=isset($placed['Registration'])?'Registration':(array_key_first($placed)??'Registrar records');
        $items[$statusIn]=[...($suspected?[]:$this->statusItems()),...($items[$statusIn]??[])];
        if ($suspected) { unset($items['Registrar records']); $items['Registration'][]=[State::UnderReview,DepartureChecks::PAUSED,null]; }
        $notices=[];
        foreach ($items as $title=>$list) { if (($row=DomainRows::notice(strtolower((string)preg_replace('/[^A-Za-z]+/','-',$title)),$list,'domain-'.$d->id.'-'.md5($title)))!==null) { $notices[$title]=$row; } }
        if ($suspected && isset($notices['Registration'])) { $notices['Registration']->target(); }
        $this->heldNotice=$this->pageHold??($suspected?(isset($placed['Registration'])?($notices['Registration']??null):null):($heldIn!==null?($notices[$heldIn]??null):null));
        // An open account hold opens Billing, with Release hold beside it (P-ACT-11).
        $money=$this->accountHold($d);
        if ($money!==null) { $placed['Billing']??=[]; }
        $sections=[];
        foreach ($placed as $title=>$labels) {
            $rows=[];
            foreach (array_intersect($labels,array_keys($facts)) as $label) { $rows[]=$this->row($label,$facts[$label],$this->side($label,$facts[$label],$edits,$label===$slot?$review:null)); array_push($rows,...DomainRows::retry($facts[$label])); }
            $held=$title==='Registration'?self::heldOrderRow($d):null;
            $notice=$title==='Billing'&&$money!==null?$money:($notices[$title]??null);
            $sections[]=Section::make($title,[...($notice?[$notice]:[]),...($held?[$held]:[]),...$rows]);
        }
        $records=[$this->recordFamilies($d,$facts,$edits,$notices['Registrar records']??null)];
        return $d->status==='active'?[...$records,...$sections]:[...$sections,...$records];
    }
    /** @return array{0: ?int, 1: ?int} First definite absence and last definite recheck. */
    private static function departureReadTimes(Domain $d, ?int $latest): array
    {
        if ($latest===null) { return [null,null]; }
        $history=$d->observation['departure_reads']??null;
        $reads=is_array($history)?array_filter($history,fn($read)=>is_int($read['at']??null)&&is_bool($read['recheck']??null)):[];
        $last=end($reads);
        // Capture follows the native save, so its history timestamp can fall in the next second.
        if (!$last || !$last['recheck'] || $last['at']<$latest) { return [$latest,null]; }
        foreach (array_reverse($reads) as $read) {
            if (!$read['recheck'] && $read['at']<=$latest && !HoldingReadRetry::ownedAfter($d,$read['at'])) {
                return [$read['at'],$latest];
            }
        }
        // A legacy or truncated history can retain a recheck after the first answer's date is gone.
        return [null,$latest];
    }
    private function departureCheck(): array
    {
        $d=$this->getRecord();$check=$d->observation['departure_check']??[];$registry=$check['registry']??[];$read=$d->observation['record_failure']??[];
        $fact=\Paymenter\Extensions\Servers\Domains\Presentation\Fact::class;
        $registrar=\Paymenter\Extensions\Servers\Domains\Management\Changes\ManagementNameservers::registrar($d);
        $latest=$this->absenceReadAt(); [$at,$found]=self::departureReadTimes($d,$latest);
        // The head carries the review state once; each read row says what that read found and when.
        $absent='Not in our registrar account';$where=$registrar.($d->server?->name?', '.$d->server->name:'');
        $first=$at?Value::fact($fact::entered($absent),$where.'. Read',$fact::date((int)$at,Reader::Staff)):Value::fact($fact::entered($absent),$where.'.');
        $rechecked=isset($check['at'])&&($check['rechecked']??false);
        $readAt=($read['identity']??null)===ObservationRecords::identity($d)
            &&($read['code']??null)!=='domain_not_in_account'&&is_string($read['at']??null)?(int)strtotime($read['at']):0;
        // A later try that got no answer: a saved failure or an attempt that stopped before saving any read.
        $retried=is_string($d->observation['read_retry']['at']??null)?(int)strtotime($d->observation['read_retry']['at']):0;
        $manual=\Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords::latestFailedReadAt($d);
        $tried=max($readAt,$retried,$manual);$tried=$tried>($latest??0)?$tried:null;
        $unclear=Value::state(State::UnderReview,Reader::Staff,$found===null?'The recheck got no answer. The first read is still the latest answer.':'The latest read got no answer.');
        $times=[...($tried?[$fact::date($tried,Reader::Staff,'Last tried')]:[]),...($found?[$fact::date($found,Reader::Staff,'Last found missing')]:[])];
        $scheduled=isset($check['at'])?(int)$check['at']+86400:null;
        $recheck=match(true){
            $scheduled===null&&$tried!==null=>Value::stateAt(State::CouldntRead,Reader::Staff,'Last tried',$fact::date($tried,Reader::Staff))
                ->note('No automatic recheck is scheduled.'),
            $scheduled===null=>Value::fact($fact::entered('Not scheduled'),'No automatic recheck scheduled.'),
            !$rechecked&&$tried!==null=>Value::state(State::Queued,Reader::Staff,'The latest read got no answer.')
                ->detail(...[...$times,$fact::date($scheduled,Reader::Staff,'Next automatic check')]),
            !$rechecked=>Value::state(State::Queued,Reader::Staff,'Automatic recheck scheduled',at:$fact::date($scheduled,Reader::Staff)),
            $found!==null&&$tried===null=>Value::fact($fact::entered($absent),'Last read',$fact::date($found,Reader::Staff)),
            default=>$times?$unclear->detail(...$times):$unclear};
        $statuses=array_values(array_unique(array_map(fn($status)=>match(strtolower(str_replace(' ','',$status))){'ok'=>'No registry restriction reported.','clienttransferprohibited','servertransferprohibited'=>'Transfers prohibited.','pendingtransfer'=>'Transfer pending.',default=>'Registry status is not recognized.'},$registry['status']??[])));
        // The row says whose registrar the registry lists. Staff compare names only when we can't name this holding's registrar.
        $listed=is_string($registry['registrar']??null)?trim($registry['registrar']):'';$other=DepartureChecks::registryListsOther($d);$ours=DepartureChecks::registryListsOurs($d);
        $lists=match(true){$listed===''=>'No registrar listed',$other=>'Lists another registrar, '.$listed,$ours=>'Lists '.$listed.', our registrar',default=>'Lists '.$listed};
        $found=implode(' ',[...$statuses,...($listed!==''&&!$other&&!$ours?['Compare with '.$registrar.'.']:[])]);
        $registryValue=match($registry['result']??null){'facts'=>isset($registry['at'])?Value::fact($fact::entered($lists),trim($found.' Read'),$fact::date($registry['at'],Reader::Staff)):Value::fact($fact::entered($lists),$found!==''?$found:null),
            'not_registered'=>Value::fact($fact::entered('Not registered')),null=>Value::state(State::NotReadYet,Reader::Staff),default=>Value::state(State::CouldntRead,Reader::Staff,'The public registry got no clear answer.')};
        // The next step stays after the automatic recheck: only a person can look at the registrar and settle it (phase B).
        $step=Row::make('Departure check',Value::fact($fact::entered('Next step: look for the domain at '.$registrar),'Then use Check again on the Recheck row.'))
            ->actions(Sheet::link('departure_registrar','Open registrar',\Paymenter\Extensions\Servers\Domains\Admin\Support\Facts::url(fn()=>\Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarFacts::accountUrl((int)$d->server_id))));
        // Staff who may confirm see the first blocking line here, or the confirm step; it describes the muted head action.
        if (\Paymenter\Extensions\Servers\Domains\Admin\Support\DepartureConfirm::allowed()) {
            $block=\Paymenter\Extensions\Servers\Domains\Admin\Support\DepartureConfirm::preview($d)['block'];
            if ($block!==null) {
                [$state,$text,$links]=\Paymenter\Extensions\Servers\Domains\Admin\Support\DepartureConfirm::line($block,$d);
                $step=Row::make('Departure check',Value::state($state,Reader::Staff,$text))->actions(...array_map(fn($l)=>Sheet::link(...$l),$links));
            } else {
                $step=Row::make('Departure check',Value::fact($fact::entered('Next step: check '.$registrar.', then confirm transferred away'),"Rows that say “on confirm” show what Confirm changes. Confirming can't be undone."))
                    ->actions(Sheet::link('departure_registrar','Open registrar',\Paymenter\Extensions\Servers\Domains\Admin\Support\Facts::url(fn()=>\Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarFacts::accountUrl((int)$d->server_id))));
            }
        }
        // Name carries the re-entry fact, so it stays on the page when a block replaces the step.
        $outcome=\Paymenter\Extensions\Servers\Domains\Admin\Support\DepartureConfirm::allowed()?array_values(array_filter(array_intersect_key(\Paymenter\Extensions\Servers\Domains\Admin\Support\DepartureConfirm::rows($d),['name'=>1,'email'=>1]))):[];
        return [$step->key('row-departure-next-step'),Row::make('First read',$first)->key('row-first-read'),Row::make('Recheck',$recheck)->actions($this->cachedActions['check_departure']??$this->check_departureAction()),Row::make('Public registry',$registryValue),...$outcome];
    }
    /** The rail: the newest history, with the History tab holding the rest. */
    protected function rail(): array
    {
        $d=$this->getRecord(); $entries=DomainHistory::entries($d);
        if ($entries===[]) { return [Section::make('Activity',[Row::make('History',Value::state(State::NotSet,Reader::Staff,'No history yet.'))])]; }
        $url=DomainResource::getUrl('view',['record'=>$d->id,'relation'=>'0']);
        return [Section::make('Activity',[DomainHistory::trace($d,array_slice($entries,0,Sheet::RAIL_STEPS),DomainFacts::headRequest($d,$this->status(),$this->edits()['request'],$this->notice()),$this->said())])
            ->link(Sheet::link('view_history','View all '.count($entries),$url))];
    }
    /** The open account hold as the Billing notice, with Release hold when this reader may release it (P-ACT-11). */
    private function accountHold(Domain $d): ?Row
    { return \Paymenter\Extensions\Servers\Domains\Admin\Support\AccountMoneyHoldActions::notice($d,fn(string $name)=>($a=$this->cachedActions[$name]??null)?->isVisible()?$a:null); }
    /** The notice that says why changes are held, so the rows it blocks point to it. */
    private ?Row $heldNotice=null;
    /** The request hold as one notice under the head, when a request holds changes. */
    private ?Row $pageHold=null;
    protected function pageNotice(): ?Row { return $this->pageHold; }
    /** The request that holds changes, in its own word: Checking or Delayed while its checks are scheduled, Under review once
     * staff must record the registrar's answer. Names both held sections and links the request (P-NTC-5 page form, P-ACT-8). */
    private function requestHold(Domain $d, ?int $request): ?Row
    {
        $op=$request===null?null:\Paymenter\Extensions\Servers\Domains\Models\Operation::find($request);
        if ($op===null) { return null; }
        $op->setRelation('domain',$d);
        $url=\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainNotices::requestUrl(['request'=>$op->id]);
        $link=$url?Sheet::link('held_request','Open request #'.$op->id,$url):null;
        $kind=['renew'=>'renewal ','register'=>'registration ','transfer'=>'transfer '][$op->action]??'';
        $who=\Paymenter\Extensions\Servers\Domains\Admin\Support\PaidUncertainty::registrar($op);
        $held='Changes to Registrar records and Registration wait for '.$kind.'request #'.$op->id;
        $stand=\Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts::answerState($op); $next=null;
        if ($stand==='checking') {
            $next=\Paymenter\Extensions\Servers\Domains\Services\RegistrarCheck::status($op)['next_at']??null;
            $state=\Paymenter\Extensions\Servers\Domains\Admin\Support\PaidUncertainty::waitState($op);
            $line=$held.', which '.$who.' has not confirmed yet.';
        } elseif ($stand!==null) { $state=State::UnderReview; $line=$held.', which needs '.$who.'’s answer recorded.'; }
        else { $state=State::UnderReview; $line='Changes are on hold until request #'.$op->id.' is checked.'; }
        // The next check is a time, so it goes on the staff line under the reason, not in it.
        $value=Value::state($state,Reader::Staff,$line,'domain-'.$d->id.'-hold');
        return Row::notice('request-hold',$next?$value->when((int)$next,'Next request check'):$value,$link);
    }
    /** What the domain's state waits for, which the head line used to say: the notice's cause and links, else the hold,
     * else the status step. A change the page stopped following says so here too.
     * @return list<array{0:State,1:mixed,2:?Action}> */
    private function statusItems(): array
    {
        $d=$this->getRecord(); $status=$this->status(); $notice=$this->notice(); $held=$this->edits()['request'];
        $word=State::tryFrom((string)($status['key']??''))??State::UnderReview;
        $items=[];
        if ($notice!==null) {
            $request=DomainFacts::headRequest($d,$status,$held,$notice);
            if (($notice['resume']??false)&&$request) { $notice['action']=['label'=>'Check request #'.$request,'url'=>\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainNotices::requestUrl(['request'=>$request])]; }
            $links=array_values(array_filter([$notice['action']??null,$notice['link']??null],fn(?array $l)=>$l!==null&&($l['url']??null)!==null));
            $state=match($notice['tone']??null){'danger'=>($notice['title']??null)===DomainFacts::UNREADABLE?State::CouldntRead:State::Failed,'warning'=>$word->tone(Reader::Staff)===\Paymenter\Extensions\Servers\Domains\Presentation\Tone::Attention?$word:State::NeedsReview,default=>$word};
            $items[]=[$state,implode(' ',array_filter([$notice['title']??null,$notice['body']??null])),isset($links[0])?Sheet::link('notice_link',$links[0]['label'],$links[0]['url']):null];
            // A second link (late money's invoice) is the Invoice to check row in Billing (sheet()).
        } elseif ($held!==null) {
            // The page notice under the head already says it.
            $url=$this->pageHold===null?\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainNotices::requestUrl(['request'=>$held]):null;
            if ($this->pageHold===null) { $items[]=[State::UnderReview,'Changes are on hold until request #'.$held.' is checked.',$url?Sheet::link('held_request','Check request #'.$held,$url):null]; }
        } else {
            $step=$this->headLine()['step'];
            if ($step!==null) { [$line,$link]=RegistrarRows::line($step,'status_link'); if ($line!==null) { $items[]=[$word,$line,$link]; } }
        }
        if ($this->managementPoll()['stopped']) { $items[]=[State::Checking,'A change is still waiting. Reload this page to check it again.',null]; }
        return $items;
    }
    /** Each notice in the section it concerns: costs in Renewal, the records read and the hold in Registrar records,
     * DNS setup. @return array<string,list<array{0:State,1:mixed,2:?Action}>> */
    private function noticeItems(Domain $d, bool $late, ?string $heldIn): array
    {
        $out=[]; $edits=$this->edits();
        if(Staff::can('admin.domains.fund')||Staff::can(Staff::COSTS)){foreach($d->operations()->where('state','held')->get() as $op){
            try{if($message=\Paymenter\Extensions\Servers\Domains\Services\ComplimentaryCosts::message($op,Staff::can(Staff::COSTS)&&Staff::can('admin.domains.fund')&&Staff::can('admin.domains.manage'))){
                if(!Staff::can(Staff::COSTS)){$message='Registrar cost approval is needed. Ask staff with access to domain costs to review it.';}
                $out['Renewal'][]=[State::ActionRequired,$message,null];
            }}
            catch(\Throwable){$out['Renewal'][]=[State::CouldntRead,'Registrar cost details are unavailable.',null];}
        }}
        $sync=\Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords::result($d);
        $recordsHeld=false;
        if(($sync['title']??null)==="Couldn't read all records"){
            try{$recordsHeld=\Paymenter\Extensions\Servers\Domains\Holding\Observation\ObservationRecords::held($d);}catch(\Throwable){}
        }
        // The late-read summary already says the read's cause and step, so a failed sync result is not said twice.
        if ($late) { $out['Registrar records'][]=$this->lateRecords($heldIn==='Registrar records'?$edits['note']:null); }
        elseif($sync!==null&&!in_array($sync['title'],['Domain synced','Sync retry scheduled'],true)&&!$recordsHeld){
            $out['Registrar records'][]=[State::NeedsReview,trim($sync['title'].'. '.RegistrarRows::sentence($sync['body']??null)),null];
        }
        $progress=$this->syncPending()?'Sync in progress; this page updates when the read finishes.':($this->syncRetryAt()!==null?'Sync will retry automatically.':null);
        if ($progress!==null) { $out['Registrar records'][]=[$this->syncPending()?State::Checking:State::Queued,$progress,null]; }
        if ($heldIn!==null&&!($late&&$heldIn==='Registrar records')&&$edits['note']!==null) {
            // A plain note is text: the notice escapes it once when it draws it.
            [$line,$link]=RegistrarRows::line($edits['note'],'held');
            $out[$heldIn][]=[State::UnderReview,$line,$link];
        }
        return $out;
    }
    /** Facts that come from the registrar read. */
    private const READ_FACTS=['Contacts','Nameservers','Glue records','DNS records','In registrar account','Transfer lock','WHOIS privacy','Email auth code','Registrar auto-renew'];
    /** Rows a late or failed registrar read leaves Missing: the page notice explains them once. */
    private const CALM_FACTS=[...self::READ_FACTS,'Domain settings','SRV records','DS records'];
    /** True while the late-read notice explains every Missing registrar value on the page. */
    private bool $calmMissing=false;
    /** No complete registrar read yet and no failed one: values are not read, not missing. */
    private function unread(Domain $d): bool
    {
        $s=$this->familyState();
        return !DomainFacts::ended($d)&&($s['last_complete_at']??null)===null&&!($s['read_failed']??false)&&!($s['cancelled_without_records']??false);
    }
    /** The registrar read is overdue or failing on a domain that has records to read. */
    private function recordsLate(Domain $d): bool
    {
        $s=$this->familyState();
        if (DomainFacts::ended($d)||($s['not_at_registrar']??null)!==null||($s['cancelled_without_records']??false)) { return false; }
        return (bool)(($s['read_overdue']??false)||($s['read_code']??null)||($s['failed_family']??null));
    }
    /** One summary of the registrar records exception, first in Registrar records: the cause and the next step, then the
     * read history in one sentence. @return array{0:State,1:string,2:null} */
    private function lateRecords(string|Htmlable|null $note): array
    {
        $s=$this->familyState(); $d=$this->getRecord();
        $last=$s['last_complete_at']??null;
        $at=static fn($when)=>StaffTime::at(is_int($when)?$when:(int)strtotime((string)$when));
        $text=trim((string)($s['read_cause']??'The registrar read failed.'));
        if (DomainFacts::syncNow($d)) { $text.=' Use Sync now to read it again.'; }
        if ($note!==null) {
            $plain=trim(html_entity_decode(strip_tags((string)($note instanceof Htmlable?$note->toHtml():$note)),ENT_QUOTES|ENT_HTML5));
            // The general hold has no cause of its own here: the read is the cause.
            $text.=' '.($plain===DomainEditActions::HELD?'Changes stay paused until a read succeeds.':$plain);
        }
        $history=$last?'Last complete read: '.$at($last).'.':'No complete read yet.';
        $attempts=(int)($s['read_attempts']??0);
        if ($attempts>=1) { $history.=' '.(($s['read_stopped']??false)?'Stopped after ':'').$attempts.' '.($attempts===1?'attempt':'attempts').'.'.(($s['read_failed_at']??null)?' Last failure: '.$at($s['read_failed_at']).'.':''); }
        // A read that is only overdue has not failed: its records are out of date.
        $failed=($s['read_code']??null)||($s['read_stopped']??false)||($s['failed_family']??null);
        return [$failed?State::Failed:State::OutOfDate,($failed?'Registrar read failed. ':'Registrar records are out of date. ').$text.' '.$history,null];
    }
    /** One row per registrar record family, between the shared records head and tail. A family's saved values, its
     * DNSSEC state and its edit buttons sit on the same row. */
    private function recordFamilies(Domain $d, array $facts, array $edits, ?Row $notice): Section
    {
        $state=$this->familyState();
        $resume=$this->getAction('resume_observation',false);
        $view=fn(string $part)=>Partial::make('domains::partials.record-families',['recordFamilies'=>$state,'staff'=>true,'part'=>$part,'readScheduling'=>!DomainFacts::ended($d),'summarised'=>$this->recordsLate($d)],'record-families-'.$part);
        $own=array_intersect_key($facts,array_flip(DomainFacts::SECTIONS[self::HELD_SECTION]));$own['DS records']=\Paymenter\Extensions\Servers\Domains\Presentation\DsState::staff($d);
        // Under the late-read notice, Sync now is said once, there.
        if ($this->calmMissing && is_string($own['DS records']['sub']??null)) { $own['DS records']['sub']=trim(str_replace('Use Sync now to read them.','',$own['DS records']['sub']))?:null; }
        $rows=[...($notice?[$notice]:[]),$view('head'),...($this->departureSuspected()?$this->departureCheck():[])];$shown=[];
        // SRV has no row of its own in the facts, so its change line comes straight from the stored progress.
        $srv=DomainFacts::progress($this->managementProgress(),'srv',DomainFacts::headRequest($d,$this->status(),$edits['request'],$this->notice()));
        foreach (($state['cancelled_without_records']??false)||($state['not_at_registrar']??null)!==null?[]:$state['rows'] as $family) {
            $label=$family['label'];$fact=$own[$label]??null;$shown[]=$label;
            $rows[]=$this->familyRow($family,$fact,$fact!==null&&$label!=='DS records'?$this->side($label,$fact,$edits,null):[],$family['kind']==='srv'?$srv:null);
        }
        // Nothing is at the registrar yet: the head says so once, with no row per record.
        foreach (($state['not_at_registrar']??null)!==null?[]:array_diff_key($own,array_flip($shown)) as $label=>$fact) { $rows[]=$this->row($label,$fact,$label==='DS records'?[]:$this->side($label,$fact,$edits,null)); array_push($rows,...DomainRows::retry($fact)); }
        $rows[]=$view('tail');
        $section=Section::make('Registrar records',$rows);
        return !DomainFacts::syncNow($d)&&$state['resume']&&$resume?->isVisible()?$section->link($resume):$section;
    }
    /** One registrar records read per render, shared by the records section and the Last sync row. */
    private ?array $families=null;
    private function familyState(): array
    { return $this->families??=\Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies::read($this->getRecord(),app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()); }
    /** A family row: the saved value with the family's read state under it when the read is not complete, or the read
     * state alone for families with no saved value (Domain settings, SRV), with SRV's change line. */
    private function familyRow(array $family, ?array $fact, array $side, ?array $progress = null): Row
    {
        if ($fact===null) {
            $row=Row::make($family['label'],DomainRows::family($family))->key('fact_'.md5($family['label']));
            return $progress===null?$row:DomainRows::progress($row,$progress);
        }
        if ($family['label']==='DS records'&&is_string($fact['value'])&&$fact['value']!==\Paymenter\Extensions\Servers\Domains\Presentation\Missing::NOT_READ) { $fact['value']='DNSSEC: '.$fact['value']; }
        $row=$this->row($family['label'],$family['state']==='complete'?[...$fact,'read'=>'complete']:$fact,$side);
        if (!in_array($family['state'],['complete','unknown'],true)&&!isset($fact['progress'])&&!in_array($fact['tone']??null,['danger','warning'],true)) { $row->result(DomainRows::family($family)); }
        return $row;
    }
    /** A row's actions: its edits or its link. Renewal and Last sync draw the page's own actions. @return list<Action> */
    private function side(string $label, array $fact, array $edits, ?Action $review): array
    {
        if ($label==='Renewal') {
            // On a row that says Off, Stop shows only when the row says what it still stops.
            $buttons=array_filter(['stop','resume'],fn(string $name)=>(($this->cachedActions[$name]??null)?->isVisible()??false)&&($name!=='stop'||$fact['value']!=='Off'||str_contains((string)$fact['sub'],'blocks both')));
            return [...array_map(fn(string $name)=>$this->cachedActions[$name],array_values($buttons)),...($review?[$review]:[])];
        }
        if ($label==='Open alerts') { return $review?[$review]:[]; }
        // One Resume sync per page: the Registrar records head draws it when the records show the stop.
        if ($label==='Last sync') { if (DomainFacts::syncNow($this->getRecord())) { return []; } $resume=$this->getAction('resume_observation',false); return $resume?->isVisible()&&!($this->familyState()['resume']??false)?[$resume]:[]; }
        if ($this->departureSuspected()&&in_array($label,['Transfer lock','Email auth code','Contacts','Nameservers'],true)) { $this->blocked[$label]=true; return [Sheet::button(Action::make('paused_'.md5($label))->label($label==='Nameservers'?'Edit':self::blockedLabel($label,$fact)))]; }
        $side=array_map(fn(Action $action)=>in_array($action->getLabel(),['Edit','Change'],true)?Sheet::edit($action,$label):Sheet::button($action),$edits['rows'][$label]??[]);
        if ($side===[]&&$edits['held']&&in_array($label,self::BLOCKED,true)) { $side[]=Sheet::button(Action::make('held_'.md5($label))->label(self::blockedLabel($label,$fact))); $this->blocked[$label]=true; }
        if ($fact['url']&&isset(DomainFacts::LINKS[$label])) { $side[]=Sheet::link('open_'.md5($label),DomainFacts::LINKS[$label],$fact['url']); }
        return $side;
    }
    /** Rows whose button stays, blocked, while changes are held. */
    private array $blocked=[];
    /** A blocked button keeps the label the real one would have. */
    public static function blockedLabel(string $label, array $fact): string
    { return match ($label) { 'Transfer lock'=>DomainEditActions::lockLabel(['On'=>true,'Off'=>false][$fact['value']]??null),'Nameservers'=>'Change','Email auth code'=>'Email code',default=>'Edit' }; }
    /** Row keys whose words differ on the page: renewal reads as the customer's Auto-renew. */
    private const SHOWN=['Renewal'=>'Auto-renew'];
    private function row(string $label, array $fact, array $side): Row
    {
        $waiting=$fact['progress']['waiting']??false;
        if ($label==='Transfer lock'&&$waiting) { $fact['sub']=RegistrarRows::sentence('Was '.strtolower((string)$fact['value']).'.'); }
        if ($label==='Service'&&$fact['value']!==null) { $fact['value']=$this->getRecord()->domain.' registration'; }
        // Under the read notice a row keeps its word and its own step: Not read yet while the read is late, Couldn't read once it failed.
        if ($this->calmMissing&&in_array($label,self::CALM_FACTS,true)&&$fact['value']===null) {
            $s=$this->familyState(); $failed=($s['read_code']??null)||($s['read_stopped']??false)||($s['failed_family']??null);
            $fact=[...$fact,'sub'=>$fact['sub']??null,'value'=>$failed?null:\Paymenter\Extensions\Servers\Domains\Presentation\Missing::NOT_READ,'tone'=>null];
        }
        $row=DomainRows::row($label,$fact,$side,self::SHOWN[$label]??null);
        if ($this->departureSuspected()) { if (in_array($label,['Nameservers','Contacts'],true)) { return $row->blocked('Paused while we check this domain.'); }
            // Phase B: nothing serves registrar DNS once the registry lists another registrar, so Edit and Edit SRV are muted.
            if ($label==='DNS records'&&\Paymenter\Extensions\Servers\Domains\Admin\Support\DepartureConfirm::allowed()&&($stopped=DepartureChecks::dnsStopped($this->getRecord()))!==null) { return $row->blocked($stopped." Confirm doesn't change DNS."); } if ($label==='Renewal'&&($this->cachedActions['resume']??null)?->isVisible()) { return $row->blocked('Auto-renew can turn on once this domain is back in our registrar account.'); } }
        if (($this->blocked[$label]??false)) {
            if ($this->heldNotice!==null) { $row->blockedBy($this->heldNotice); } else { $row->blocked((string)$this->edits()['held']); }
        }
        return $row;
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
                $terms=self::complimentaryTerms($d);$company=\Paymenter\Extensions\Servers\Domains\Dns\CompanyLabels::company();
                // The domain's linked service owns its displayed annual price, even when owner requests are closed.
                try{$service=$d->service;$annual=$service?->currency_code==='USD'?\Paymenter\Extensions\Servers\Domains\Services\PriceCatalogue::retail((string)$service->price):null;}catch(\Throwable){$annual=null;}
                $term=fn(\Filament\Schemas\Components\Utilities\Get $get)=>$terms[(int)$get('years')]??null;
                $facts=static fn(array $rows)=>\Filament\Schemas\Components\Html::make(fn()=>view('domains::admin.renewal-facts',['facts'=>$rows]));
                return [$facts(['Domain'=>['value'=>$d->domain],'Expires'=>['value'=>$d->provider_expires_at?->format('M j, Y')??'Missing']]),
                    \Filament\Forms\Components\Select::make('years')->label('Years')->inlineLabel()->options(array_combine(array_keys($terms),array_map(fn($t)=>$t['years'].' '.($t['years']===1?'year':'years'),$terms)))->default(array_key_first($terms))->required()->live(),
                    \Filament\Schemas\Components\Html::make(fn(\Filament\Schemas\Components\Utilities\Get $get)=>view('domains::admin.renewal-facts',['facts'=>[
                        'New expiry'=>['value'=>isset($term($get)['expiry'])?\Carbon\CarbonImmutable::parse($term($get)['expiry'])->format('M j, Y'):'Missing','sub'=>'Up to '.($options['max_years']??0).(($options['max_years']??0)===1?' added year fits before ':' added years fit before ').(isset($options['horizon'])?\Carbon\CarbonImmutable::parse($options['horizon'])->format('M j, Y'):'the registration horizon').'.'],
                        'Total'=>['value'=>'$0.00','sub'=>isset($term($get)['cost'])?($company!==''?$company.' covers':'We cover').' the $'.number_format((float)$term($get)['cost'],2,'.','').' registrar renewal cost.':'No current registrar renewal cost.'],
                        'Next annual renewal'=>['value'=>$annual===null?'Missing':('$'.$annual.'/yr'),'sub'=>'Renewal invoices stay annual. Renewal prices may change.'],
                    ]]))];
            })->action(function(array $data)use($d):void {
                try {
                    $cycles=app(\Paymenter\Extensions\Servers\Domains\Services\RenewalCycles::class);$options=$cycles->complimentaryOptions((int)$d->id,Auth::user());
                    $term=$options['terms'][(int)($data['years']??0)]??null;
                    if(!$term||$term['cost']===null){throw new \DomainException('Current registrar cost is missing.');}
                    $cycles->complimentary((int)$d->id,Auth::user(),$term['years'],new \Paymenter\Extensions\Servers\Domains\Data\Money($term['cost'],'USD'),'staff_complimentary_renewal');
                    \Filament\Notifications\Notification::make()->success()->title('Renewal queued')->send();
                } catch(\Throwable){\Filament\Notifications\Notification::make()->danger()->title('Could not renew')->body('Reload the domain and check its renewal terms and registrar cost.')->send();}
            });
    }
}
