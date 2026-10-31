<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use App\Models\Server;
use Filament\Actions\{Action,ActionGroup};
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\{Schema,Components\Actions,Components\Flex,Components\Group,Components\Html,Components\Section};
use Filament\Support\Enums\FontFamily;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{AccountChecklist,BalanceCheck,HelpHint,MissingEntry,NameSiloAccountAction,OnOffSwitch,PslActions,RegistrarFacts,ReminderAction,SettingsActions,Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,CatalogueAcceptances,StaffSettingsReview};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** One registrar account (a Domains server): status, balance and setup. Reached from the Registrars list. */
class RegistrarAccount extends Page
{
    protected static ?string $slug='registrar-account';
    protected static bool $shouldRegisterNavigation=false;
    #[Locked] public int $account=0;
    public string $reviewStamp='';
    private ?array $status=null;
    public static function canAccess(): bool { return Staff::can(); }
    public static function getRoutePath(Panel $panel): string { return '/'.static::getSlug($panel).'/{account}'; }
    public function mount(int $account): void
    {
        Staff::requireView();
        abort_unless(Server::where('extension','Domains')->whereKey($account)->exists(),404);
        $this->account=$account;$this->reviewStamp=StaffSettingsReview::stamp($account,null);
    }
    private function server(): Server { return Server::where('extension','Domains')->findOrFail($this->account); }
    private function record(): ?Account { return Account::where('server_id',$this->account)->first(); }
    /** @return array{label:string,tone:string,reason:?string} */
    private function status(): array { return $this->status??=RegistrarFacts::status($this->server(),$this->record()); }
    public function getTitle(): string { Staff::requireView(); return (string)$this->server()->name; }
    public function getHeading(): HtmlString
    {
        $a=$this->record();$status=$this->status();
        return new HtmlString('<span style="display:inline-flex;flex-wrap:wrap;align-items:center;gap:.5rem"><span>'.e($this->getTitle()).'</span>'
            .($a&&DemoData::is($a)?DemoData::tag(inline:true)->toHtml():'')
            .view('filament::components.badge',['color'=>$status['tone'],'size'=>'lg','slot'=>new HtmlString(e($status['label']))])->render().'</span>'.RegistrarFacts::STYLE);
    }
    public function getSubheading(): ?string { return $this->status()['reason']; }
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
        $actions=[Action::make('check_balance')->label('Check balance')->visible(RegistrarFacts::connected($this->record(),$this->status()))->action(fn()=>$this->checkBalance())];
        if (!$this->record()) { return [...$actions,$setup('configure'),$setup('enroll_sandbox')]; }
        return [...$actions,ActionGroup::make([$setup('configure'),NameSiloAccountAction::make($id),
            Action::make('price_history')->label('View price history')->color('gray')->visible(fn()=>Staff::can(Staff::POLICY))->modalHeading('Price history')
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')->schema(fn()=>[Html::make(fn()=>TldFacts::historyTable(TldFacts::history($id)))])])
            ->label('More')->button()->color('gray')->icon('heroicon-m-chevron-down')->iconPosition('after')];
    }
    /** Rebuild the page after a System checks action changed what it shows. */
    private function resetContent(): void { unset($this->cachedSchemas['content']); }
    public function checkBalance(): void { BalanceCheck::run($this->account); }
    public function content(Schema $schema): Schema
    {
        Staff::requireView();$s=$this->server();$a=$this->record();
        $sections=[Section::make('Account')->schema($this->accountEntries($s,$a))->extraAttributes(['class'=>'domains-facts domains-account'])->columnSpanFull()];
        $system=AccountChecklist::system();
        if (Staff::can(PslActions::VIEW)) { $psl=PslActions::row(fn()=>$this->resetContent());$system[in_array($psl['tone'],['danger','warning'],true)?'failing':'passing'][]=$psl; }
        if ($a) {
            $runtime=AccountChecklist::runtime($a);$system[$runtime['value']===null?'failing':'passing'][]=$runtime;
            $alerts=AccountChecklist::alerts($a);$system[$alerts['tone']==='success'?'passing':'failing'][]=$alerts;
            $id=(int)$s->id;
            $renewals=$this->rows('renewal',AccountChecklist::renewals($id),false);
            $renewals[]=Actions::make([SettingsActions::setup($id,$this->reviewStamp,'renewal_billing'),SettingsActions::setup($id,$this->reviewStamp,'unpaid_hold'),ReminderAction::make($id)]);
            $sections[]=Group::make([
                Section::make('Setup checklist')->schema($this->rows('setup',AccountChecklist::setup($a,$this->status())))->columnSpanFull(),
                Section::make('Renewal invoices and reminders')->schema($renewals)->columnSpanFull(),
                Section::make('Default pricing')->schema($this->defaultPricing($id))->visible(fn()=>Staff::can(Staff::POLICY))->columnSpanFull(),
            ])->key('account_'.$id)->extraAttributes(['class'=>'domains-account'])->columnSpanFull();
        }
        $checks=$system['failing']?$this->rows('check',$system['failing'],false):[TextEntry::make('all_good')->hiddenLabel()->state('All good')->icon('heroicon-m-check-circle')->iconColor('success')];
        if ($system['passing']) { $checks[]=Section::make('Details')->schema($this->rows('pass',$system['passing'],false))->collapsible()->collapsed()->compact(); }
        $sections[]=Section::make('System checks')->schema($checks)->key('system_checks')->extraAttributes(['class'=>'domains-account'])->columnSpanFull();
        return $schema->components($sections);
    }
    /** The account's default pricing and the TLDs that use it. Saving publishes those TLDs. */
    private function defaultPricing(int $id): array
    {
        try { $rule=\Paymenter\Extensions\Servers\Domains\Services\PricingRule::describe((new \Paymenter\Extensions\Servers\Domains\Services\PricingRules)->defaultRule($id)); }
        catch (\Throwable) { $rule=null; }
        $tlds=\Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::bound(\Paymenter\Extensions\Servers\Domains\Models\Tld::query())->where('server_id',$id)->where('use_default_rule',true)->orderBy('tld')->pluck('tld');
        $used=$tlds->isEmpty()?'No TLDs use it yet.':'Used by '.$tlds->count().' '.($tlds->count()===1?'TLD':'TLDs').': '.$tlds->map(fn($t)=>'.'.$t)->implode(', ').'.';
        $entry=MissingEntry::apply(TextEntry::make('default_pricing')->label('Customer pays')->state($rule)->inlineLabel(),cause:$rule===null?"Saved default pricing can't be read. Use Edit default pricing.":null);
        if ($rule!==null) { $entry->belowContent($used); }
        try { $feeText=CardFee::describe(CardFee::read($id)).' per payment'; }
        catch (\Throwable) { $feeText=null; }
        $feeEntry=MissingEntry::apply(TextEntry::make('card_fee')->label('Card fee')->state($feeText)->inlineLabel(),cause:$feeText===null?"Saved card fee can't be read. Use Edit card fee.":null);
        if ($feeText!==null) { $feeEntry->hintAction(HelpHint::make('Used for estimated profit on the Price list, and by pricing that adds a percentage to cost and card fee.')); }
        return [Flex::make([$entry,Actions::make([SettingsActions::setup($id,$this->reviewStamp,'default_rule')])->grow(false)])->from('md'),
            Flex::make([$feeEntry,Actions::make([SettingsActions::setup($id,$this->reviewStamp,'card_fee')])->grow(false)])->from('md')];
    }
    /** Fact rows with an optional button each: Missing shows in danger with its cause. */
    private function rows(string $prefix,array $rows,bool $buttons=true): array
    {
        $out=[];
        foreach ($rows as $i=>$r) {
            $entry=MissingEntry::apply(TextEntry::make($prefix.'_'.$i)->label($r['label'])->state($r['value'])->inlineLabel(),cause:$r['value']===null?$r['cause']:null,color:$r['tone']==='success'?null:$r['tone']);
            if ($r['value']!==null&&$r['cause']!==null) { $entry->belowContent($r['tone']==='danger'||$r['tone']==='warning'?RegistrarFacts::tone($r['cause'],$r['tone']):$r['cause']); }
            if ($r['tone']==='success') { $entry->icon('heroicon-m-check-circle')->iconColor('success'); }
            if ($r['help']) { $entry->hintAction(HelpHint::make($r['help'])); }
            if (in_array($r['value'],['On','Off'],true)) { $out[]=$this->switchRow($prefix.'_'.$i,$r); }
            elseif (!empty($r['buttons'])) { $out[]=Flex::make([$entry,Actions::make($r['buttons'])->grow(false)])->from('md'); }
            elseif ($buttons&&$r['action']) { $out[]=Flex::make([$entry,Actions::make([SettingsActions::setup($this->account,$this->reviewStamp,$r['action'])])->grow(false)])->from('md'); }
            else { $out[]=$entry; }
        }
        return $out;
    }
    /** The switch comes first, so a section's switches line up. One that can't be used here shows its state only, and says why. */
    private function switchRow(string $key,array $r): Flex
    {
        $on=$r['value']==='On';$action=$r['buttons'][0]??($r['action']?SettingsActions::setup($this->account,$this->reviewStamp,$r['action']):null);
        $live=(bool)$action?->isVisible();$help=trim(($r['help']??'').($live?'':' '.$r['cause']));
        $entry=TextEntry::make($key)->label($r['label'])->state($r['cause'])->color('gray');
        if ($help!=='') { $entry->hintAction(HelpHint::make($help)); }
        return Flex::make([$live?Actions::make([OnOffSwitch::apply($action,$on,$r['label'])])->grow(false):Html::make(OnOffSwitch::html($on,$r['label']))->grow(false),$entry]);
    }
    private function accountEntries(Server $s, ?Account $a): array
    {
        $fact=static fn(string $key,string $label,?string $value,?string $cause=null)=>MissingEntry::apply(TextEntry::make($key)->label($label)->state($value)->inlineLabel(),cause:$cause);
        if (!$a) { return [$fact('registrar','Registrar',null,'Account not set up yet. Use Connect account.'),TextEntry::make('domains')->label('Domains')->state((string)\Paymenter\Extensions\Servers\Domains\Models\Domain::where('server_id',$s->id)->count())->inlineLabel()]; }
        $entries=[$fact('registrar','Registrar',TldFacts::registrar($a->registrar_key)),$fact('mode','Mode',RegistrarFacts::mode($a))];
        if ($a->registrar_key==='enom') { $entries[]=$fact('api_user','API user',RegistrarFacts::apiUser($a),'Identity not verified yet. Use Edit API credentials.')->fontFamily(FontFamily::Mono); }
        if ($a->registrar_key==='enom'&&$a->environment==='live') {
            try { $fingerprint=CatalogueAcceptances::binding($a)->fingerprint; } catch (\Throwable) { $fingerprint=null; }
            // Operators copy it into domains.enom_live.accounts (release-runbook.md).
            $entries[]=$fact('fingerprint','Live account ID',$fingerprint,"Saved credentials can't be read. Use Edit API credentials.")->fontFamily(FontFamily::Mono)->copyable();
        }
        if (RegistrarFacts::connected($a,$this->status())) {
            $b=RegistrarFacts::balance($a);
            $below=$b['value']===null?[e($b['cause'])]:array_filter([e('Checked '.$b['checked']),$b['low']?RegistrarFacts::warn('Low balance'):null]);
            $below[]=RegistrarFacts::fundsLink($b['funds']);
            // The fix for a Missing balance is the Check balance header action.
            $entries[]=MissingEntry::apply(TextEntry::make('balance')->label('Balance')->state($b['value'])->inlineLabel(),cause:$b['value']===null?$b['cause']:null)->belowContent(new HtmlString(implode(' · ',$below)));
            $entries[]=TextEntry::make('due')->label('Due in 30 days')->state($b['due'])->inlineLabel()->hintAction(HelpHint::make(RegistrarFacts::DUE_HELP))
                ->belowContent($b['without_cost']>0?RegistrarFacts::warn($b['without_cost'].' without a cost'):null);
        }
        $entries[]=TextEntry::make('domains')->label('Domains')->state((string)\Paymenter\Extensions\Servers\Domains\Models\Domain::where('server_id',$s->id)->count())->inlineLabel();
        if ($calls=RegistrarFacts::calls($a)) {
            $entry=MissingEntry::apply(TextEntry::make('calls')->label('API calls, last 24 hours')->state($calls['value'])->inlineLabel(),cause:$calls['value']===null?$calls['sub']:null,color:$calls['tone']);
            if ($calls['value']!==null&&$calls['sub']) { $entry->belowContent($calls['sub']); }
            if ($calls['help']) { $entry->hintAction(HelpHint::make($calls['help'])); }
            $entries[]=$entry;
        }
        $entries[]=$fact('last_sync','Last sync',RegistrarFacts::lastSync($s),Missing::fills('within a day'));
        return $entries;
    }
}
