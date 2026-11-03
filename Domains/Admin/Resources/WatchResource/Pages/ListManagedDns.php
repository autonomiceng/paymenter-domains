<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages;
use Filament\Actions\Action;
use Filament\Forms\Components\{Hidden,Select};
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\{Table,Columns\TextColumn};
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{WatchResource,RoutingProfileResource};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\{ManagedDnsSetup,StaffManagedDnsSetup,StaffWatchWrites};
/** A filtered native table, rather than a new configuration style. */
class ListManagedDns extends ListRecords
{
    protected static string $resource=WatchResource::class;
    // These are managed domains, so the external-watch resource's columns and filters do not apply.
    protected function makeTable(): Table{return $this->makeBaseTable();}
    public function getTitle(): string{return 'DNS monitoring setup';}
    public function getSubheading(): ?string{return 'Set a default DNS template on each registrar account. These domains will pick it up automatically. Their expiry monitoring continues.';}
    public function getSubNavigation(): array{return WatchResource::tabs('dns-setup');}
    protected function getHeaderActions(): array
    {return [Action::make('templates')->label('DNS templates')->url(RoutingProfileResource::getUrl())];}
    protected function domains(): \Illuminate\Database\Eloquent\Builder{return ManagedDnsSetup::affected(time());}
    public function table(Table $table): Table
    {
        return $table->query($this->domains()->with('user'))->recordUrl(null)->columns([
            TextColumn::make('domain')->label('Domain')->searchable()->sortable()->wrap(),
            TextColumn::make('user.email')->label('Customer')->wrap(),
            TextColumn::make('comparison')->label('DNS comparison')->state(fn()=>$this instanceof ListDnsFailures?'Retrying automatically':'Not configured'),
        ])->recordActions([Action::make('accountTemplate')->label('Set account DNS template')
            ->visible(fn()=>!($this instanceof ListDnsFailures) && Staff::can(StaffWatchWrites::MANAGE) && Staff::canEditDefaultDns())
            ->url(fn(Domain $record)=>\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::getUrl(['account'=>$record->server_id])),
            Action::make('assignTemplate')->label('Assign template')->modalSubmitActionLabel('Assign template')
            ->visible(fn()=>!($this instanceof ListDnsFailures) && Staff::can(StaffWatchWrites::MANAGE) && WatchProfile::where('enabled',true)->get()->contains(fn($p)=>ManagedDnsSetup::usable($p)))
            ->modalDescription('The intended DNS could not be found automatically. Future checks compare against this template.')
            ->schema([Hidden::make('ticket')->default(fn(Domain $record)=>StaffManagedDnsSetup::ticket(Auth::user(),$record)),
                Select::make('profile')->label('DNS template')->options(fn()=>WatchProfile::where('enabled',true)->get()->filter(fn($p)=>ManagedDnsSetup::usable($p))->pluck('name','id')->all())->required(),
                Select::make('expected_dns')->label('Expected nameservers')->options(['platform'=>'Our nameservers','external'=>'Other'])->nullable()])
            ->action(function(Domain $record,array $data):void {
                try{(new StaffManagedDnsSetup())->assign(Auth::user(),(int)$record->id,(string)($data['ticket']??''),(int)($data['profile']??0),$data['expected_dns']??null);}
                catch(\DomainException|\Illuminate\Database\Eloquent\ModelNotFoundException){Notification::make()->warning()->title('This domain or template changed. Reload and choose an enabled DNS template.')->send();return;}
                Notification::make()->success()->title('DNS template assigned')->send();
            })])->defaultSort('domain')->paginated([25,50])
            ->emptyStateHeading($this instanceof ListDnsFailures?'No affected DNS checks':'DNS monitoring is set up')
            ->emptyStateDescription($this instanceof ListDnsFailures?'No eligible managed domains have failed DNS checks.':'No eligible domains need a DNS template.');
    }
}
