<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\{Schema,Components\Callout,Components\EmbeddedTable};
use Filament\Tables\Columns\{TextColumn,ViewColumn};
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\{Auth,Blade,Log};
use Illuminate\Support\HtmlString;
use Livewire\Attributes\{Locked,Url};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,CanonicalName};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Price,Tld};
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,EndingChoices,EnomTestCallLimit,ReadDeferred,StaffSettingsReview as Review,StaffSettingsWrites};
/** What you sell: tick the TLDs and years an account sells. Nothing reads terms or prices until staff turn a TLD on. */
class WhatYouSell extends Page implements HasTable
{
    use InteractsWithTable;
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-check-circle';
    protected static string|\UnitEnum|null $navigationGroup='Domains';
    protected static ?int $navigationSort=4;
    protected static ?string $title='What you sell';
    protected static ?string $slug='what-you-sell';
    protected string $view='domains::admin.what-you-sell';
    #[Url] public ?int $account=null;
    public string $newEnding='';
    /** ending => {terms, transfer}: the ticks on screen, and what is saved now. */
    #[Locked] public array $chosen=[];
    #[Locked] public array $saved=[];
    /** Endings kept only so customers can renew. */
    #[Locked] public array $renewalsOnly=[];
    /** ending => years Enom offers, as read so far. */
    #[Locked] public array $years=[];
    /** Endings whose years could not be read. */
    #[Locked] public array $unknownYears=[];
    /** Fixed when the page loads, so ticking a row does not move it. */
    #[Locked] public array $endings=[];
    #[Locked] public ?string $listFailure=null;
    /** A save from before this screen left out how orders are sent. One save catches up. */
    #[Locked] public bool $ordersNeedSave=false;
    #[Locked] public string $reviewStamp='';
    /** Sale status per ending for one render. */
    private array $status=[];
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
    public function canEdit(): bool {return Staff::can(Staff::POLICY)&&Staff::can(Review::permission('acceptance'));}
    private function canSync(): bool {return Staff::can(Staff::POLICY)&&Staff::can(Review::permission('import'));}
    private function binding(): ?AccountBinding
    {
        try{return $this->account?CatalogueAcceptances::binding(Account::where('server_id',$this->account)->sole()):null;}catch(\Throwable){return null;}
    }
    public function readable(): bool {return ($binding=$this->binding())!==null&&EndingChoices::readable($binding);}
    public function isHidden(string $ending): bool {return EndingChoices::hidden($ending);}
    /** The first visit reads the list after the page shows, so a slow answer never holds the page. */
    public function shouldLoadList(): bool {return $this->canEdit()&&$this->readable()&&$this->listFailure===null&&app(EndingChoices::class)->saved($this->binding())===null;}
    public function loadList(): void
    {
        if($this->canEdit()&&$this->readable()&&app(EndingChoices::class)->saved($this->binding())===null){$this->readList();}
    }
    public function refreshList(): void {if($this->canEdit()&&$this->readable()){$this->readList();}}
    private function readList(): void
    {
        try{app(EndingChoices::class)->refresh($this->binding());$this->listFailure=null;}
        catch(\Throwable $error){
            if(!$error instanceof \DomainException&&!$error instanceof EnomTestCallLimit&&!$error instanceof ReadDeferred){Log::warning('Domains ending list read failed.',['exception_class'=>$error::class]);}
            $this->listFailure=$error instanceof EnomTestCallLimit?SettingsActions::refusal($error,'what_you_sell',[],(int)$this->account):'The registrar did not answer. Your saved TLDs are still here. Try Refresh list in a minute.';
        }
        $this->arrange();
    }
    /** Load saved state; ticks not yet saved survive a reload after someone else saved. */
    private function reload(bool $keepTicks=false): void
    {
        $binding=$this->binding();$this->reviewStamp=$this->account?Review::stamp($this->account,null):'';$saved=[];
        $this->ordersNeedSave=false;
        if($binding!==null){try{$accepted=CatalogueAcceptances::load($binding);$saved=$accepted->endings;$this->ordersNeedSave=$saved!==[]&&TldFacts::ordersNeedSave(Account::where('server_id',$this->account)->sole(),$accepted);}catch(\DomainException){}}
        $sold=array_filter($saved,static fn(array $row)=>$row['register']!==[]);
        $this->saved=array_map(static fn(array $row)=>['terms'=>$row['register'],'transfer'=>$row['transfer']!==[]],$sold);
        $this->renewalsOnly=array_keys(array_diff_key($saved,$sold));
        if(!$keepTicks){$this->chosen=$this->saved;}
        $this->arrange();
    }
    private function arrange(): void
    {
        $binding=$this->binding();$first=[...array_keys($this->saved),...$this->renewalsOnly];sort($first);
        $listed=array_filter($binding?(app(EndingChoices::class)->saved($binding)??[]):[],static fn(string $ending)=>!EndingChoices::hidden($ending));
        $rest=array_values(array_diff(array_unique([...$listed,...array_keys($this->chosen)]),$first));sort($rest);
        $this->endings=[...$first,...$rest];$this->status=[];$this->flushCachedTableRecords();
    }
    /** @return list<int> */
    public function yearsFor(string $ending): array
    {
        if(isset($this->years[$ending])){return $this->years[$ending];}
        if(($this->binding()?->registrarKey)==='namesilo'){return [1];}
        return $this->readable()?($this->saved[$ending]['terms']??[1]):range(1,10);
    }
    private function readYears(string $ending): void
    {
        if(isset($this->years[$ending])||!$this->readable()){return;}
        $years=app(EndingChoices::class)->years($this->binding(),$ending);
        if($years===null){$this->unknownYears[$ending]=true;return;}
        $this->years[$ending]=$years;unset($this->unknownYears[$ending]);
    }
    /** Saved endings show their ticked years without a read; this asks once for the rest. */
    public function moreYears(string $ending): void {if($this->canEdit()&&in_array($ending,$this->endings,true)){$this->readYears($ending);}}
    public function toggleEnding(string $ending): void
    {
        if(!$this->canEdit()||!in_array($ending,$this->endings,true)||EndingChoices::hidden($ending)){return;}
        if(isset($this->chosen[$ending])){unset($this->chosen[$ending]);return;}
        if(isset($this->saved[$ending])){$this->chosen[$ending]=$this->saved[$ending];return;}
        $this->readYears($ending);$this->chosen[$ending]=['terms'=>[$this->yearsFor($ending)[0]],'transfer'=>false];
    }
    public function toggleYear(string $ending,int $year): void
    {
        if(!$this->canEdit()||!isset($this->chosen[$ending])||!in_array($year,$this->yearsFor($ending),true)){return;}
        $terms=$this->chosen[$ending]['terms'];
        $terms=in_array($year,$terms,true)?array_values(array_diff($terms,[$year])):[...$terms,$year];sort($terms);
        if($terms===[]){unset($this->chosen[$ending]);return;}
        $this->chosen[$ending]['terms']=$terms;
    }
    public function toggleTransfer(string $ending): void
    {
        if($this->canEdit()&&isset($this->chosen[$ending])){$this->chosen[$ending]['transfer']=!$this->chosen[$ending]['transfer'];}
    }
    /** Accounts with nothing to list still need a way to name an ending. */
    public function addEnding(): void
    {
        if(!$this->canEdit()){return;}
        $ending=strtolower(ltrim(trim($this->newEnding),'.'));
        try{CanonicalName::parse('example.'.$ending,[$ending]);}
        catch(\Throwable){Notification::make()->danger()->title("That isn't a TLD")->body('Type it like .com or com.')->send();return;}
        if(EndingChoices::hidden($ending)){Notification::make()->danger()->title("Couldn't add it")->body(SettingsActions::refusal(new \DomainException('This TLD is hidden.'),'acceptance'))->send();return;}
        $this->newEnding='';
        if(!isset($this->chosen[$ending])){$this->chosen[$ending]=$this->saved[$ending]??['terms'=>[$this->yearsFor($ending)[0]],'transfer'=>false];}
        if(!in_array($ending,$this->endings,true)){$this->endings[]=$ending;$this->flushCachedTableRecords();}
    }
    /** @return array<string,?array> what a save would write; null turns an ending off */
    public function changes(): array
    {
        $changes=[];
        foreach($this->chosen as $ending=>$row){if(($this->saved[$ending]??null)!==$row){$changes[$ending]=$row;}}
        foreach($this->saved as $ending=>$row){if(!isset($this->chosen[$ending])){$changes[$ending]=null;}}
        ksort($changes);return $changes===[]&&$this->ordersNeedSave?$this->saved:$changes;
    }
    public function save(): void
    {
        if(!$this->canEdit()){return;}
        $changes=$this->changes();$server=(int)$this->account;$actor=(int)Auth::id();
        try{app(StaffSettingsWrites::class)->run(new Review($actor,$server,null,'acceptance',Review::ticket($actor,$server,null,'acceptance',$this->reviewStamp),''),['changes'=>$changes]);}
        catch(\Throwable $error){
            if(!$error instanceof \DomainException&&!$error instanceof \InvalidArgumentException){Log::warning('Domains what you sell save failed.',['exception_class'=>$error::class]);}
            $stale=$error instanceof \DomainException&&$error->getMessage()==='Settings changed. Reload this page.';
            if($stale){$this->reload(true);}
            Notification::make()->danger()->title("Couldn't save")->body($stale?'Someone else changed this account. Your ticks are still here. Check them and save again.':SettingsActions::refusal($error,'acceptance',[],$server))->send();
            return;
        }
        $added=array_keys(array_filter($changes,fn(?array $row,string $ending)=>$row!==null&&($this->saved[$ending]??null)!==$row,ARRAY_FILTER_USE_BOTH));$summary='';$failed=null;
        if($added!==[]&&$this->canSync()&&$this->readable()){
            try{$summary=SettingsActions::syncSummary($server,app(StaffSettingsWrites::class)->run(new Review($actor,$server,null,'import',Review::ticket($actor,$server,null,'import',Review::stamp($server,null)),''),['only'=>$added]));}
            catch(\Throwable $error){$failed=SettingsActions::refusal($error,'import',[],$server);}
        }
        $this->reload();
        $notification=Notification::make()->title('Saved')->actions([Action::make('open_tlds')->label('Open TLDs & pricing')->link()->url(TldResource::getUrl('index'))]);
        if($failed!==null){$notification->warning()->body("Couldn't get prices yet. ".$failed);}
        else{$notification->success()->body(trim($summary.' '.($added===[]?'':($summary===''?'Next, sync prices. ':'').'To start selling, turn on sales in TLDs & pricing.'))?:null);}
        $notification->send();
    }
    /** @return array{label:string,tone:string} */
    public function status(string $ending): array
    {
        if(isset($this->status[$ending])){return $this->status[$ending];}
        $status=match(true){
            in_array($ending,$this->renewalsOnly,true)&&!isset($this->chosen[$ending])=>['label'=>'Renewals only','tone'=>'gray'],
            !isset($this->saved[$ending])=>isset($this->chosen[$ending])?['label'=>'New','tone'=>'info']:['label'=>'','tone'=>'gray'],
            default=>$this->savedStatus($ending)};
        return $this->status[$ending]=$status;
    }
    private function savedStatus(string $ending): array
    {
        $tld=Tld::where(['server_id'=>$this->account,'tld'=>$ending])->first();
        if($tld===null||!Price::where(['server_id'=>$this->account,'tld'=>$ending])->exists()){return ['label'=>Missing::LABEL,'tone'=>Missing::COLOR,'next'=>TldFacts::NEEDS_SYNC];}
        return TldFacts::salesState($tld)['label']==='For sale'?['label'=>'For sale','tone'=>'success']:['label'=>'Not for sale','tone'=>'gray'];
    }
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh_list')->label('Refresh list')->color('gray')->visible(fn()=>$this->canEdit()&&$this->readable())->action(fn()=>$this->refreshList()),
            Action::make('save')->label(fn()=>($count=count($this->changes()))===0?'Save':'Save '.$count.' '.($count===1?'change':'changes'))
                ->visible(fn()=>$this->canEdit()&&$this->binding()!==null)->disabled(fn()=>$this->changes()===[])->action(fn()=>$this->save()),
        ];
    }
    public function content(Schema $schema): Schema
    {
        Staff::requireView();
        if($this->binding()===null){
            return $schema->components([Callout::make('Connect a registrar account first')->warning()->columnSpanFull()
                ->footer(new HtmlString(Blade::render('<x-filament::button tag="a" :href="$url" color="gray" size="sm">Open Registrars</x-filament::button>',['url'=>RegistrarSetup::getUrl()])))]);
        }
        $components=[];
        if($this->listFailure!==null){$components[]=Callout::make("Couldn't read the list")->description($this->listFailure)->warning()->columnSpanFull();}
        return $schema->components([...$components,EmbeddedTable::make()]);
    }
    public function table(Table $table): Table
    {
        $cell=static fn(string $name)=>ViewColumn::make($name)->view('domains::admin.sell-cell');
        return $table->searchable()->searchPlaceholder('Search TLDs')->paginated([50,100,'all'])->defaultPaginationPageOption(50)
            ->records(function(?string $search,int $page,int|string $recordsPerPage):LengthAwarePaginator{
                $needle=strtolower(ltrim(trim((string)$search),'.'));
                $rows=collect($this->endings)->filter(fn(string $ending)=>$needle===''||str_contains($ending,$needle))->mapWithKeys(fn(string $ending)=>[$ending=>['ending'=>$ending]]);
                $size=$recordsPerPage==='all'?max(1,$rows->count()):(int)$recordsPerPage;
                return new LengthAwarePaginator($rows->forPage($page,$size),$rows->count(),$size,$page);
            })
            ->emptyStateHeading(fn()=>$this->getTableSearch()?'No TLDs match':'No TLDs to show')->emptyStateDescription(null)
            ->columns([
                $cell('sell')->label('Sell'),
                TextColumn::make('ending')->label('TLD')->weight('medium')->fontFamily('mono')->formatStateUsing(fn(string $state)=>'.'.$state),
                $cell('years')->label('Years'),
                $cell('transfers')->label('Transfers'),
                TextColumn::make('status')->label('Status')->badge()->state(fn(array $record)=>$this->status($record['ending'])['label'])
                    ->color(fn(array $record)=>$this->status($record['ending'])['tone'])->icon(fn($state)=>$state===Missing::LABEL?Missing::ICON:null)
                    ->description(fn(array $record)=>$this->status($record['ending'])['next']??null)
                    ->url(fn(array $record)=>isset($this->status($record['ending'])['next'])?TldResource::getUrl('index'):null),
            ]);
    }
}
