<?php
// Adapted from Enom 444f31c Admin/Resources/TldResource; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\{Columns\TextColumn,Filters\SelectFilter,Table};
use Illuminate\Database\Eloquent\{Builder,Model};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,ViewTld};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
class TldResource extends Resource
{
    protected static ?string $model=Tld::class;
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-tag';
    protected static string|\UnitEnum|null $navigationGroup='Domains';
    protected static ?string $navigationLabel='TLDs & pricing';
    protected static ?string $modelLabel='TLD';
    protected static ?string $pluralModelLabel='TLDs';
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
        $grants=Staff::requireView();$facts=[];$demo=[];$sellers=[];
        $row=static function(Tld $t)use(&$facts,$grants):array {
            return $facts[$t->id]??=TldFacts::snapshot($t,$grants);
        };
        $isDemo=static function(Tld $t)use(&$demo):bool {
            return $demo[$t->server_id]??=DemoData::is(Account::where('server_id',$t->server_id)->first());
        };
        $seller=static function(Tld $t)use(&$sellers):?int {
            return array_key_exists($t->tld,$sellers)?$sellers[$t->tld]:($sellers[$t->tld]=TldFacts::sellerId($t->tld));
        };
        $renewal=static fn(Tld $t)=>TldFacts::renewal($row($t)['prices']);
        return $table->columns([
            TextColumn::make('tld')->label('TLD')->prefix('.')->fontFamily(FontFamily::Mono)->searchable()->sortable()->extraAttributes(['style'=>'word-break:normal;overflow-wrap:normal']),
            TextColumn::make('account')->label('Registrar account')->state(fn(Tld $record)=>$record->server->name)
                ->suffix(fn(Tld $record)=>$isDemo($record)?new \Illuminate\Support\HtmlString(' '.DemoData::tag(inline:true)->toHtml()):null)
                ->description(fn(Tld $record)=>TldFacts::registrar($record->registrar_key).' · '.strtolower(TldFacts::pricing($record))
                    .($seller($record)===(int)$record->server_id?' · new orders':''))->wrap(),
            TextColumn::make('renewal')->label('Renewal price')->state(fn(Tld $record)=>$renewal($record)['retail']??Missing::LABEL)
                ->color(fn($state)=>$state===Missing::LABEL?Missing::COLOR:null)->icon(fn($state)=>$state===Missing::LABEL?Missing::ICON:null)
                ->description(fn($state)=>$state===Missing::LABEL?'Sync TLD prices needed':null)->extraAttributes(['style'=>'white-space:nowrap']),
            TextColumn::make('cost')->label('Cost')->visible($grants->costs)->state(fn(Tld $record)=>$renewal($record)['cost']??Missing::LABEL)
                ->color(fn($state,Tld $record)=>$state===Missing::LABEL||TldFacts::costNote($renewal($record))?Missing::COLOR:null)
                ->icon(fn($state)=>$state===Missing::LABEL?Missing::ICON:null)
                ->description(fn($state,Tld $record)=>$state===Missing::LABEL?'No cost saved':TldFacts::costNote($renewal($record)))->extraAttributes(['style'=>'white-space:nowrap']),
            TextColumn::make('holders')->label('Domains')->state(fn(Tld $record)=>TldFacts::holders($record)),
            TextColumn::make('status')->label('Status')->badge()->state(fn(Tld $record)=>$row($record)['sales']['label'])
                ->color(fn(Tld $record)=>$row($record)['sales']['tone'])->description(fn(Tld $record)=>$row($record)['sales']['reason']),
        ])->filters([SelectFilter::make('registrar_key')->label('Registrar')->options(['enom'=>'Enom','namesilo'=>'NameSilo']),
            SelectFilter::make('server_id')->label('Registrar account')->options(fn()=>\App\Models\Server::where('extension','Domains')->pluck('name','id')->all())])
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
    {return ['index'=>ListTlds::route('/'),'view'=>ViewTld::route('/{record}')];}
}
