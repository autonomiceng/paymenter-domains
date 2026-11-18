<?php
// Enom 444f31c request review layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages;
use Filament\Actions\{Action, ActionGroup};
use Illuminate\Contracts\Encryption\DecryptException;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource, OperationResource};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts, EnomAnswerAction, Facts, HistoryRecovery, Labels, OperationActions, RegistrarFacts, RequestFacts, RequestRows, Sheet, Staff, StaffReviewHistory, SupportDetails};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head, Row, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{RecordDetailPage, Section};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\{Reader, State};
use Paymenter\Extensions\Servers\Domains\Services\{EnomAnswers, StaffOperationReview, StaffSettingsReview};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** A registrar request: its notice and facts in the sheet, its history on the rail. */
class ViewOperation extends RecordDetailPage
{
    protected static string $resource=OperationResource::class;
    /** Actions in the More menu; Check status and Release hold sit in the notice. */
    private const MORE=['approve_charge','hold','disposition','refund','credit','replacement'];
    /** The title and the request's own word. Its reason opens the sheet. */
    protected function head(): Head
    {
        Staff::requireView(); $op=$this->getRecord();
        return Head::make('Request #'.$op->id,Reader::Staff)->tag(OperationResource::demo($op),false)
            ->state(RequestRows::state(RequestFacts::status($op,true,$op->domain),$op),'request-'.$op->id);
    }
    public function getBreadcrumbs(): array { return [$this->getResourceUrl()=>'Registrar requests',$this->getTitle()]; }
    #[\Livewire\Attributes\Locked]
    public string $reviewStamp='';
    #[\Livewire\Attributes\Locked]
    public string $answerStamp='';
    public function mount(int|string $record): void
    { parent::mount($record); $this->stamp($this->getRecord()); }
    private function stamp(Operation $op): void
    {
        $this->reviewStamp=StaffOperationReview::stamp($op,$op->domain);
        // Only a request that needs Enom's answer reads the settings review.
        $this->answerStamp=EnomAnswers::eligible($op,app(Clock::class)->now())?StaffSettingsReview::stamp((int)$op->server_id,null):'';
    }
    /** A hidden action unmounts in silence, so an old click is answered here, before Filament drops it. */
    public function mountAction(string $name, array $arguments=[], array $context=[]): mixed
    {
        if ($this->changed($name)) { OperationActions::stale($name,$this->getRecord())->send(); $this->reloadRequest(); return null; }
        return parent::mountAction($name,$arguments,$context);
    }
    /** A dialog opened before the change would do nothing on confirm, so its confirm closes it with the same answer. */
    public function callMountedAction(array $arguments=[]): mixed
    {
        $name=$this->mountedActions[0]['name']??null;
        if (is_string($name)&&$this->changed($name)) {
            while ($this->mountedActions!==[]) { $this->unmountAction(false); }
            OperationActions::stale($name,$this->getRecord())->send(); $this->reloadRequest(); return null;
        }
        return parent::callMountedAction($arguments);
    }
    private function changed(string $name): bool
    {
        if (!isset(StaffOperationReview::ACTIONS[$name])) { return false; }
        $op=$this->getRecord()->refresh();
        return !hash_equals($this->reviewStamp,StaffOperationReview::stamp($op,$op->domain))||!OperationActions::relevant($name,$op);
    }
    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);
        $this->reloadRequest();
    }
    private function reloadRequest(): void
    {
        // Writers load their own locked models. Rebuild every snapshot, including after a caught partial failure.
        $this->stamp($this->getRecord()->refresh());
        unset($this->cachedSchemas['content']);
        $this->cachedActions=[]; $this->cachedHeaderActions=[];
        $this->cacheInteractsWithHeaderActions();
        $this->forceRender();
    }
    protected function actions(): array
    {
        Staff::requireView(); $op=$this->getRecord();
        $more=array_values(array_filter($this->operationActions(),fn(Action $a)=>in_array($a->getName(),self::MORE,true)));
        return [ActionGroup::make($more)->label('More')->button()->color('gray')
            ->visible(fn()=>(bool)array_filter(self::MORE,fn($name)=>Staff::can(StaffOperationReview::permission($name))&&OperationActions::relevant($name,$op)))];
    }
    /** Check status and Release hold sit in the notice and are mounted as page actions. */
    public function recheckAction(): Action { return $this->pageAction('recheck'); }
    public function resume_readsAction(): Action { return $this->pageAction('resume_reads'); }
    public function releaseAction(): Action { return $this->pageAction('release'); }
    public function history_recoveryAction(): Action { return HistoryRecovery::action($this->getRecord()); }
    /** Sits on the one request that can't settle itself. */
    public function enom_answerAction(): Action { return EnomAnswerAction::make($this->getRecord(),$this->answerStamp); }
    /** A free change's final result, on the reply row where a paid one records the registrar support answer. */
    public function mark_failedAction(): Action { return $this->pageAction('mark_failed'); }
    private function pageAction(string $name): Action
    { return array_values(array_filter($this->operationActions(),fn(Action $a)=>$a->getName()===$name))[0]; }
    private function operationActions(): array { return OperationActions::make($this->getRecord(),$this->reviewStamp); }
    protected function sections(): array
    {
        $op=$this->getRecord(); $grants=Staff::requireView(); $facts=RequestFacts::for($op,$grants); $rows=[];
        $state=RequestRows::state($status=RequestFacts::status($op,true,$op->domain),$op);
        // Nothing else on the page says why this request has stopped moving.
        $answer=$this->getAction('enom_answer',false); $answers=(bool)$answer?->isVisible();
        $who=$op->registrar_key==='namesilo'?'NameSilo':'Enom';
        $notice=RequestFacts::notice($op,$facts)??($answers?['title'=>$who.' didn’t confirm this order.','body'=>'Open a ticket with '.$who.' support, then record their answer.','tone'=>'warning']:null);
        // Support needs the request's facts for the ticket, so whoever can record the answer can copy them.
        $copy=$answers&&($notice['copy']??true)?SupportDetails::action($op)->label('Copy details')->extraAttributes(['aria-label'=>SupportDetails::LABEL],merge:true):null;
        $fail=$this->getAction('mark_failed',false); $fail=$fail?->isVisible()?Sheet::button($fail):null;
        // The notice keeps its own action; staff who may close the change also learn when to use the button on Registrar reply.
        if ($notice!==null&&$fail!==null) { $notice['body']=trim(($notice['body']??'').' If you know the change didn’t happen, use '.$fail->getLabel().'.'); }
        // The notice's link moves to the row it names; the notice keeps the one action that retries, and the registrar's answer
        // sits on Registrar reply when the notice already has its action.
        $link=$notice===null?null:($notice['link']??null);
        $action=isset($notice['action'])?$this->getAction($notice['action'],false):null; $acts=(bool)$action?->isVisible();
        $lead=$notice===null?null:($answers&&(($notice['primary']??null)==='enom_answer'||!$acts)?$answer:($acts?Sheet::button($action,$notice['action']==='refund'?'danger':'gray'):null));
        // When the answer leads, the notice's own action (Check status, a read before a manual answer) sits on Registrar reply.
        $check=null;
        if ($lead===$answer&&$answer!==null) { $answers=false; $lead=Sheet::button($answer); $check=$acts&&$notice['action']!=='refund'?Sheet::button($action):null; }
        if ($notice) { $rows[]=RequestRows::notice($notice,$state,$lead); }
        elseif ($status['reason']!==null&&($reason=RequestRows::sentence($status['reason']))!==null) { $rows[]=RequestRows::reason($state,$reason,'request-'.$op->id); }
        $account=RequestFacts::account($op);
        $domain = $op->domain;
        $domainUrl=$domain?Facts::url(fn()=>DomainResource::getUrl('view',['record'=>$op->domain_id])):null;
        $accountUrl=$link==='registrars'?Facts::url(fn()=>RegistrarFacts::accountUrl((int)$op->server_id,RequestFacts::SWITCH_LINKS[$notice['link_label']??'Open account']??null)):Facts::url(fn()=>RegistrarFacts::accountUrl((int)$op->server_id));
        $invoiceUrl=$op->invoice_id?Facts::invoiceUrl((int)$op->invoice_id):null;
        $domainValue = RequestRows::text($domain?->domain, cause: 'Domain not found.');
        if ($domain !== null) {
            try {
                $tag = DemoData::label(DemoData::is($domain), DomainFacts::test($domain), inline: true);
                if ($tag->toHtml() !== '') {
                    $domainValue = $domainValue->tagged($tag);
                }
            } catch (DecryptException) {
                // An unreadable snapshot cannot establish whether this is an offline fixture.
            }
        }
        $rows[]=self::linked(Row::make('Domain', $domainValue),'Open domain',$domainUrl);
        $rows[]=Row::make('Type',RequestRows::text(Labels::action($op->action)));
        $rows[]=self::linked(Row::make('Registrar account',RequestRows::text($account['value'],$account['sub'])),$link==='registrars'?($notice['link_label']??'Open account'):'Open account',$accountUrl);
        // History opens with Request created and its time, so Details says it only when History can't be read.
        $history=StaffReviewHistory::rows($op,$grants);
        if ($history===null) { $rows[]=Row::make('Created',RequestRows::time($op->created_at,RequestFacts::NO_TIME)); }
        // A renewal waiting out Enom's repeat refusal goes at a fixed time; the notice says why, this row says when.
        if (($goes=\Paymenter\Extensions\Servers\Domains\Services\RenewalRepeatWait::until($op))!==null) { $rows[]=Row::make('Goes to registrar',RequestRows::time($goes,RequestFacts::NO_TIME)); }
        // Sent to registrar is hidden while the request has not been sent. An unsure send is History's to say, once.
        if (($op->started_at || in_array($op->state,['running','pending','uncertain','succeeded'],true)) && StaffReviewHistory::sendEvent($op)===StaffReviewHistory::SENT) { $rows[]=Row::make(StaffReviewHistory::SENT,RequestRows::time(StaffReviewHistory::sentAt($op),RequestFacts::NO_TIME)); }
        if (!($facts['unreadable']??false)) {
            $reply=$facts['registrar_reply']??$facts['send_failure']??null;
            if ($reply!==null||$answers||$check!==null||$copy!==null||$fail!==null||RequestFacts::answerState($op)!==null) {
                $row=Row::make('Registrar reply',$reply === null ? self::noReply($op) : RequestRows::text($reply));
                // The notice holds one action (P-NTC-5), so copying the ticket details sits here, on the registrar's reply.
                $replyActs=array_values(array_filter([$answers?Sheet::button($answer):$check,$fail,$copy]));
                $rows[]=$replyActs===[]?$row:$row->actions(...$replyActs);
            }
            if (array_key_exists('invoice',$facts)) { $rows[]=self::linked(Row::make('Invoice',RequestRows::invoice($facts['invoice'],$facts['invoice_state']??null,$facts['invoice_sub'])),'Open invoice',$invoiceUrl); }
            // A refund or dispute on this invoice means money we no longer hold, so staff must look.
            if (isset($facts['payment_reversed'])) {
                $words=$facts['payment_reversed']['words'];
                // The account hold a lost dispute opened is released on the domain page, so the row says so and links there; once
                // released it is a plain fact with when and by whom.
                $hold=$facts['payment_reversed']['hold']??null; $holdUrl=null;
                if (($hold['state']??null)==='open') {
                    $words=rtrim((string)$words).' The customer’s account is on hold until staff release it on the domain page.';
                    $holdUrl=Facts::url(fn()=>DomainResource::getUrl('view',['record'=>$hold['domain_id']]).'#'.\Paymenter\Extensions\Servers\Domains\Admin\Support\AccountMoneyHoldActions::ANCHOR);
                }
                $payment=Row::make('Payment',$words===null?RequestRows::text(null,cause:'Could not read refunds and disputes. Check the invoice payments.')
                    :($facts['payment_reversed']['act']?Value::state(State::NeedsReview,Reader::Staff,RequestRows::sentence($words))
                        :RequestRows::text($words,($hold['state']??null)==='released'?'Account hold released '.\Paymenter\Extensions\Servers\Domains\Presentation\StaffTime::at($hold['at']).($hold['by']?', by '.$hold['by']:'').'.':null)));
                $rows[]=self::linked($payment,'Go to account hold',$holdUrl);
            }
            if (array_key_exists('cost',$facts)) { $rows[]=Row::make('Cost',RequestRows::text($facts['cost'],$facts['cost_sub'])); }
            if (array_key_exists('auth_code',$facts)) {
                $email=$op->domain?->service?->user?->email;
                $row=Row::make('Auth code',RequestRows::text($facts['auth_code'],$facts['auth_code_sub']));
                $rows[]=$email?$row->actions(Sheet::link('ask_customer','Ask customer','mailto:'.$email)):$row;
            }
            if ($grants->costs && array_key_exists('refund',$facts)) {
                $fix=isset($facts['refund_fix'])?$this->getAction($facts['refund_fix'],false):null;
                $row=Row::make($facts['refund_label']??'Credit',RequestRows::text($facts['refund'],$facts['refund_sub']??null));
                $rows[]=$fix?->isVisible()?$row->actions(Sheet::button($fix)):$row;
            }
        } elseif ($check!==null) { $rows[]=Row::make('Registrar reply',RequestRows::text(null,cause:DomainFacts::UNREADABLE))->actions($check); }
        return [Section::make('Details',$rows)];
    }
    /** The rail: what happened, newest first. Each step shows its own outcome; the request's state is in the page head. */
    protected function rail(): array
    {
        $op=$this->getRecord(); $rows=StaffReviewHistory::rows($op,Staff::requireView());
        if ($rows===null) { return [Section::make('History',[Row::notice('history',Value::state(State::CouldntRead,Reader::Staff,DomainFacts::UNREADABLE))])]; }
        $steps=array_reverse(StaffReviewHistory::timeline($op,$rows));
        if ($steps===[]) { return [Section::make('History',[],'No history yet.')]; }
        return [Section::make('History',[RequestRows::trace($steps)])];
    }
    /** Registrar reply before the registrar answered: none needed yet while a check is scheduled, then what the stopped checks found. */
    private static function noReply(Operation $op): Value
    {
        $check=\Paymenter\Extensions\Servers\Domains\Services\RegistrarCheck::status($op)??[];
        $last=isset($check['at'])?\Paymenter\Extensions\Servers\Domains\Presentation\StaffTime::at((int)$check['at']):null;
        $history=$op->registrar_key==='enom'?'Enom’s order history':'the registrar';
        return match (RequestFacts::answerState($op)) {
            'checking'=>RequestRows::text('None yet','Needed only if checks stop without a clear answer.'),
            'unclear'=>RequestRows::text('No clear answer',trim(($n=(int)($op->action==='renew'?($op->policy['renewal_polls']??0):($op->policy['reconciliation']['polls']??0)))>0
                ?$n.' '.($n===1?'check':'checks').' of '.$history.'.':'').($last?' Last read '.$last.'.':'')),
            default=>Value::state(State::NotReadYet,Reader::Staff,'Waiting for the registrar\'s answer.'),
        };
    }
    /** A row whose value names a record gets the link to it. */
    private static function linked(Row $row, string $label, ?string $url): Row
    { return $url===null?$row:$row->actions(Sheet::link('open_'.substr(md5($label.$row->getKey(false)),0,8),$label,$url)); }
}
