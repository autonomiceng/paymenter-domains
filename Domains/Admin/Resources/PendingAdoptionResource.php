<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Filament\Tables\{Table,Columns\TextColumn,Filters\Filter};
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{WatchReadResource,Staff};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\PendingAdoptionResource\Pages\{ListPending,ViewPending};
class PendingAdoptionResource extends WatchReadResource
{
    protected static ?string $model=Domain::class;
    protected static ?string $navigationLabel='Adoption pending';
    protected static ?string $pluralModelLabel='Adoption pending';
    protected static ?string $slug='adoption-pending';
    public static function canViewAny(): bool{return Staff::can(Staff::ADOPT);}
    /** Imports waiting for review are Under review on the Domains list; old links land on that filter. */
    public static function shouldRegisterNavigation(): bool{return false;}
    public static function redirectUrl(): string{return DomainResource::getUrl('index',['filters'=>['status'=>['values'=>['under_review']]]]);}
    public static function getEloquentQuery(): Builder{return parent::getEloquentQuery()->where(fn($query)=>$query->where('provenance','adoption_pending')->orWhere('status','adoption_pending'));}
    public static function table(Table $table): Table
    {return $table->columns([TextColumn::make('domain')->label('Domain')->searchable()->wrap()->extraAttributes(['style'=>'overflow-wrap:anywhere;min-width:11rem;max-width:24rem']),TextColumn::make('user_id')->label('Customer ID'),TextColumn::make('service_id')->label('Service ID'),TextColumn::make('setup')->label('Registration')->state('Registration setup pending')->badge()])->filters([Filter::make('customer')->schema([TextInput::make('id')->label('Customer ID')->numeric()])->query(fn(Builder $query,array $data)=>ctype_digit((string)($data['id']??''))?$query->where('user_id',(int)$data['id']):$query)])->recordUrl(fn($record)=>static::getUrl('view',['record'=>$record]))->paginated([25,50])->defaultPaginationPageOption(25)->emptyStateHeading('No registrations pending adoption');}
    public static function getPages(): array{return ['index'=>ListPending::route('/'),'view'=>ViewPending::route('/{record}')];}
}
