<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Carbon\CarbonImmutable;
use Filament\Actions\{Action,BulkAction};
use Filament\Forms\Components\{Radio,Select,TextInput,Toggle};
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\{Schema,Components\Callout,Components\EmbeddedTable};
use Filament\Tables\Columns\{ColumnGroup,TextColumn};
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\{Filter,SelectFilter};
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\{Auth,Blade,Log};
use Illuminate\Support\HtmlString;
use Livewire\Attributes\{Locked,Url};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{HelpHint,SellTable,SettingsActions,Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,CanonicalName};
use Paymenter\Extensions\Servers\Domains\Models\{Account,SellRow};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,EndingChoices,PriceFill,PriceList,SellRule,StaffSettingsReview as Review,StaffSettingsWrites};
/** What you sell: the rule and the TLD table. Reads only our own table. Only the fill reads from a registrar. */
class WhatYouSell extends Page implements HasTable
{
    use InteractsWithTable;
    private const KEEP='keep';
    /** The account choice that reads every account. Saves belong to one account, so this view is read-only. */
    public const ALL=0;
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-check-circle';
    protected static string|\UnitEnum|null $navigationGroup='Domains';
    protected static ?int $navigationSort=4;
    protected static ?string $title='TLDs & pricing';
    protected static ?string $slug='what-you-sell';
    protected string $view='domains::admin.what-you-sell';
    #[Url] public ?int $account=null;
    #[Url] public ?string $tld=null;
    #[Url] public int $years=1;
    public string $newEnding='';
    #[Locked] public string $reviewStamp='';
    /** One request only, so a long list stays out of the page state. */
    private ?SellTable $reads=null;
    private ?array $accountNames=null;
    /** @var array<int,bool> */
    private array $readers=[];
    private array $rights=[];
    public static function canAccess(): bool {return Staff::can();}
    public static function getNavigationLabel(): string {return 'What you sell';}
    public function mount(): void
    {
        Staff::requireView();
        $this->tableSearch=$this->tld??'';
        $this->keepChoices();
        $this->reload();
    }
    public function updatedAccount(): void {$this->startList();}
    public function updatedYears(): void {$this->startList();}
    /** An address or a stale form can name anything, so a choice stays one this page offers. */
    private function keepChoices(): void
    {
        $ids=array_keys($this->accounts());$this->years=min(10,max(1,$this->years));
        if(!in_array($this->account,$ids,true)&&!($this->account===self::ALL&&count($ids)>1)){$this->account=$ids[0]??null;}
    }
    /** Another account or term is another list: the old page and ticks don't carry over. */
    private function startList(): void
    {
        $this->keepChoices();$this->getTable()->flushCachedVisibleColumns();$this->resetPage();$this->deselectAllTableRecords();$this->reload();
    }
    /** @return array<int,string> connected accounts by server, named as on Registrars */
    public function accounts(): array
    {
        if($this->accountNames!==null){return $this->accountNames;}
        $names=\App\Models\Server::where('extension','Domains')->pluck('name','id');
        return $this->accountNames=Account::orderBy('server_id')->pluck('server_id')->mapWithKeys(fn($id)=>[(int)$id=>(string)($names[$id]??'Registrar account #'.$id)])->all();
    }
    /** Each row asks, so a request checks a right once. The next request checks again, and a save rechecks under its locks. */
    private function may(string $permission): bool {return $this->rights[$permission]??=Staff::can($permission);}
    public function canSeeCosts(): bool {return $this->may(Staff::COSTS);}
    private function mayEdit(): bool {return $this->may(Staff::POLICY)&&$this->may(Review::permission('acceptance'));}
    public function canEdit(): bool {return $this->account!==self::ALL&&$this->mayEdit();}
    private function canSync(): bool {return $this->may(Staff::POLICY)&&$this->may(Review::permission('import'));}
    private function binding(): ?AccountBinding {return $this->account?$this->bindingOf($this->account):null;}
    private function bindingOf(int $server): ?AccountBinding
    {
        try{return CatalogueAcceptances::binding(Account::where('server_id',$server)->sole());}catch(\Throwable){return null;}
    }
    /** @return array<int,AccountBinding> the accounts this view reads */
    private function bindings(): array
    {
        $servers=$this->account===self::ALL?array_keys($this->accounts()):array_filter([$this->account]);
        return collect($servers)->mapWithKeys(fn(int $id)=>[$id=>$this->bindingOf($id)])->filter()->all();
    }
    /** Years are a cost view: staff without costs stay on the page they had. */
    public function years(): int {return $this->canSeeCosts()?$this->years:1;}
    /** Accounts with a price reader get their list from it; the rest type each TLD. */
    public function readable(): bool {return ($binding=$this->binding())!==null&&$this->hasReader($binding);}
    /** Only checks the account's saved setup. It never contacts the registrar. */
    private function hasReader(AccountBinding $binding): bool
    {
        if(($catalogue=$this->catalogue())!==null){return $catalogue->readable($binding);}
        if(isset($this->readers[$binding->serverId])){return $this->readers[$binding->serverId];}
        try{return $this->readers[$binding->serverId]=app(PriceFill::class)->reader($binding)!==null;}catch(\Throwable){return $this->readers[$binding->serverId]=false;}
    }
    /** Enom reads with its catalogue switched off are off on purpose, so only a reader that should exist asks for attention. */
    private function readerNeedsAttention(?AccountBinding $binding=null): bool
    {
        $binding??=$this->binding();
        return $binding!==null&&($binding->registrarKey!=='enom'||config($binding->environment->value==='live'?'domains.enom_live.catalogue':'domains.enom_catalogue.enabled')===true)&&!$this->hasReader($binding);
    }
    protected function catalogue(): ?\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue
    {return \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue::routed();}
    private function reads(): SellTable
    {
        return $this->reads??=new SellTable($this->bindings(),$this->years(),$this->canSeeCosts(),fn()=>$this->getTableRecords()->items(),$this->account===self::ALL,$this->catalogue());
    }
    private function reload(): void
    {
        $this->reads=null;$this->readers=[];
        // All accounts only reads: its table shows the same rows without saving them.
        if($this->catalogue()===null&&$this->account!==self::ALL&&($binding=$this->binding())!==null){try{SellRule::ensure($binding,CatalogueAcceptances::load($binding));}catch(\DomainException){}}
        $this->reviewStamp=$this->account?($this->catalogue()!==null?\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCommands::stamp($this->account):SellRule::stamp($this->account)):'';
        $this->flushCachedTableRecords();
    }
    private function rule(): array {return $this->reads()->rule((int)$this->account);}
    /** Accounts with nothing to list still need a way to name a TLD. */
    public function addEnding(): void
    {
        if(!$this->canEdit()){return;}
        $ending=strtolower(ltrim(trim($this->newEnding),'.'));
        try{CanonicalName::parse('example.'.$ending,[$ending]);}
        catch(\Throwable){Notification::make()->danger()->title("That isn't a TLD")->body('Type it like .com or com.')->send();return;}
        if(EndingChoices::hidden($ending)){Notification::make()->danger()->title("Couldn't add it")->body(SettingsActions::refusal(new \DomainException('This TLD is hidden.'),'acceptance'))->send();return;}
        $this->newEnding='';$this->write(['choices'=>[$ending=>'sell']]);
    }
    /** One save for the selection. Fields left as they are stay out of it, so each row keeps what it had. */
    private function bulkEdit(array $data,array $tlds): void
    {
        $terms=[...(($data['longest']??self::KEEP)===self::KEEP?[]:['longest'=>(int)$data['longest']]),...($data['transfer']===self::KEEP?[]:['transfer'=>$data['transfer']==='on'])];
        $sell=[...($data['choice']===self::KEEP?[]:['choices'=>array_fill_keys($tlds,$data['choice']==='rule'?null:$data['choice'])]),...($terms===[]?[]:['terms'=>array_fill_keys($tlds,$terms)])];
        if($sell===[]){Notification::make()->title('No changes')->send();return;}
        $this->write($sell);
    }
    /** Every save goes through the guarded acceptance write. It never spends and never turns sales on. */
    private function write(array $sell): void
    {
        if(!$this->canEdit()){return;}
        $server=(int)$this->account;$actor=(int)Auth::id();
        try{$summary=app(StaffSettingsWrites::class)->run(new Review($actor,$server,null,'acceptance',Review::ticket($actor,$server,null,'acceptance',$this->reviewStamp),''),['sell'=>$sell]);}
        catch(\Throwable $error){
            if(!$error instanceof \DomainException&&!$error instanceof \InvalidArgumentException){Log::warning('Domains what you sell save failed.',['exception_class'=>$error::class]);}
            $stale=$error instanceof \DomainException&&$error->getMessage()==='Settings changed. Reload this page.';
            if($stale){$this->reload();}
            Notification::make()->danger()->title("Couldn't save")->body($stale?'Someone else changed this account. Check the list and try again.':SettingsActions::refusal($error,'acceptance',[],$server))->send();
            return;
        }
        $this->reload();
        Notification::make()->success()->title('Saved')->body('Sales start as prices arrive, once orders are on.')
            ->actions([Action::make('open_account')->label('Open registrar account')->link()->url(RegistrarAccount::getUrl(['account'=>$server]))])->send();
        foreach($summary['failures']??[] as $ending=>$message){Notification::make()->danger()->title("Couldn't save")->body('.'.$ending.': '.SettingsActions::refusal(new \DomainException($message),'acceptance',[],$server))->send();}
    }
    /** The same fill the schedule runs. Sends reads only, keeps the last good rows if one fails. */
    public function syncNow(): void
    {
        if(!$this->canSync()||!$this->readable()){return;}
        try{if($this->catalogue()!==null){$actor=(int)Auth::id();$server=(int)$this->account;
            app(StaffSettingsWrites::class)->run(new Review($actor,$server,null,'import',Review::ticket($actor,$server,null,'import',$this->reviewStamp),''),[]);
        }else{\Paymenter\Extensions\Servers\Domains\Jobs\SyncPrices::enqueue((int)Auth::id(),(int)$this->account);}}
        catch(\Throwable $error){
            Notification::make()->danger()->title("Couldn't start sync")->body(\Paymenter\Extensions\Servers\Domains\Jobs\SyncPrices::isQueueRefusal($error)?$error->getMessage():'Check the registrar account under Registrars, then try again.')->send();return;
        }
        Notification::make()->success()->title('Price sync queued')->body('You can keep working. The result will appear in your notifications.')->send();
    }
    /** Filament puts Save first in a form dialog; these read Cancel, then Save. */
    private static function cancelFirst(): \Closure
    { return fn(Action $action)=>[$action->getModalCancelAction(),$action->getModalSubmitAction()]; }
    protected function getHeaderActions(): array
    {
        if($this->bindings()===[]){return [];}
        $all=fn()=>$this->account===self::ALL;$blocked=fn()=>$all()?['aria-disabled'=>'true']:[];
        return [
            Action::make('sync_now')->label('Sync prices')->color('gray')->visible(fn()=>$this->canSync()&&($all()||$this->readable()))->disabled($all)->extraAttributes($blocked)->action(fn()=>$this->syncNow()),
            Action::make('rule')->label('Default for new TLDs')->color('gray')->visible(fn()=>$this->mayEdit()&&($all()||$this->binding()!==null))->disabled($all)->extraAttributes($blocked)->modalSubmitActionLabel('Save')->modalFooterActions(self::cancelFirst())
                ->fillForm(fn()=>[...$this->rule(),'sell_new'=>$this->rule()['mode']==='only'?'no':'yes'])->schema(fn()=>[
                    Radio::make('sell_new')->label('Sell new TLDs')->required()->inline()->options(['yes'=>'Yes','no'=>'No'])
                        ->hintAction(HelpHint::make("For every TLD you have not set yourself, including ones the registrar adds later. Yes: they sell, unless the row is set to Don't sell. No: only rows set to Sell are sold. To change rows, tick them and use Edit selected.")),
                    TextInput::make('max_cost')->label('Cost limit')->numeric()->minValue(0)->prefix('USD')
                        ->hintAction(HelpHint::make('Skips a TLD when its register or renew cost, whichever is higher, is above this. Premium is per name, not per TLD: those names are checked at checkout.')),
                    TextInput::make('years')->label('Longest term')->integer()->required()->minValue(1)->maxValue(10)->suffix('years')->visible($this->binding()?->registrarKey!=='namesilo'),
                    Toggle::make('transfers')->label('Transfers'),
                ])->action(function(array $data):void {
                    $this->write(['rule'=>['mode'=>$data['sell_new']==='yes'?'except':'only','max_cost'=>$data['max_cost']??null,'years'=>(int)($data['years']??1),'transfers'=>(bool)$data['transfers']]]);
                }),
            Action::make('csv')->label('Download CSV')->color('gray')->visible(fn()=>$this->canSeeCosts())->action(fn()=>$this->downloadCsv()),
        ];
    }
    /** The list as the table holds it, on every page: same years, search, filters and focused TLD. Saved prices only, so an estimate row stays out. */
    public function downloadCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $years=$this->years();
        $csv=app(PriceList::class)->csv($years,$this->show()??'all',$this->account===self::ALL?null:$this->account,$this->held());
        return response()->streamDownload(static function()use($csv):void{echo $csv;},'domain-prices-'.$years.'-'.($years===1?'year':'years').'.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
    }
    /** The head line: what this account sells. All accounts has no one rule to sum, so it says why the buttons are off. */
    public function getSubheading(): Htmlable|string|null
    {
        if($this->account===self::ALL){return $this->mayEdit()||$this->canSync()?'Choose one account to sync or edit.':null;}
        if(($binding=$this->binding())===null){return null;}
        if(($catalogue=$this->catalogue())!==null){$snapshot=$catalogue->summary($binding->serverId,$this->years());}
        // For sale means the rule picks it and the account has accepted a registration this long. Renewals or transfers alone are not a sale.
        if(!isset($snapshot)){$accepted=$this->reads()->accepted($binding->serverId);$years=$this->years();
        $wanted=SellRule::rows($binding)->get()->filter(fn(SellRow $row)=>SellRule::decide($row,$this->rule())==='sell'&&in_array($years,$accepted[$row->tld]['register']??[],true));
        }
        $count=$snapshot['count']??$wanted->count();$highest=$snapshot['highest']??(isset($wanted)?$wanted->max('high_cost'):null);
        $summary=$count.' '.($count===1?'TLD':'TLDs').' for sale.';
        if($this->canSeeCosts()&&$highest!==null){$summary.=' Highest cost USD '.number_format((float)$highest,2).' a year.';}
        $filled=isset($snapshot)?$snapshot['filled']:PriceFill::last($binding)?->filled_at;
        return new HtmlString(HelpHint::beside(e($summary),'Prices are checked before a TLD goes on sale. Orders must be on under Registrars. On Live, use Activate after reviewing setup and spending. Missing prices, unsupported registry details and prices below cost keep a TLD off.')
            .($filled===null?'':' Prices from '.view('domains::partials.moment',['at'=>CarbonImmutable::createFromTimestamp((int)$filled)])->render().'.'));
    }
    /** The saved read the panel and the left-out warning show. Empty for staff without costs. */
    public function savedPrices(): array {return $this->reads()->saved();}
    public function explanations(): array {return $this->canSeeCosts()?$this->reads()->explanations($this->held()):[];}
    /** @return list<string> server:tld keys of every row the table holds, on every page */
    private function held(): array {return $this->getFilteredTableQuery()->get(['server_id','tld'])->map(fn(SellRow $row)=>SellTable::key($row))->all();}
    private function show(): ?string {return self::showFilter($this->getTableFilterState('show')['value']??null);}
    /** A saved Show value this page no longer offers reads as no Show filter, so the page still opens. */
    private static function showFilter(mixed $value): ?string {return is_string($value)&&$value!=='all'&&isset(PriceList::FILTERS[$value])?$value:null;}
    /** What the table's words mean, said once under the toolbar so cells stay plain numbers. */
    public function tableNote(): ?string
    {
        if(!$this->canSeeCosts()||$this->bindings()===[]){return null;}
        if(($years=$this->years())>1){return 'Saved prices in USD for '.$years.' years, never worked out from the 1-year price. Missing: no price is saved, hover over it for the next step. Off: not sold this long.'.($this->canEdit()?' To sell it longer, tick the row and use Edit selected.':'');}
        $more=array_intersect_key($this->getTable()->getVisibleColumns(),['renew_cost'=>1,'renew_price'=>1,'renew_profit'=>1,'transfer_cost'=>1,'transfer_price'=>1,'transfer_profit'=>1])!==[];
        return 'Prices in USD for 1 year. Gray prices are estimates: nothing is saved for that TLD yet.'.($more?' Off means that sale is turned off, or the TLD is not for sale.':'');
    }
    public function content(Schema $schema): Schema
    {
        Staff::requireView();
        if($this->bindings()===[]){
            return $schema->components([Callout::make('Connect a registrar account first')->warning()->columnSpanFull()
                ->footer(new HtmlString(Blade::render('<x-filament::button tag="a" :href="$url" color="gray" size="sm">Open Registrars</x-filament::button>',['url'=>RegistrarSetup::getUrl()])))]);
        }
        $components=[];$all=$this->account===self::ALL;$button=static fn(string $label,string $url)=>new HtmlString(Blade::render('<x-filament::button tag="a" :href="$url" color="gray" size="sm">{{ $label }}</x-filament::button>',['url'=>$url,'label'=>$label]));
        // All accounts warns for each account that needs it, by name, with a way to that account.
        foreach($all?$this->bindings():array_filter([(int)$this->account=>$this->binding()]) as $server=>$binding){
            $name=$this->accounts()[$server]??'Registrar account #'.$server;
            $progress=$this->catalogue()?->progress($binding->serverId);
            $state=$progress===null?PriceFill::last($binding):(object)['filled_at'=>$progress['succeeded_at']??null,'failed_at'=>$progress['failed_at']??null];$readerFailed=$this->readerNeedsAttention($binding);
            if($readerFailed){
                $components[]=Callout::make($all?'Check '.$name.' to sync prices':'Check the registrar account to sync prices')->warning()->columnSpanFull()
                    ->footer($button('Open registrar account',RegistrarAccount::getUrl(['account'=>$server])));
            }
            if($progress===null?PriceFill::stale($state,app(Clock::class)->now()->getTimestamp()):in_array($progress['state'],['retrying'],true)){
                $components[]=Callout::make($all?'Prices for '.$name.' are out of date':'Prices are out of date')->warning()->columnSpanFull()
                    ->description(($state->filled_at===null?'We have not been able to read prices yet.':'The last good read was '.SellTable::time((int)$state->filled_at).'. Those prices are still shown.').' We try again once a day. '
                        .($readerFailed?'Check the registrar account.':($all?'Show this account and use Sync prices to see what stopped the read.':'Use Sync prices to see what stopped the read.')))
                    ->footer($all&&!$readerFailed?$button('Show this account',self::getUrl(['account'=>$server])):null);
            }
        }
        return $schema->components([...$components,EmbeddedTable::make()]);
    }
    private function emptyHeading(): string
    {
        if(!$this->narrowed()){return 'No prices yet';}
        $only=$this->show()!==null&&!$this->hasTableSearch()&&!$this->switchedOn('country')&&!$this->switchedOn('no_cost');
        return $only&&$this->reads()->leftOutExplains($this->show())?'No prices to show. Some TLDs are left out; see above.':'No TLDs match';
    }
    private function focused(): bool {return $this->tld!==null&&$this->getTableSearch()===$this->tld;}
    private function switchedOn(string $filter): bool {return (bool)($this->getTableFilterState($filter)['isActive']??false);}
    /** A search or filter is why rows are gone, so the way back is to clear it, not to read prices again. */
    private function narrowed(): bool {return $this->hasTableSearch()||$this->show()!==null||$this->switchedOn('country')||$this->switchedOn('no_cost');}
    /** Cost, Price and Profit for one sale. Renew and Transfer start off so the page stays one screen wide. */
    private function moneyGroup(string $action): ColumnGroup
    {
        $cell=fn(SellRow $record,string $field)=>$this->reads()->cell($record,$action,$field);
        $column=fn(string $field,string|\Closure $label)=>TextColumn::make($action.'_'.$field)->label($label)->alignEnd()->extraHeaderAttributes(['class'=>'domains-sell-'.$action])->extraCellAttributes(['class'=>'domains-sell-'.$action])->visible(fn()=>$this->canSeeCosts())->toggleable(isToggledHiddenByDefault:$action!=='register')
            ->state(fn(SellRow $record)=>$cell($record,$field)['text'])->color(fn(SellRow $record)=>$cell($record,$field)['tone'])->tooltip(fn(SellRow $record)=>$cell($record,$field)['hint'])
            ->badge(fn(SellRow $record)=>$cell($record,$field)['mark'])->extraAttributes(fn(SellRow $record)=>$cell($record,$field)['mark']?['data-beco-tone'=>'err']:[]);
        return ColumnGroup::make(PriceList::ACTIONS[$action],[
            $column('cost',$action==='register'?fn()=>$this->years().'-year cost':'Cost')->sortable(fn()=>$action==='register'&&$this->years()===1),
            $column('price','Price'),
            $column('profit','Profit'),
        ])->alignCenter();
    }
    /** Bulk edit's fields. With one TLD they say what that TLD has now, since this is also how a single row is edited. */
    private function editFields(?SellRow $one): array
    {
        $rule=$one===null?null:$this->reads()->rule((int)$one->server_id);$longest=$one?->longest??$rule['years']??null;$transfer=$one?->transfer??$rule['transfers']??null;
        $keep=static fn(?string $now)=>[self::KEEP=>$now===null?'Leave as is':'Leave as is ('.$now.')'];
        return [
            Select::make('choice')->label('Sell')->required()->selectablePlaceholder(false)->default(self::KEEP)->options([self::KEEP=>'Leave as is','rule'=>'Use the default rule','sell'=>'Sell','skip'=>"Don't sell"])
                ->hintAction(HelpHint::make($one===null?'Use the default rule clears Sell or Don’t sell for these TLDs. Cost limits and each row’s terms still apply.':'Use the default rule clears Sell or Don’t sell for this TLD. Cost limits and its terms still apply.')),
            Select::make('longest')->label('Longest term')->required()->selectablePlaceholder(false)->default(self::KEEP)->visible($this->binding()?->registrarKey!=='namesilo')
                ->options($keep($longest===null?null:$longest.' '.($longest===1?'year':'years'))+array_combine(range(1,10),array_map(fn(int $y)=>$y.' '.($y===1?'year':'years'),range(1,10)))),
            Select::make('transfer')->label('Transfers')->required()->selectablePlaceholder(false)->default(self::KEEP)->options($keep($transfer===null?null:($transfer?'On':'Off'))+['on'=>'On','off'=>'Off']),
        ];
    }
    public function table(Table $table): Table
    {
        $costs=fn()=>$this->canSeeCosts();
        return $table->query(fn()=>$this->reads()->rows($this->focused()?$this->tld:null))
            ->defaultSort('tld')->paginated([25,50,100])->defaultPaginationPageOption(25)->searchPlaceholder('Search TLDs')
            ->emptyStateHeading(fn()=>$this->emptyHeading())
            ->emptyStateDescription(fn()=>$this->focused()
                ?'This TLD has no prices here yet. To load them, use Sync prices.'
                :($this->narrowed()?null:($this->readerNeedsAttention()?'Check the registrar account to read prices.':'The first read runs by itself. To get prices now, use Sync prices.')))
            ->emptyStateActions([Action::make('clear')->label(fn()=>$this->show()!==null||$this->switchedOn('country')||$this->switchedOn('no_cost')?'Clear filters':'Clear search')->color('gray')
                ->visible(fn()=>$this->narrowed()&&!$this->focused())->action(function():void {$this->removeTableFilters();$this->resetTableSearch();})])
            // Merged, so the panel skin's table class stays and this head matches every other table.
            ->extraAttributes(['class'=>'domains-sell-table'],merge:true)
            ->filters([
                Filter::make('country')->label('Country TLDs')->query(fn($query)=>$query->where(fn($q)=>$q->where('tld','like','__')->orWhere('tld','like','%.__'))),
                Filter::make('no_cost')->label('No cost yet')->query(fn($query)=>$query->whereNull('register_cost')),
                SelectFilter::make('show')->label('Show')->placeholder(PriceList::FILTERS['all'])->visible($costs)->options(array_diff_key(PriceList::FILTERS,['all'=>true]))
                    ->indicateUsing(fn(array $state)=>($value=self::showFilter($state['value']??null))===null?null:PriceList::FILTERS[$value])
                    ->query(fn($query,array $data)=>($value=self::showFilter($data['value']??null))===null?$query:$this->reads()->only($query,$value)),
            ])
            ->columns([
                TextColumn::make('tld')->label('TLD')->extraCellAttributes(['class'=>'domains-sell-pin'])->extraHeaderAttributes(['class'=>'domains-sell-pin'])->weight('medium')->fontFamily('mono')->sortable()->formatStateUsing(fn(string $state)=>'.'.$state)
                    ->url(fn(SellRow $record)=>($id=$this->reads()->tldId($record))===null?null:TldResource::getUrl('view',['record'=>$id]))->color(fn(SellRow $record)=>$this->reads()->tldId($record)===null?null:'primary')
                    ->searchable(query:fn($query,string $search)=>$query->where('tld','like','%'.strtolower(ltrim(trim($search),'.')).'%')),
                TextColumn::make('account_name')->label('Account')->visible(fn()=>$this->account===self::ALL)->state(fn(SellRow $record)=>$this->accounts()[(int)$record->server_id]??null),
                TextColumn::make('terms')->label('Terms')->toggleable(isToggledHiddenByDefault:true)->state(fn(SellRow $record)=>$this->reads()->terms($record)),
                TextColumn::make('pricing')->label('Pricing')->visible($costs)->toggleable(isToggledHiddenByDefault:true)->state(fn(SellRow $record)=>$this->reads()->pricing($record)),
                ...array_map(fn(string $action)=>$this->moneyGroup($action),array_keys(PriceList::ACTIONS)),
                TextColumn::make('status')->label('Status')->extraHeaderAttributes(['class'=>'domains-sell-status'])->extraCellAttributes(['class'=>'domains-sell-status'])->badge()->state(fn(SellRow $record)=>$this->reads()->status($record)['label'])
                    ->color(fn(SellRow $record)=>$this->reads()->status($record)['tone'])
                    ->description(fn(SellRow $record)=>$this->reads()->status($record)['next']??null)->tooltip(fn(SellRow $record)=>$this->canEdit()?$this->reads()->blocked($record):null)
                    ->url(fn(SellRow $record)=>isset($this->reads()->status($record)['open'])?TldResource::getUrl('index'):null),
            ])
            ->checkIfRecordIsSelectableUsing(fn(SellRow $record)=>$this->reads()->blocked($record)===null)
            ->toolbarActions([
                // The table counts the selection in the browser, so the button reads it there. Select all works with filters.
                BulkAction::make('edit')->label(new HtmlString('Edit <span x-text="getSelectedRecordsCount()"></span> selected'))->visible(fn()=>$this->canEdit())->deselectRecordsAfterCompletion()
                    ->modalHeading(fn($records)=>$records->count()===1?'Edit .'.$records->first()->tld:'Edit '.$records->count().' TLDs')->modalSubmitActionLabel('Save')->modalFooterActions(self::cancelFirst())->modalWidth('md')
                    ->schema(fn($records)=>$this->editFields($records->count()===1?$records->first():null))
                    ->action(fn(array $data,$records)=>$this->bulkEdit($data,$records->filter(fn(SellRow $record)=>$this->reads()->blocked($record)===null)->pluck('tld')->all())),
            ]);
    }
}
