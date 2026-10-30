<?php
// Enom 444f31c request review layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages;
use Filament\Actions\{Action, ActionGroup};
use Filament\Infolists\Components\{RepeatableEntry, RepeatableEntry\TableColumn, TextEntry};
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\{Callout, Section};
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource, OperationResource};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{BalanceCheck, Labels, MissingEntry, OperationActions, RegistrarFacts, RequestFacts, Staff, StaffReviewHistory};
use Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview;
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
class ViewOperation extends ViewRecord
{
    protected static string $resource=OperationResource::class;
    /** Actions in the More menu; Check status and Release hold sit in the notice. */
    private const MORE=['hold','disposition','refund','credit','replacement'];
    private const FACTS_STYLE='<style>@media (min-width:40rem){.domains-facts .fi-in-entry.fi-in-entry-has-inline-label{grid-template-columns:minmax(0,30%) minmax(0,1fr) minmax(0,1fr)}}</style>';
    public function getTitle(): string { Staff::requireView(); return 'Request #'.$this->getRecord()->id; }
    public function getHeading(): HtmlString
    {
        $status=Labels::requestStatus($this->getRecord());
        return new HtmlString('<span style="display:inline-flex;flex-wrap:wrap;align-items:center;gap:.5rem">'.e($this->getTitle())
            .(OperationResource::demo($this->getRecord())?DemoData::tag(inline:true)->toHtml():'')
            .view('filament::components.badge',['color'=>$status['tone'],'size'=>'lg','slot'=>new HtmlString(e($status['label']))])->render().'</span>'.self::FACTS_STYLE);
    }
    /** The reason line under the heading when no notice says it. */
    public function getSubheading(): ?string
    { $op=$this->getRecord(); return RequestFacts::notice($op)===null ? Labels::requestStatus($op)['reason'] : null; }
    public function getBreadcrumbs(): array { return [$this->getResourceUrl()=>'Registrar requests',$this->getTitle()]; }
    protected function fillForm(): void { $this->data=[]; }
    #[\Livewire\Attributes\Locked]
    public string $reviewStamp='';
    public function mount(int|string $record): void
    { parent::mount($record); $op=$this->getRecord(); $this->reviewStamp=StaffOperationReview::stamp($op,$op->domain); }
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
        $op=$this->getRecord()->refresh();
        $this->reviewStamp=StaffOperationReview::stamp($op,$op->domain);
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
    public function check_balanceAction(): Action
    {
        $op=$this->getRecord(); $account=RequestFacts::account($op)['account'];
        return Action::make('check_balance')->label('Check balance')->color('gray')
            ->visible(fn()=>(RequestFacts::notice($op)['link_label']??null)==='Check balance'&&RegistrarFacts::connected($account,RegistrarFacts::status(\App\Models\Server::findOrFail($op->server_id),$account)))
            ->action(fn()=>BalanceCheck::run((int)$op->server_id));
    }
    private function pageAction(string $name): Action
    { return array_values(array_filter($this->actions(),fn(Action $a)=>$a->getName()===$name))[0]; }
    private function actions(): array { return OperationActions::make($this->getRecord(),$this->reviewStamp); }
    public function infolist(Schema $schema): Schema
    {
        $op=$this->getRecord(); $grants=Staff::requireView(); $facts=RequestFacts::for($op,$grants); $components=[];
        if ($notice=RequestFacts::notice($op,$facts)) { $components[]=$this->notice($notice); }
        $account=RequestFacts::account($op);
        $setup=self::url(fn()=>RegistrarFacts::accountUrl((int)$op->server_id));
        $rows=[
            self::entry('Domain',$op->domain?->domain,url:$op->domain?self::url(fn()=>DomainResource::getUrl('view',['record'=>$op->domain_id])):null,cause:'Domain not found')->fontFamily('mono'),
            self::entry('Type',Labels::action($op->action)),
            self::entry('Registrar account',$account['value'],sub:$account['sub'],url:$setup,demo:$account['account']&&DemoData::is($account['account'])),
            self::entry('Created',self::time($op->created_at),cause:RequestFacts::NO_TIME),
        ];
        // Sent to registrar is hidden while the request has not been sent.
        if ($op->started_at || in_array($op->state,['running','pending','uncertain','succeeded'],true)) { $rows[]=self::entry('Sent to registrar',self::time($op->started_at),cause:RequestFacts::NO_TIME); }
        if (!($facts['unreadable']??false)) {
            $rows[]=self::entry('Invoice',$facts['invoice'],sub:$facts['invoice_sub'],url:$op->invoice_id?self::invoiceUrl((int)$op->invoice_id):null);
            if (array_key_exists('cost',$facts)) { $rows[]=self::entry('Cost',$facts['cost'],sub:$facts['cost_sub']); }
            if (array_key_exists('auth_code',$facts)) {
                $email=$op->domain?->service?->user?->email;
                $rows[]=self::entry('Auth code',$facts['auth_code'],sub:$facts['auth_code_sub'],fix:$email?Action::make('ask_customer')->label('Ask customer')->url('mailto:'.$email):null);
            }
            if (array_key_exists('refund',$facts)) {
                $fix=isset($facts['refund_fix'])?$this->getAction($facts['refund_fix'],false):null;
                $rows[]=self::entry('Credit',$facts['refund'],sub:$facts['refund_sub']??null);
                if ($fix?->isVisible()) { $rows[]=TextEntry::make('refund_fix')->hiddenLabel()->state(new HtmlString($fix->size('sm')->toHtml())); }
            }
        }
        $components[]=Section::make('Details')->schema($rows)->columnSpanFull()->extraAttributes(['class'=>'domains-facts']);
        $history=StaffReviewHistory::rows($op,$grants);
        $created=['time'=>self::time($op->created_at),'event'=>'Request created','sub'=>null,'by'=>'System'];
        // The reason shows as a muted line under its event.
        $event=fn(array $row)=>[...$row,'event'=>new HtmlString(e($row['event']).($row['sub']===null?'':'<div style="font-size:.8125rem;opacity:.7">'.e($row['sub']).'</div>'))];
        $components[]=Section::make('History')->columnSpanFull()->schema([$history===null
            ? TextEntry::make('history_unreadable')->hiddenLabel()->state(\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::UNREADABLE)->color('danger')
            : RepeatableEntry::make('history')->hiddenLabel()->state(array_map($event,[$created,...$history]))->table([TableColumn::make('Time'),TableColumn::make('Event'),TableColumn::make('By')])
                ->schema([MissingEntry::apply(TextEntry::make('time'),cause:RequestFacts::NO_TIME),TextEntry::make('event'),TextEntry::make('by')])]);
        return $schema->components($components);
    }
    private function notice(array $notice): Callout
    {
        // The cause's link comes first, then the action that retries: "Resume orders", then "Release hold".
        $buttons=[];
        $action=isset($notice['action'])?$this->getAction($notice['action'],false):null;
        $op=$this->getRecord();
        // Check balance is a button when the account can take it, else the way to the account.
        $balance=($notice['link_label']??null)==='Check balance' ? $this->getAction('check_balance',false) : null;
        $link=match($notice['link']??null) {
            'registrars'=>['Open account',self::url(fn()=>RegistrarFacts::accountUrl((int)$op->server_id))],
            'invoice'=>['Open invoice',$op->invoice_id?self::invoiceUrl((int)$op->invoice_id):null],
            'domain'=>['Open domain',$op->domain?self::url(fn()=>DomainResource::getUrl('view',['record'=>$op->domain_id])):null],
            default=>null};
        $acts=(bool)$action?->isVisible();
        if ($balance?->isVisible()) { $link=null; $buttons[]=$balance->color($acts?'gray':'primary')->size('sm')->toHtml(); }
        if ($link&&$link[1]) { $buttons[]=view('filament::components.button.index',['tag'=>'a','href'=>$link[1],'color'=>$acts?'gray':'primary','size'=>'sm','slot'=>new HtmlString(e($link[0]))])->render(); }
        if ($acts) { $buttons[]=$action->color('primary')->size('sm')->toHtml(); }
        return Callout::make($notice['title'])->description($notice['body']??null)->status($notice['tone'])->columnSpanFull()
            ->footer($buttons?new HtmlString('<div style="display:flex;flex-wrap:wrap;gap:.5rem">'.implode('',$buttons).'</div>'):null);
    }
    private static function entry(string $label, ?string $value, ?string $sub=null, ?string $url=null, bool $demo=false, ?Action $fix=null, ?string $cause=null): TextEntry
    {
        $entry=TextEntry::make('fact_'.md5($label))->label($label)->state($value)->inlineLabel()->extraAttributes(['style'=>'overflow-wrap:anywhere'])->url($url);
        if ($value!==null&&$sub!==null) { $entry->belowContent($sub); }
        if ($demo) { $entry->suffix(DemoData::tag(inline:true)); }
        // $sub sits under a present value; a Missing value shows $cause, else $sub as its cause.
        return MissingEntry::apply($entry,fix:$fix,cause:$value===null?($cause??$sub):null);
    }
    private static function time(?\DateTimeInterface $at): ?string
    { return $at ? \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y H:i').' UTC' : null; }
    private static function invoiceUrl(int $id): ?string
    {
        $invoice=\App\Models\Invoice::find($id);
        return $invoice&&\App\Admin\Resources\InvoiceResource::canEdit($invoice)?self::url(fn()=>\App\Admin\Resources\InvoiceResource::getUrl('edit',['record'=>$id])):null;
    }
    private static function url(\Closure $make): ?string { try { return $make(); } catch (\Throwable) { return null; } }
}
