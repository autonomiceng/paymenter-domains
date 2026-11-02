<?php
// Enom 444f31c TLD pricing layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages;
use Filament\Actions\{Action,ActionGroup};
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\{Schema,Components\Grid,Components\Section};
use Filament\Infolists\Components\TextEntry;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{MissingEntry,RegistrarFacts,Staff,TldFacts,SettingsActions};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,StaffSettingsReview as Review,StaffSettingsWrites};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
class ViewTld extends ViewRecord
{
    protected static string $resource=TldResource::class;
    public string $reviewStamp='';
    /** Page names for the shared settings actions. Edit prices is the main button and is on the prices section.
     * Default pricing is edited on the registrar account page, and saving prices publishes them. */
    private const LABELS=['seller'=>'Change registrar','override'=>'Edit prices','tld_settings'=>'Open in What you sell'];
    public function mount(int|string $record): void
    {parent::mount($record);$this->reviewStamp=\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::stamp((int)$this->getRecord()->server_id,(int)$this->getRecord()->id);}
    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);
        // Writers load their own models. Rebuild the page so the next change needs no reload.
        $t=$this->getRecord()->refresh();$this->reviewStamp=\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::stamp((int)$t->server_id,(int)$t->id);
        unset($this->cachedSchemas['infolist']);$this->cachedActions=[];$this->cachedHeaderActions=[];
        $this->cacheInteractsWithHeaderActions();$this->forceRender();
    }
    /** @return array<string,Action> */
    private function settings(): array
    {
        $out=[];
        foreach(SettingsActions::make((int)$this->getRecord()->server_id,(int)$this->getRecord()->id,$this->reviewStamp) as $action){
            $label=self::LABELS[$action->getName()]??null;
            $out[$action->getName()]=$action->getName()==='tld_settings'
                ?Action::make('tld_settings')->label('Open in What you sell')->url(\Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell::getUrl(['account'=>$this->getRecord()->server_id,'tld'=>$this->getRecord()->tld]))->visible(fn()=>Staff::can(Staff::POLICY)&&Staff::can(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::permission('acceptance')))
                :($label?$action->label($label)->modalHeading($label):$action);
        }
        return $out;
    }
    protected function getHeaderActions(): array
    {
        $actions=$this->settings();
        return [$actions['override']->color('primary'),ActionGroup::make([$actions['seller'],$actions['tld_settings'],$this->restoreSalesAction()])->label('More')->button()->color('gray')];
    }
    /** A deliberate staff stop cannot be undone automatically when sales terms or prices change. */
    public function restoreSalesAction(): Action
    {
        $t=$this->getRecord();$server=(int)$t->server_id;$id=(int)$t->id;
        $acceptedSales=static function()use($server,$t):array {
            $a=Account::where('server_id',$server)->sole();
            $accepted=CatalogueAcceptances::load(CatalogueAcceptances::binding($a))->endings[$t->tld]??[];
            return ['register'=>!empty($accepted['register']),'transfer'=>!empty($accepted['transfer'])];
        };
        return Action::make('restore_sales')->label('Restore sales')->requiresConfirmation()->modalHeading('Restore sales?')->modalSubmitActionLabel('Restore sales')
            ->modalDescription('Restores this account’s accepted registrations and transfers, and turns renewals on using saved prices. Existing term limits stay the same.')
            ->visible(function()use($t,$acceptedSales):bool {
                if(!Staff::can(Staff::POLICY)||!Staff::can(Review::permission('tld_settings'))){return false;}
                try{$accepted=$acceptedSales();}catch(\Throwable){$accepted=['register'=>false,'transfer'=>false];}
                return !$t->enabled||!$t->renew_enabled||(!$t->register_enabled&&$accepted['register'])||(!$t->transfer_enabled&&$accepted['transfer']);
            })
            ->schema([Hidden::make('review_key')->default(fn()=>Review::ticket((int)auth()->id(),$server,$id,'tld_settings',$this->reviewStamp))])
            ->action(function(array $data)use($server,$id,$acceptedSales):void {
                $input=[];
                try{
                    $t=$this->getRecord()->fresh();
                    $input=['min_years'=>$t->min_years,'max_years'=>$t->max_years,'enabled'=>true,'renew'=>true,...$acceptedSales(),'verified'=>true];
                    if(!$input['register']&&!$input['transfer']){throw new \DomainException('Acquisition acceptance unavailable.');}
                    app(StaffSettingsWrites::class)->run(new Review((int)auth()->id(),$server,$id,'tld_settings',(string)($data['review_key']??''),'Restored sales from the TLD page.'),$input);
                    Notification::make()->success()->title('Change recorded')->send();
                }catch(\Throwable $error){Notification::make()->danger()->title('Change refused')->body(SettingsActions::refusal($error,'tld_settings',$input,$server,$id))->send();}
            });
    }
    public function overrideAction(): Action {return $this->settings()['override'];}
    public function syncPricesAction(): Action {return SettingsActions::syncAll((int)$this->getRecord()->server_id)->name('syncPrices');}
    protected function fillForm(): void {$this->data=[];}
    public function getTitle(): string {Staff::requireView();return '.'.$this->getRecord()->tld;}
    public function getHeading(): HtmlString
    {
        $sales=TldFacts::salesState($this->getRecord());
        return new HtmlString('<span style="display:inline-flex;flex-wrap:wrap;align-items:center;gap:.5rem"><span class="font-mono">'.e($this->getTitle()).'</span>'
            .view('filament::components.badge',['color'=>$sales['tone'],'size'=>'lg','slot'=>new HtmlString(e($sales['label']))])->render().'</span>');
    }
    public function getSubheading(): ?string {return TldFacts::salesState($this->getRecord())['reason'];}
    public function getBreadcrumbs(): array {return [$this->getResourceUrl()=>'TLDs & pricing',$this->getTitle()];}
    private function button(?Action $action, string $label): ?HtmlString
    {return $action?->isVisible()?new HtmlString($action->label($label)->link()->size('sm')->toHtml()):null;}
    private static function entry(string $label, ?string $value, string|HtmlString|null $sub = null, ?string $color = null, ?string $url = null): TextEntry
    {
        $entry=TextEntry::make('fact_'.md5($label))->label($label)->state($value)->inlineLabel()->extraAttributes(['style'=>'overflow-wrap:anywhere'])->url($url);
        if($value!==null&&$sub!==null){$entry->belowContent($sub);}
        return MissingEntry::apply($entry,cause:$value===null&&is_string($sub)?$sub:null,color:$color);
    }
    public function infolist(Schema $schema): Schema
    {
        $grants=Staff::requireView();$t=$this->getRecord();$snapshot=TldFacts::snapshot($t,$grants);
        $sellerId=TldFacts::sellerId($t->tld);$seller=$sellerId===null?null:Account::where('server_id',$sellerId)->first();
        $sales=[self::entry('Registrar for new orders',$seller?(string)\App\Models\Server::whereKey($sellerId)->value('name'):null,$seller?'Existing domains stay with their registrar':'No registrar selected. Use Change registrar.',color:'primary',url:RegistrarFacts::accountUrl($sellerId))
                ->suffix($seller&&DemoData::is($seller)?DemoData::tag(inline:true):null),
            self::entry('Terms',TldFacts::terms($t))];
        // Saved costs stop authorizing new sales at the sales-policy age cap.
        $synced=TldFacts::lastSynced($snapshot['prices']);$failure=TldFacts::syncFailure((int)$t->server_id);
        $old=$synced!==null&&$synced->lte(now()->subDays(TldFacts::SYNC_WARN_DAYS));
        $rule='New sales stop when saved costs reach 48 hours old. Sync prices to resume.';
        $sync=[];
        if($failure){
            $cause=e($failure['cause'].($failure['at']?' · '.$failure['at']:''));$link=RegistrarFacts::accountLink((int)$t->server_id,'Open account');
            $sync[]=self::entry('Last price sync','Sync failed',new HtmlString($cause.($link?'<div>'.$link->toHtml().'</div>':'')),'danger');
        }
        $syncNow=$this->button($this->getAction('syncPrices',false),'Sync prices');
        $last=self::entry('Prices last synced',$synced?->format('M j, Y'),$synced===null?($syncNow?'Not synced yet.':'Not synced yet. Use Sync prices on the TLD list.'):new HtmlString(
            ($old?'<div style="color:rgb(217 119 6)">At least '.TldFacts::SYNC_WARN_DAYS.' days ago. Sync prices to get current costs.</div>':'').'<div>'.e($rule).'</div>'),$old?'warning':null);
        if(($old||$synced===null)&&$syncNow){$last->hint($syncNow);}
        $sync[]=$last;
        $override=$this->getAction('override',false);
        return $schema->components([
            Grid::make(['default'=>1,'lg'=>2])->columnSpanFull()->schema([
                Section::make('Sales')->schema($sales)->columnSpan(1),
                Section::make('Price sync')->schema($sync)->columnSpan(1)]),
            // A copy renders as a button here; the menu keeps its own item view.
            Section::make('Prices by registrar account')->afterHeader($override?->isVisible()?new HtmlString((clone $override)->button()->color('gray')->toHtml()):null)
                ->schema([TextEntry::make('prices')->label('Prices by registrar account')->hiddenLabel()->state(fn()=>TldFacts::accountsTable($t,$grants))->html()])->columnSpanFull(),
            Section::make('History')
                ->schema([TextEntry::make('history')->label('History')->hiddenLabel()->state(fn()=>TldFacts::historyTable(TldFacts::history((int)$t->server_id,(int)$t->id)))->html()])->columnSpanFull(),
        ]);
    }
}
