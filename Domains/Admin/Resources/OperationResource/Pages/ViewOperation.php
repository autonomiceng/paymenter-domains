<?php
// Enom 444f31c request review layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages;
use Filament\Actions\{Action, ActionGroup};
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\{Callout, Html, Section, Text};
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource, OperationResource};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts, EnomAnswerAction, HistoryRecovery, Labels, MissingEntry, OperationActions, RegistrarFacts, RequestFacts, Sheet, Staff, StaffReviewHistory, StatusMark};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{EnomAnswers, StaffOperationReview, StaffSettingsReview};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
class ViewOperation extends ViewRecord
{
    protected static string $resource=OperationResource::class;
    protected \Filament\Support\Enums\Width|string|null $maxContentWidth=Sheet::WIDTH;
    /** Actions in the More menu; Check status and Release hold sit in the notice. */
    private const MORE=['hold','disposition','refund','credit','replacement'];
    public function getTitle(): string { Staff::requireView(); return 'Request #'.$this->getRecord()->id; }
    public function getHeading(): HtmlString
    { return new HtmlString('<span class="domains-title">'.e($this->getTitle()).(OperationResource::demo($this->getRecord())?DemoData::tag(inline:true)->toHtml():'').'</span>'); }
    /** The state and, when no notice says it, the reason. */
    public function getSubheading(): string|Htmlable|null
    {
        $op=$this->getRecord(); $status=RequestFacts::status($op,true,$op->domain);
        return StatusMark::line($status['tone'],$status['label'],'request-'.$op->id,RequestFacts::notice($op)===null?$status['reason']:null);
    }
    public function getBreadcrumbs(): array { return [$this->getResourceUrl()=>'Registrar requests',$this->getTitle()]; }
    protected function fillForm(): void { $this->data=[]; }
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
        if (isset(StaffOperationReview::ACTIONS[$name])) {
            $op=$this->getRecord()->refresh();
            if (!hash_equals($this->reviewStamp,StaffOperationReview::stamp($op,$op->domain))||!OperationActions::relevant($name,$op)) {
                OperationActions::stale($name,$op)->send(); $this->reloadRequest(); return null;
            }
        }
        return parent::mountAction($name,$arguments,$context);
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
        unset($this->cachedSchemas['infolist']);
        $this->cachedActions=[]; $this->cachedHeaderActions=[];
        $this->cacheInteractsWithHeaderActions();
        $this->forceRender();
    }
    protected function getHeaderActions(): array
    {
        Staff::requireView(); $op=$this->getRecord();
        $more=array_values(array_filter($this->actions(),fn(Action $a)=>in_array($a->getName(),self::MORE,true)));
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
    private function pageAction(string $name): Action
    { return array_values(array_filter($this->actions(),fn(Action $a)=>$a->getName()===$name))[0]; }
    private function actions(): array { return OperationActions::make($this->getRecord(),$this->reviewStamp); }
    public function infolist(Schema $schema): Schema
    {
        $op=$this->getRecord(); $grants=Staff::requireView(); $facts=RequestFacts::for($op,$grants); $components=[];
        // Nothing else on the page says why this request has stopped moving.
        $notice=RequestFacts::notice($op,$facts)??($this->getAction('enom_answer',false)?->isVisible()?['title'=>'Only '.($op->registrar_key==='namesilo'?'NameSilo':'Enom').' can confirm this result.','body'=>'Ask the registrar to check this attempt, then enter its answer.','tone'=>'warning']:null);
        if ($notice) { $components[]=$this->notice($notice); }
        $account=RequestFacts::account($op);
        $setup=self::url(fn()=>RegistrarFacts::accountUrl((int)$op->server_id));
        $rows=[
            self::entry('Domain',$op->domain?->domain,url:$op->domain?self::url(fn()=>DomainResource::getUrl('view',['record'=>$op->domain_id])):null,cause:'Domain not found'),
            self::entry('Type',Labels::action($op->action)),
            self::entry('Registrar account',$account['value'],sub:$account['sub'],url:$setup,demo:$account['account']&&DemoData::is($account['account'])),
        ];
        // History opens with Request created and its time, so Details says it only when History can't be read.
        $history=StaffReviewHistory::rows($op,$grants);
        if ($history===null) { $rows[]=self::entry('Created',self::time($op->created_at),cause:RequestFacts::NO_TIME,at:$op->created_at); }
        // Sent to registrar is hidden while the request has not been sent. An unsure send is History's to say, once.
        if (($op->started_at || in_array($op->state,['running','pending','uncertain','succeeded'],true)) && StaffReviewHistory::sendEvent($op)===StaffReviewHistory::SENT) { $rows[]=self::entry(StaffReviewHistory::SENT,self::time($op->started_at),cause:RequestFacts::NO_TIME,at:$op->started_at); }
        if (!($facts['unreadable']??false)) {
            if (isset($facts['registrar_reply'])) { $rows[]=self::entry('Registrar reply',$facts['registrar_reply']); }
            elseif (isset($facts['send_failure'])) { $rows[]=self::entry('Registrar reply',$facts['send_failure']); }
            if (array_key_exists('invoice',$facts)) { $rows[]=self::entry('Invoice',$facts['invoice'],sub:$facts['invoice_sub'],url:$op->invoice_id?self::invoiceUrl((int)$op->invoice_id):null); }
            if (array_key_exists('cost',$facts)) { $rows[]=self::entry('Cost',$facts['cost'],sub:$facts['cost_sub']); }
            if (array_key_exists('auth_code',$facts)) {
                $email=$op->domain?->service?->user?->email;
                $rows[]=self::entry('Auth code',$facts['auth_code'],sub:$facts['auth_code_sub'],fix:$email?Action::make('ask_customer')->label('Ask customer')->url('mailto:'.$email):null);
            }
            if ($grants->costs && array_key_exists('refund',$facts)) {
                $fix=isset($facts['refund_fix'])?$this->getAction($facts['refund_fix'],false):null;
                $rows[]=self::entry('Credit',$facts['refund'],sub:$facts['refund_sub']??null,side:$fix?->isVisible()?Html::make(Sheet::buttons([$fix->size('sm')->toHtml()])):[]);
            }
        }
        $components[]=Sheet::section('Details',$rows);
        return $schema->components([Sheet::split($components,[$this->history($op,$history)])]);
    }
    /** The rail: what happened, newest first. The newest step carries the request's state. */
    private function history(Operation $op, ?array $rows): Section
    {
        if ($rows===null) { return Sheet::section('History',[TextEntry::make('history_unreadable')->hiddenLabel()->state(DomainFacts::UNREADABLE)->color('danger')]); }
        $status=RequestFacts::status($op,true,$op->domain);
        $steps=array_map(fn(array $row,int $i)=>Sheet::event($row['event'],$row['sub'],$row['time'],$row['by'],$i===0?$status['tone']:'gray',noTime:RequestFacts::NO_TIME),$list=array_reverse(StaffReviewHistory::timeline($op,$rows)),array_keys($list));
        return Sheet::section('History',[$steps===[]?Text::make('No history yet'):Sheet::trace($steps)]);
    }
    private function notice(array $notice): Callout
    {
        // The cause's link comes first, then the action that retries: "Go to Orders switch", then "Release hold".
        $buttons=[];
        $action=isset($notice['action'])?$this->getAction($notice['action'],false):null;
        $op=$this->getRecord();
        $link=match($notice['link']??null) {
            'registrars'=>[$label=$notice['link_label']??'Open account',self::url(fn()=>RegistrarFacts::accountUrl((int)$op->server_id,RequestFacts::SWITCH_LINKS[$label]??null))],
            'invoice'=>['Open invoice',$op->invoice_id?self::invoiceUrl((int)$op->invoice_id):null],
            'domain'=>['Open domain',$op->domain?self::url(fn()=>DomainResource::getUrl('view',['record'=>$op->domain_id])):null],
            default=>null};
        $acts=(bool)$action?->isVisible();
        $answer=$this->getAction('enom_answer',false);$answers=(bool)$answer?->isVisible();
        if ($link&&$link[1]) { $buttons[]=view('filament::components.button.index',['tag'=>'a','href'=>$link[1],'color'=>$acts||$answers?'gray':'primary','size'=>'sm','slot'=>new HtmlString(e($link[0]))])->render(); }
        // When the notice's step is the registrar's answer, that button leads and Check status follows.
        if ($answers&&($notice['primary']??null)==='enom_answer') { $buttons[]=$answer->color('primary')->size('sm')->toHtml(); $answers=false; if($acts){$buttons[]=Sheet::button($action)->toHtml();$acts=false;} }
        if ($acts) { $buttons[]=$action->color('primary')->size('sm')->toHtml(); }
        if ($answers) { $buttons[]=($acts?Sheet::button($answer):$answer->color('primary')->size('sm'))->toHtml(); }
        return Callout::make($notice['title'])->description($notice['body']??null)->status($notice['tone'])->columnSpanFull()
            ->footer($buttons?Sheet::buttons($buttons):null);
    }
    private static function entry(string $label, ?string $value, ?string $sub=null, ?string $url=null, bool $demo=false, ?Action $fix=null, ?string $cause=null, array|Html $side=[], ?\DateTimeInterface $at=null): \Filament\Schemas\Components\Flex
    {
        $entry=TextEntry::make('fact_'.md5($label))->label($label)->state($value)->url($url);
        if ($value!==null&&$sub!==null) { $entry->belowContent($sub); }
        if ($at) { $entry->formatStateUsing(fn()=>Sheet::moment($at->getTimestamp())); }
        if ($demo) { $entry->suffix(DemoData::tag(inline:true)); }
        if ($label==='Domain') { $entry->fontFamily('mono'); }
        // $sub sits under a present value; a Missing value shows $cause, else $sub as its cause.
        return Sheet::row(MissingEntry::apply($entry,fix:$fix,cause:$value===null?($cause??$sub):null),$side,key:'fact_'.md5($label));
    }
    private static function time(?\DateTimeInterface $at): ?string
    { return StaffReviewHistory::time($at); }
    private static function invoiceUrl(int $id): ?string
    {
        $invoice=\App\Models\Invoice::find($id);
        return $invoice&&\App\Admin\Resources\InvoiceResource::canEdit($invoice)?self::url(fn()=>\App\Admin\Resources\InvoiceResource::getUrl('edit',['record'=>$id])):null;
    }
    private static function url(\Closure $make): ?string { try { return $make(); } catch (\Throwable) { return null; } }
}
