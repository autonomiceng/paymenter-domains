<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Actions\{Action,BulkAction};
use Filament\Forms\Components\{Radio,Select,TextInput,Toggle};
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\{Schema,Components\Callout,Components\EmbeddedTable};
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\{Auth,Blade,Log};
use Illuminate\Support\{HtmlString,Str};
use Livewire\Attributes\{Locked,Url};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{HelpHint,SettingsActions,Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,CanonicalName};
use Paymenter\Extensions\Servers\Domains\Models\{Account,SellRow,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,CatalogueAcceptances,EndingChoices,EnomTestCallLimit,PriceFill,PriceList,PricingRule,PricingRules,SellRule,StaffSettingsReview as Review,StaffSettingsWrites};
/** What you sell: the rule and the TLD table. Reads only our own table. Only the fill reads from a registrar. */
class WhatYouSell extends Page implements HasTable
{
    use InteractsWithTable;
    private const KEEP='keep';
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-check-circle';
    protected static string|\UnitEnum|null $navigationGroup='Domains';
    protected static ?int $navigationSort=4;
    protected static ?string $title='What you sell';
    protected static ?string $slug='what-you-sell';
    protected string $view='domains::admin.what-you-sell';
    #[Url] public ?int $account=null;
    public string $newEnding='';
    #[Locked] public string $reviewStamp='';
    /** One request only, so a long list stays out of the page state. */
    private ?array $acceptedRows=null;
    private ?array $savedRule=null;
    private ?array $saleFacts=null;
    private ?bool $hasReader=null;
    /** One request's fee and default rule, and each row's numbers: a page reads them once, not per cell. */
    private ?array $fee=null;
    private ?array $defaultRule=null;
    private array $rowNumbers=[];
    private array $rights=[];
    public static function canAccess(): bool {return Staff::can();}
    public static function getNavigationLabel(): string {return 'What you sell';}
    public function mount(): void
    {
        Staff::requireView();$ids=$this->accounts();
        if(!in_array($this->account,array_keys($ids),true)){$this->account=array_key_first($ids);}
        $this->reload();
    }
    /** @return array<int,string> connected accounts by server, named as on Registrars */
    public function accounts(): array
    {
        $names=\App\Models\Server::where('extension','Domains')->pluck('name','id');
        return Account::orderBy('server_id')->pluck('server_id')->mapWithKeys(fn($id)=>[(int)$id=>(string)($names[$id]??'Registrar account #'.$id)])->all();
    }
    /** Each row asks, so a request checks a right once. The next request checks again, and a save rechecks under its locks. */
    private function may(string $permission): bool {return $this->rights[$permission]??=Staff::can($permission);}
    public function canEdit(): bool {return $this->may(Staff::POLICY)&&$this->may(Review::permission('acceptance'));}
    private function canSync(): bool {return $this->may(Staff::POLICY)&&$this->may(Review::permission('import'));}
    private function binding(): ?AccountBinding
    {
        try{return $this->account?CatalogueAcceptances::binding(Account::where('server_id',$this->account)->sole()):null;}catch(\Throwable){return null;}
    }
    /** Accounts with a price reader get their list from it; the rest type each TLD. */
    public function readable(): bool
    {
        if($this->hasReader!==null){return $this->hasReader;}
        try{return $this->hasReader=($binding=$this->binding())!==null&&app(PriceFill::class)->reader($binding)!==null;}catch(\Throwable){return $this->hasReader=false;}
    }
    private function reload(): void
    {
        $this->acceptedRows=$this->savedRule=$this->saleFacts=$this->fee=$this->defaultRule=null;$this->rowNumbers=[];
        if(($binding=$this->binding())!==null){try{SellRule::ensure($binding,CatalogueAcceptances::load($binding));}catch(\DomainException){}}
        $this->reviewStamp=$this->account?Review::stamp($this->account,null):'';
        $this->flushCachedTableRecords();
    }
    /** @return array<string,array> what the account sells now */
    private function accepted(): array
    {
        if($this->acceptedRows!==null){return $this->acceptedRows;}
        try{return $this->acceptedRows=($binding=$this->binding())?CatalogueAcceptances::load($binding)->endings:[];}catch(\DomainException){return $this->acceptedRows=[];}
    }
    private function rule(): array
    {
        if($this->savedRule!==null){return $this->savedRule;}
        $binding=$this->binding();
        try{$accepted=CatalogueAcceptances::load($binding);}catch(\DomainException){$accepted=null;}
        return $this->savedRule=SellRule::read($binding,$accepted);
    }
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
        $sell=[...($data['choice']===self::KEEP?[]:['choices'=>array_fill_keys($tlds,$data['choice'])]),...($terms===[]?[]:['terms'=>array_fill_keys($tlds,$terms)])];
        if($sell===[]){Notification::make()->title('No changes')->send();return;}
        $this->write($sell);
    }
    /** Every save goes through the guarded acceptance write. It never spends and never turns sales on. */
    private function write(array $sell): void
    {
        if(!$this->canEdit()){return;}
        $server=(int)$this->account;$actor=(int)Auth::id();
        try{app(StaffSettingsWrites::class)->run(new Review($actor,$server,null,'acceptance',Review::ticket($actor,$server,null,'acceptance',$this->reviewStamp),''),['sell'=>$sell]);}
        catch(\Throwable $error){
            if(!$error instanceof \DomainException&&!$error instanceof \InvalidArgumentException){Log::warning('Domains what you sell save failed.',['exception_class'=>$error::class]);}
            $stale=$error instanceof \DomainException&&$error->getMessage()==='Settings changed. Reload this page.';
            if($stale){$this->reload();}
            Notification::make()->danger()->title("Couldn't save")->body($stale?'Someone else changed this account. Check the list and try again.':SettingsActions::refusal($error,'acceptance',[],$server))->send();
            return;
        }
        $this->reload();
        Notification::make()->success()->title('Saved')->body('Nothing sells until its prices are in and sales are on in TLDs & pricing.')
            ->actions([Action::make('open_tlds')->label('Open TLDs & pricing')->link()->url(TldResource::getUrl('index'))])->send();
    }
    /** The same fill the schedule runs. Sends reads only, keeps the last good rows if one fails. */
    public function syncNow(): void
    {
        if(!$this->canSync()||!$this->readable()){return;}
        try{$count=app(PriceFill::class)->run(Account::where('server_id',$this->account)->sole());}
        catch(\Throwable $error){
            if(!$error instanceof \DomainException){Log::warning('Domains what you sell sync failed.',['exception_class'=>$error::class]);}
            Notification::make()->danger()->title("Couldn't get prices")
                ->body($error instanceof EnomTestCallLimit?SettingsActions::refusal($error,'import',[],(int)$this->account):'The registrar did not answer. Your last good prices are still here. Try again in a minute.')->send();
            return;
        }
        $this->reload();
        if($count===0){Notification::make()->warning()->title('Another price read is running')->body('Its prices will show here in a minute.')->send();return;}
        Notification::make()->success()->title('Prices updated')->body('Read '.$count.' TLDs.')->send();
    }
    /** @return array{label:string,tone:string,next?:string,open?:bool} */
    private function status(SellRow $row): array
    {
        $sold=$this->accepted()[$row->tld]??null;
        if($sold!==null&&$sold['register']===[]){return ['label'=>'Renewals only','tone'=>'gray'];}
        $waiting=['label'=>'Waiting for prices','tone'=>'warning'];
        return match(SellRule::decide($row,$this->rule())){
            'skipped'=>['label'=>'Not for sale','tone'=>'gray'],
            'limit'=>['label'=>'Over your limit','tone'=>'gray'],
            'waiting'=>$waiting,
            default=>$sold===null?($row->high_cost===null?$waiting:['label'=>'Not for sale','tone'=>'gray','next'=>'Use Edit selected to add it'])
                :(($sales=$this->sales($row->tld))===null?$waiting:['label'=>$sales['label'],'tone'=>$sales['tone']??'gray',...(($sales['reason']??null)===null?[]:['next'=>$sales['reason'].'. Open TLDs & pricing','open'=>true])]),
        };
    }
    /** Sale state per TLD for the rows on screen, read once. Null when no prices are saved for it yet. */
    private function sales(string $tld): ?array
    {
        $facts=$this->facts()[$tld]??null;
        return $facts===null||$facts['prices']===[]?null:$facts['sales'];
    }
    private function facts(): array
    {
        if($this->saleFacts!==null){return $this->saleFacts;}
        $binding=$this->binding();$names=collect($this->getTableRecords()->items())->pluck('tld')->all();
        $tlds=Tld::where(['server_id'=>$this->account,'account_fingerprint'=>$binding?->fingerprint])->whereIn('tld',$names)->get();$page=TldFacts::page($tlds);
        return $this->saleFacts=$tlds->mapWithKeys(fn(Tld $t)=>[$t->tld=>$page[$t->id]])->all();
    }
    /** @return array{0:?string,1:?string} price and profit for one year: the published price, else the default rule's. */
    private function numbers(SellRow $row): array
    {
        if($row->register_cost===null){return [null,null];}
        if(isset($this->rowNumbers[$row->tld])){return $this->rowNumbers[$row->tld];}
        try{
            $fee=$this->fee??=CardFee::read((int)$this->account);$cost=(string)$row->register_cost;
            $saved=collect($this->facts()[$row->tld]['prices']??[])->first(fn(array $p)=>$p['action']==='register'&&$p['term']===1&&$p['retail']!==null&&!in_array('unpublished',$p['flags'],true));
            $retail=$saved?Str::after($saved['retail'],'USD '):PricingRule::price($cost,$this->defaultRule??=(new PricingRules)->defaultRule((int)$this->account,false,$fee),'register');
            return $this->rowNumbers[$row->tld]=['USD '.$retail,'USD '.PriceList::profit(CardFee::profit($retail,$cost,$fee))];
        }catch(\Throwable){return [null,null];}
    }
    protected function getHeaderActions(): array
    {
        if($this->binding()===null){return [];}
        return [
            Action::make('sync_now')->label('Sync now')->color('gray')->visible(fn()=>$this->canSync()&&$this->readable())->action(fn()=>$this->syncNow()),
            Action::make('rule')->label('Default for new TLDs')->visible(fn()=>$this->canEdit())->modalSubmitActionLabel('Save')
                ->fillForm(fn()=>[...$this->rule(),'sell_new'=>$this->rule()['mode']==='only'?'no':'yes'])->schema(fn()=>[
                    Radio::make('sell_new')->label('Sell new TLDs')->required()->inline()->options(['yes'=>'Yes','no'=>'No'])
                        ->hintAction(HelpHint::make("For every TLD you have not set yourself, including ones the registrar adds later. Yes: they sell, unless the row is set to Don't sell. No: only rows set to Sell are sold. To change rows, tick them and use Edit selected.")),
                    TextInput::make('max_cost')->label('Cost limit')->numeric()->minValue(0)->prefix('USD')
                        ->hintAction(HelpHint::make('Skips a TLD when its register or renew cost, whichever is higher, is above this. Premium is per name, not per TLD: those names are checked at checkout.')),
                    TextInput::make('years')->label('Longest term')->integer()->required()->minValue(1)->maxValue(10)->suffix('years')->visible($this->binding()->registrarKey!=='namesilo'),
                    Toggle::make('transfers')->label('Transfers'),
                ])->action(function(array $data):void {
                    $this->write(['rule'=>['mode'=>$data['sell_new']==='yes'?'except':'only','max_cost'=>$data['max_cost']??null,'years'=>(int)($data['years']??1),'transfers'=>(bool)$data['transfers']]]);
                }),
        ];
    }
    public function getSubheading(): ?string
    {
        $filled=($binding=$this->binding())?PriceFill::last($binding)?->filled_at:null;
        return $filled===null?null:'Prices from '.self::time((int)$filled);
    }
    private static function time(int $at): string {return gmdate('M j, Y H:i',$at).' UTC';}
    public function content(Schema $schema): Schema
    {
        Staff::requireView();
        if(($binding=$this->binding())===null){
            return $schema->components([Callout::make('Connect a registrar account first')->warning()->columnSpanFull()
                ->footer(new HtmlString(Blade::render('<x-filament::button tag="a" :href="$url" color="gray" size="sm">Open Registrars</x-filament::button>',['url'=>RegistrarSetup::getUrl()])))]);
        }
        $components=[];$state=PriceFill::last($binding);
        if(PriceFill::stale($state,app(Clock::class)->now()->getTimestamp())){
            $components[]=Callout::make('Prices are out of date')->warning()->columnSpanFull()
                ->description(($state->filled_at===null?'We have not been able to read prices yet.':'The last good read was '.self::time((int)$state->filled_at).'. Those prices are still shown.').' We try again once a day. Use Sync now to see what stopped the read.');
        }
        return $schema->components([...$components,EmbeddedTable::make()]);
    }
    public function table(Table $table): Table
    {
        $costs=fn()=>$this->may(Staff::COSTS);
        return $table->query(function(){
                $binding=$this->binding();
                if($binding===null){return SellRow::query()->whereRaw('1=0');}
                // Hidden TLDs show only when already saved, for renewals.
                return SellRule::rows($binding)->where(fn($q)=>$q->whereNotIn('tld',EndingChoices::hiddenList())->orWhereIn('tld',array_keys($this->accepted())));
            })
            ->defaultSort('tld')->paginated([50,100])->defaultPaginationPageOption(50)->searchPlaceholder('Search TLDs')
            ->emptyStateHeading(fn()=>$this->getTableSearch()?'No TLDs match':'No prices yet')
            ->emptyStateDescription(fn()=>$this->getTableSearch()?null:'The first read runs by itself. To get prices now, use Sync now.')
            ->filters([
                Filter::make('country')->label('Country TLDs')->query(fn($query)=>$query->where(fn($q)=>$q->where('tld','like','__')->orWhere('tld','like','%.__'))),
                Filter::make('no_cost')->label('No cost yet')->query(fn($query)=>$query->whereNull('register_cost')),
            ])
            ->columns([
                TextColumn::make('tld')->label('TLD')->weight('medium')->fontFamily('mono')->sortable()->formatStateUsing(fn(string $state)=>'.'.$state)
                    ->searchable(query:fn($query,string $search)=>$query->where('tld','like','%'.strtolower(ltrim(trim($search),'.')).'%'))
                    ->description(fn(SellRow $record)=>$record->longest===null?null:'Up to '.$record->longest.' '.($record->longest===1?'year':'years')),
                TextColumn::make('register_cost')->label('1-year cost')->sortable()->visible($costs)->placeholder('No cost yet')->formatStateUsing(fn($state)=>TldFacts::money((string)$state,4))
                    ->tooltip(fn(SellRow $record)=>$record->read_at===null?null:'Prices from '.self::time((int)$record->read_at)),
                TextColumn::make('price')->label('Price')->visible($costs)->state(fn(SellRow $record)=>$this->numbers($record)[0]),
                TextColumn::make('profit')->label('Profit')->visible($costs)->state(fn(SellRow $record)=>$this->numbers($record)[1])
                    ->color(fn(?string $state)=>str_contains((string)$state,'-')?'danger':null),
                TextColumn::make('status')->label('Status')->badge()->state(fn(SellRow $record)=>$this->status($record)['label'])
                    ->color(fn(SellRow $record)=>$this->status($record)['tone'])
                    ->description(fn(SellRow $record)=>$this->status($record)['next']??null)
                    ->url(fn(SellRow $record)=>isset($this->status($record)['open'])?TldResource::getUrl('index'):null),
            ])
            ->recordActions([
                Action::make('edit_terms')->label('Edit terms')->link()->visible(fn()=>$this->canEdit())
                    ->fillForm(fn(SellRow $record)=>['longest'=>$record->longest??$this->rule()['years'],'transfer'=>$record->transfer??$this->rule()['transfers']])
                    ->schema(fn()=>[
                        TextInput::make('longest')->label('Longest term')->integer()->required()->minValue(1)->maxValue(10)->suffix('years')->visible($this->binding()?->registrarKey!=='namesilo'),
                        Toggle::make('transfer')->label('Transfers'),
                    ])->action(function(array $data,SellRow $record):void {
                        $this->write(['terms'=>[$record->tld=>['longest'=>(int)($data['longest']??1),'transfer'=>(bool)$data['transfer']]]]);
                    }),
            ])
            ->toolbarActions([
                // The table counts the selection in the browser, so the button reads it there. Select all works with filters.
                BulkAction::make('edit')->label(new HtmlString('Edit <span x-text="getSelectedRecordsCount()"></span> selected'))->visible(fn()=>$this->canEdit())->deselectRecordsAfterCompletion()
                    ->modalHeading(fn($records)=>'Edit '.$records->count().' '.($records->count()===1?'TLD':'TLDs'))->modalSubmitActionLabel('Save')->modalWidth('md')
                    ->schema(fn()=>[
                        Select::make('choice')->label('Sell')->required()->selectablePlaceholder(false)->default(self::KEEP)->options([self::KEEP=>'Leave as is','sell'=>'Sell','skip'=>"Don't sell"]),
                        Select::make('longest')->label('Longest term')->required()->selectablePlaceholder(false)->default(self::KEEP)->visible($this->binding()?->registrarKey!=='namesilo')
                            ->options([self::KEEP=>'Leave as is',...array_combine(range(1,10),array_map(fn(int $y)=>$y.' '.($y===1?'year':'years'),range(1,10)))]),
                        Select::make('transfer')->label('Transfers')->required()->selectablePlaceholder(false)->default(self::KEEP)->options([self::KEEP=>'Leave as is','on'=>'On','off'=>'Off']),
                    ])->action(fn(array $data,$records)=>$this->bulkEdit($data,$records->pluck('tld')->all())),
            ]);
    }
}
