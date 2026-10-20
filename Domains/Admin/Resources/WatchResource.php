<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Tables\{Table,Columns\TextColumn,Filters\Filter,Filters\SelectFilter};
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{WatchReadResource,WatchQueries};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,ViewWatch};
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProductMap};
class WatchResource extends WatchReadResource
{
    protected static ?string $model=WatchName::class;
    protected static ?string $navigationLabel='Domain watch';
    protected static ?string $modelLabel='domain watch';
    protected static ?string $pluralModelLabel='Domain watch';
    protected static ?string $slug='domain-watch';
    public static function getEloquentQuery(): Builder{return parent::getEloquentQuery()->with(['delegation','points','registry','work']);}
    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('canonical_name')->label('Domain')->searchable()->sortable()->wrap()->description(fn($record)=>'Customer #'.$record->user_id)->extraAttributes(['style'=>'overflow-wrap:anywhere;min-width:11rem;max-width:24rem']),
            TextColumn::make('state')->badge()->formatStateUsing(fn($state)=>match($state){'active'=>'Checking','paused'=>'Checks paused','retired'=>'Watch ended','linked'=>'Linked to registration',default=>'Needs review'}),
            TextColumn::make('registration')->label('Registration')->formatStateUsing(fn($state)=>match($state){'external'=>'External Registration','platform'=>'Platform Registration',default=>'Unknown'})->placeholder('Unknown'),
            TextColumn::make('delegation.dns')->label('Last observed DNS')->formatStateUsing(fn($state)=>WatchQueries::observed($state))->placeholder('Unknown'),
            TextColumn::make('points.partial')->label('Pointer check')->state(fn($record)=>!$record->points?'Not checked':($record->points->partial?'Partial':'Recorded'))->badge(),
            TextColumn::make('work_due')->label('Next attempt')->state(fn($record)=>$record->work->min('due_at')?\Carbon\CarbonImmutable::createFromTimestampUTC($record->work->min('due_at')):null)->dateTime('M j, Y H:i')->placeholder('Not scheduled'),
        ])->filters([
            Filter::make('customer')->schema([TextInput::make('id')->label('Customer ID')->numeric()])->query(fn(Builder $query,array $data)=>ctype_digit((string)($data['id']??''))?$query->where('user_id',(int)$data['id']):$query),
            SelectFilter::make('state')->options(['active'=>'Checking','paused'=>'Paused','retired'=>'Ended','linked'=>'Linked']),
            SelectFilter::make('registration')->options(['external'=>'External','platform'=>'Platform']),
            SelectFilter::make('expected_dns')->label('Expected DNS')->options(['platform'=>'Platform','external'=>'External']),
            SelectFilter::make('observed')->label('Last observed DNS')->options(['platform'=>'Platform','external'=>'External'])->query(fn(Builder $query,array $data)=>isset($data['value'])?$query->whereHas('delegation',fn($query)=>$query->where('dns',$data['value'])):$query),
            SelectFilter::make('product')->label('Source product')->options(fn()=>WatchProductMap::with('product')->get()->mapWithKeys(fn($m)=>[$m->product_id=>$m->product?->name??'Product #'.$m->product_id])->all())->query(fn(Builder $query,array $data)=>isset($data['value'])?$query->whereHas('sources',fn($query)=>$query->whereIn('map_id',WatchProductMap::where('product_id',$data['value'])->select('id'))):$query),
            Filter::make('stale')->label('Missing, partial or out-of-date checks')->query(WatchQueries::stale(...)),
            Filter::make('drift')->label('Expected platform / observed external')->query(fn(Builder $query)=>$query->where('expected_dns','platform')->whereHas('delegation',fn($query)=>$query->where('dns','external'))),
            SelectFilter::make('expiry')->label('Recorded expiry · first 1,000 names within')->options(['7'=>'7 days','30'=>'30 days','90'=>'90 days'])->query(fn(Builder $query,array $data)=>WatchQueries::expiry($query,(int)($data['value']??0))),
        ])->recordUrl(fn($record)=>static::getUrl('view',['record'=>$record]))->defaultSort('canonical_name')->paginated([25,50])->defaultPaginationPageOption(25)->emptyStateHeading('No matching domains');
    }
    public static function getPages(): array{return ['index'=>ListWatches::route('/'),'view'=>ViewWatch::route('/{record}')];}
}
