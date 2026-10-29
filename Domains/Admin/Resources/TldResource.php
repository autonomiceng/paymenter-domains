<?php
// Adapted from Enom 444f31c Admin/Resources/TldResource; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\{Columns\TextColumn,Filters\SelectFilter,Table};
use Illuminate\Database\Eloquent\{Builder,Model};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SettingsActions,Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,PriceList,ViewTld};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
class TldResource extends Resource
{
    protected static ?string $model=Tld::class;
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-tag';
    protected static string|\UnitEnum|null $navigationGroup='Domains';
    protected static ?string $navigationLabel='TLDs & pricing';
    protected static ?int $navigationSort=3;
    protected static ?string $modelLabel='TLD';
    protected static ?string $pluralModelLabel='TLDs';
    protected static ?string $breadcrumb='TLDs & pricing';
    protected static ?string $slug='domain-tlds';
    public static function canViewAny(): bool {return Staff::can();}
    public static function canView(Model $record): bool {return Staff::can();}
    public static function canCreate(): bool {return false;}
    public static function canEdit(Model $record): bool {return false;}
    public static function canDelete(Model $record): bool {return false;}
    public static function canDeleteAny(): bool {return false;}
    public static function canGloballySearch(): bool {return false;}
    public static function getEloquentQuery(): Builder
    {Staff::requireView();return TldFacts::bound(parent::getEloquentQuery())->with('server:id,name');}
    public static function table(Table $table): Table
    {
        $grants=Staff::requireView();$pages=new \WeakMap();$demo=[];
        // Facts for the whole rendered page in one batch of reads, so extra rows add no queries.
        $row=static function(Tld $t,mixed $livewire)use($pages,$grants):array {
            $records=method_exists($livewire,'getTableRecords')?$livewire->getTableRecords():collect([$t]);
            $cached=$pages[$records]??[];
            if(!isset($cached[$t->id])){
                $page=collect(method_exists($records,'items')?$records->items():$records->all())->filter(fn($r)=>$r instanceof Tld);
                if(!$page->contains('id',$t->id)){$page=collect([$t]);}
                $cached+=TldFacts::page($page,$grants);$pages[$records]=$cached;
            }
            return $cached[$t->id];
        };
        $isDemo=static function(Tld $t)use(&$demo):bool {
            return $demo[$t->server_id]??=DemoData::is(Account::where('server_id',$t->server_id)->first());
        };
        $renewal=static fn(Tld $t,mixed $livewire)=>TldFacts::renewal($row($t,$livewire)['prices']);
        return $table->columns([
            TextColumn::make('tld')->label('TLD')->prefix('.')->fontFamily(FontFamily::Mono)->searchable()->sortable()->extraAttributes(['style'=>'word-break:normal;overflow-wrap:normal']),
            TextColumn::make('account')->label('Registrar account')->state(fn(Tld $record)=>$record->server->name)
                ->suffix(fn(Tld $record)=>$isDemo($record)?new \Illuminate\Support\HtmlString(' '.DemoData::tag(inline:true)->toHtml()):null)
                ->description(fn(Tld $record,$livewire)=>TldFacts::registrar($record->registrar_key).' · '.strtolower(TldFacts::pricing($record))
                    .($row($record,$livewire)['seller']===(int)$record->server_id?' · new orders':''))->wrap(),
            TextColumn::make('renewal')->label('Renewal price')->state(fn(Tld $record,$livewire)=>$renewal($record,$livewire)['retail']??Missing::LABEL)
                ->color(fn($state)=>$state===Missing::LABEL?Missing::COLOR:null)->icon(fn($state)=>$state===Missing::LABEL?Missing::ICON:null)
                ->description(fn($state)=>$state===Missing::LABEL?'Sync TLD prices needed':null)->extraAttributes(['style'=>'white-space:nowrap']),
            TextColumn::make('cost')->label('Cost')->visible($grants->costs)->state(fn(Tld $record,$livewire)=>$renewal($record,$livewire)['cost']??Missing::LABEL)
                ->color(fn($state,Tld $record,$livewire)=>$state===Missing::LABEL||TldFacts::costNote($renewal($record,$livewire))?Missing::COLOR:null)
                ->icon(fn($state)=>$state===Missing::LABEL?Missing::ICON:null)
                ->description(fn($state,Tld $record,$livewire)=>$state===Missing::LABEL?'No cost saved':TldFacts::costNote($renewal($record,$livewire)))->extraAttributes(['style'=>'white-space:nowrap']),
            TextColumn::make('holders')->label('Domains')->state(fn(Tld $record,$livewire)=>$row($record,$livewire)['holders']),
            TextColumn::make('status')->label('Status')->badge()->state(fn(Tld $record,$livewire)=>$row($record,$livewire)['sales']['label'])
                ->color(fn(Tld $record,$livewire)=>$row($record,$livewire)['sales']['tone'])->description(fn(Tld $record,$livewire)=>$row($record,$livewire)['sales']['reason']),
        ])->filters([SelectFilter::make('registrar_key')->label('Registrar')->options(['enom'=>'Enom','namesilo'=>'NameSilo']),
            SelectFilter::make('server_id')->label('Registrar account')->options(fn()=>\App\Models\Server::where('extension','Domains')->pluck('name','id')->all())])
            ->recordActions([SettingsActions::sync()->link()])
            ->recordUrl(fn($record)=>self::getUrl('view',['record'=>$record]))->defaultSort('tld')->paginated([25,50,100])
            ->emptyStateHeading('No TLDs yet')->emptyStateDescription(null);
    }
    /** Accounts whose last price sync failed, with the stored cause in words. */
    public static function syncFailures(): array
    {
        $out=[];
        foreach(Account::query()->whereIn('server_id',\App\Models\Server::where('extension','Domains')->where('type','server')->select('id'))->orderBy('server_id')->get() as $a){
            if($failure=TldFacts::syncFailure((int)$a->server_id)){$out[]=['account'=>(string)\App\Models\Server::whereKey($a->server_id)->value('name'),...$failure];}
        }
        return $out;
    }
    public static function getPages(): array
    {return ['index'=>ListTlds::route('/'),'price-list'=>PriceList::route('/price-list'),'view'=>ViewTld::route('/{record}')];}
}
