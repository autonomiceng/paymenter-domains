<?php
// Adapted from Enom 444f31c Admin/Pages/EnomSetup; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Pages;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\{Schema,Components\Callout,Components\EmbeddedTable};
use Filament\Support\Enums\IconPosition;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,EmptyTable,OneLine,RegistrarFacts,Sheet,Staff,StaffDomainStatus,StatusMark,TableColumns,TldFacts};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain};
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Services\ContactDetails;
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** Registrars: every registrar account (Domains server) with its status and balance. The slug keeps the old address. */
class RegistrarSetup extends Page implements HasTable
{
    use InteractsWithTable;
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-cog-6-tooth';
    protected static string|\UnitEnum|null $navigationGroup='Domains';
    protected static ?int $navigationSort=2;
    protected static ?string $title='Registrars';
    protected static ?string $slug='registrar-setup';
    protected static ?\Filament\Pages\Enums\SubNavigationPosition $subNavigationPosition=\Filament\Pages\Enums\SubNavigationPosition::Top;
    public function getSubNavigation(): array { return \Paymenter\Extensions\Servers\Domains\Admin\Support\StaffTabs::registrars('Accounts'); }
    /** Row facts per server for one render. */
    private array $rows=[];
    /** The menu item stays marked on an account and on Registrar requests. */
    public static function getNavigationItemActiveRoutePattern(): string|array
    { return [static::getRouteName(),RegistrarAccount::getRouteName(),RegistrarHistory::getRouteName(),RegistrarBalances::getRouteName(),ActivationStatus::getRouteName(),OperationResource::getRouteBaseName().'.*']; }
    public static function canAccess(): bool {return Staff::can();}
    public function mount(): void {Staff::requireView();}
    protected function getHeaderActions(): array
    {
        $resource=\App\Admin\Resources\ServerResource::class;$url=self::resourceUrl($resource,'create');
        if($url===null||!$resource::canCreate()){return [];}
        return [Action::make('add_account')->label('Add registrar account')->url($url)];
    }
    public function content(Schema $schema): Schema
    {
        Staff::requireView();$components=[];
        if ($missing=ContactDetails::missingRequiredProperties()) {
            $open=self::resourceUrl(\App\Admin\Resources\CustomPropertyResource::class,'index');
            $components[]=Callout::make("Customers can't enter ".implode(', ',$missing).' in Account. Add them as custom properties.')->warning()->columnSpanFull()
                ->footer($open?new HtmlString(Blade::render('<x-filament::button tag="a" :href="$url" color="gray" size="sm">Open custom properties</x-filament::button>',['url'=>$open])):null);
        }
        return $schema->components([...$components,EmbeddedTable::make()]);
    }
    public function table(Table $table): Table
    {
        $row=fn(Server $s)=>$this->row($s);
        $missing=static fn(TextColumn $column)=>$column->color(fn($state,Server $record)=>$state===Missing::LABEL?Missing::COLOR:null)->icon(fn($state)=>$state===Missing::LABEL?Missing::icon():null);
        $table=$table->query(self::accounts())->defaultSort('id')->paginated([25,50,100])->defaultPaginationPageOption(25)->heading('Accounts')->searchPlaceholder('Search account')
            ->recordUrl(fn(Server $record)=>RegistrarAccount::getUrl(['account'=>$record->id]));
        // One line per account. The reason, a missing balance's cause, a low balance and missing costs go in Note, each with its step.
        return TableColumns::choose(EmptyTable::apply($table,'No registrar accounts','Add one to start selling domains.','No accounts match'),[
                OneLine::apply(TextColumn::make('name')->label('Account')->searchable()->weight('medium')
                    ->suffix(fn(Server $record)=>$row($record)['demo']?DemoData::tag(inline:true):null)),
                TextColumn::make('registrar')->label('Registrar')->state(fn(Server $record)=>$row($record)['account']?TldFacts::registrar($row($record)['account']->registrar_key):null),
                TextColumn::make('mode')->label('Mode')->state(fn(Server $record)=>$row($record)['connected']?RegistrarFacts::mode($row($record)['account']):null),
                TextColumn::make('status')->label('Status')->badge()->state(fn(Server $record)=>$row($record)['status']['label'])
                    ->color(fn(Server $record)=>$row($record)['status']['tone'])->extraAttributes(fn(Server $record)=>StatusMark::attributes($row($record)['status']['tone'],'registrar-'.$record->id)),
                OneLine::apply(TextColumn::make('note')->label('Note')->color('gray')->state(fn(Server $record)=>$row($record)['note']['text'])
                    ->extraAttributes(fn(Server $record)=>StatusMark::note($row($record)['note']['act']))),
                $missing(TextColumn::make('balance')->label('Balance')->state(fn(Server $record)=>$row($record)['balance']?($row($record)['balance']['value']??Missing::LABEL):null)
                    ->alignEnd()->extraAttributes(['class'=>'domains-money'])),
                TextColumn::make('due')->label('Due in 30 days')->state(fn(Server $record)=>$row($record)['balance']['due']??null)->alignEnd()->extraAttributes(['class'=>'domains-money']),
                TextColumn::make('checked')->label('Balance checked')->state(fn(Server $record)=>$row($record)['balance']['checked']??null)->formatStateUsing(fn($state)=>Sheet::moment((string)$state)),
                TextColumn::make('domains')->label('Domains')->state(fn(Server $record)=>$row($record)['domains'])->alignEnd(),
            ],['checked','domains'])
            ->filters([
                SelectFilter::make('registrar')->label('Registrar')->options(fn()=>Account::query()->distinct()->pluck('registrar_key')->mapWithKeys(fn(string $key)=>[$key=>TldFacts::registrar($key)])->all())
                    ->query(fn(Builder $query,array $data)=>$query->when($data['value']??null,fn(Builder $q,string $key)=>$q->whereIn('id',Account::where('registrar_key',$key)->select('server_id')))),
                SelectFilter::make('mode')->label('Mode')->options(['live'=>'Live','test'=>'Test'])
                    ->query(fn(Builder $query,array $data)=>$query->when($data['value']??null,fn(Builder $q,string $mode)=>$q->whereIn('id',Account::where('environment',$mode==='live'?'=':'!=','live')->select('server_id')))),
                // Status is worked out per account, so this one reads the rows.
                SelectFilter::make('status')->label('Status')->options(fn()=>self::accounts()->get()->map(fn(Server $s)=>$this->row($s)['status']['label'])->unique()->sort()->mapWithKeys(fn(string $label)=>[$label=>$label])->all())
                    ->query(fn(Builder $query,array $data)=>$query->when($data['value']??null,fn(Builder $q,string $label)=>$q->whereIn('id',self::accounts()->get()->filter(fn(Server $s)=>$this->row($s)['status']['label']===$label)->modelKeys()))),
            ])
            ->recordActions([
                Action::make('add_funds')->label('Add funds')->link()->icon('heroicon-m-arrow-top-right-on-square')->iconPosition(IconPosition::After)
                    ->visible(fn(Server $record)=>$row($record)['balance']!==null)->url(fn(Server $record)=>$row($record)['balance']['funds']??null)->openUrlInNewTab()
                    ->extraAttributes(['rel'=>'noopener noreferrer']),
                Action::make('connect')->label('Connect')->link()->visible(fn(Server $record)=>$row($record)['account']===null)
                    ->url(fn(Server $record)=>RegistrarAccount::getUrl(['account'=>$record->id])),
            ]);
    }
    /** @return array{account:?Account,status:array,connected:bool,domains:int,demo:bool,balance:?array,note:array{text:?string,act:bool}} */
    private function row(Server $s): array
    {
        if (isset($this->rows[$s->id])) { return $this->rows[$s->id]; }
        $a=Account::where('server_id',$s->id)->first();$status=RegistrarFacts::status($s,$a);$connected=RegistrarFacts::connected($a,$status);
        $balance=$connected?RegistrarFacts::balance($a):null;
        return $this->rows[$s->id]=['account'=>$a,'status'=>$status,'connected'=>$connected,'domains'=>Domain::where('server_id',$s->id)->count(),
            'demo'=>$a!==null&&DemoData::is($a),'balance'=>$balance,'note'=>self::note($status['reason'],$balance)];
    }
    /** The account's Note: why it is in this state, then any job on its money, each with its step. @return array{text:?string,act:bool} */
    private static function note(?string $reason,?array $b): array
    {
        $jobs=$b===null?[]:array_filter([
            $b['value']===null?$b['cause']:null,
            $b['value']!==null&&$b['low']?'Low balance. Add funds.':null,
            $b['unreadable']>0?DomainFacts::UNREADABLE_RENEWAL:($b['without_cost']>0?$b['without_cost'].' '.($b['without_cost']===1?'renewal':'renewals').' without a cost. Use Sync prices on the TLD list.':null),
        ]);
        $parts=array_map(StaffDomainStatus::sentence(...),array_filter([$reason,...$jobs]));
        return ['text'=>$parts===[]?null:implode(' ',$parts),'act'=>$jobs!==[]];
    }
    private static function accounts(): Builder { return Server::query()->where('extension','Domains'); }
    private static function resourceUrl(string $resource,string $page): ?string
    { try { return class_exists($resource)?$resource::getUrl($page):null; } catch (\Throwable) { return null; } }
}
