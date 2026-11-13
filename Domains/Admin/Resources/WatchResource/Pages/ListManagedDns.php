<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages;
use Filament\Actions\Action;
use Filament\Forms\Components\{Hidden,Select};
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\{Builder,Model};
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{WatchResource,RoutingProfileResource};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,StaffTabs};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head,Row,Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{Column,RecordListPage,Section};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Presentation\{Reader,State};
use Paymenter\Extensions\Servers\Domains\Services\{ManagedDnsSetup,StaffManagedDnsSetup,StaffWatchWrites};
/** Domains > DNS monitoring setup, a List page: the managed domains that need a DNS template. These are managed
 * domains, so the external-watch resource's columns and filters do not apply. */
class ListManagedDns extends RecordListPage
{
    protected static string $resource=WatchResource::class;
    public function getSubNavigation(): array{return StaffTabs::domains('DNS monitoring setup');}
    public function getBreadcrumbs(): array{return [];}
    protected function head(): Head{return Head::make('Domains',Reader::Staff);}
    protected function actions(): array
    {return [Action::make('templates')->label('Default nameservers')->color('gray')->url(RoutingProfileResource::getUrl())];}
    protected function domains(): Builder{return ManagedDnsSetup::affected(time());}
    protected function query(): Builder{return $this->domains()->with('user');}
    protected function rowUrl(Model $record): ?string{return null;}
    /** What the DNS comparison column says for a row of this list. */
    protected function comparison(): State{return State::NotSet;}
    protected function notices(): array
    {
        if(!$this->domains()->exists()){return [];}
        return [Section::make('DNS monitoring',[Row::notice('dns-setup',Value::state(State::NotSet,Reader::Staff,
            'Set the default nameservers in Domains > DNS templates. These domains inherit them automatically. Their expiry monitoring continues.'))])];
    }
    protected function columns(): array
    {
        return [
            Column::domain('domain','Domain',false)->searchable()->sortable(),
            Column::text('user.email','Customer')->short(),
            Column::value('comparison','DNS comparison',fn(Domain $d): Value=>Value::state($this->comparison(),Reader::Staff,null,'dns-'.$d->id)),
        ];
    }
    protected function defaultSort(): ?array{return ['domain','asc'];}
    protected function searchLabel(): ?string{return 'Search domain';}
    protected function emptyLine(): array{return ['DNS monitoring is set up','No eligible domains need a DNS template.','No domains match'];}
    protected function recordActions(): array
    {
        return [Action::make('assignTemplate')->label('Assign template')->modalSubmitActionLabel('Assign template')
            ->visible(fn()=>$this->canAssign() && Staff::can(StaffWatchWrites::MANAGE) && WatchProfile::where('enabled',true)->get()->contains(fn($p)=>ManagedDnsSetup::usable($p)))
            ->modalDescription('The intended DNS could not be found automatically. Future checks compare against this template.')
            ->schema([Hidden::make('ticket')->default(fn(Domain $record)=>StaffManagedDnsSetup::ticket(Auth::user(),$record)),
                Select::make('profile')->label('DNS template')->options(fn()=>WatchProfile::where('enabled',true)->get()->filter(fn($p)=>ManagedDnsSetup::usable($p))->pluck('name','id')->all())->required(),
                Select::make('expected_dns')->label('Expected nameservers')->options(['platform'=>'Our nameservers','external'=>'Other'])->nullable()])
            ->action(function(Domain $record,array $data):void {
                try{(new StaffManagedDnsSetup())->assign(Auth::user(),(int)$record->id,(string)($data['ticket']??''),(int)($data['profile']??0),$data['expected_dns']??null);}
                catch(\DomainException|\Illuminate\Database\Eloquent\ModelNotFoundException){Notification::make()->warning()->title('This domain or template changed. Reload and choose an enabled DNS template.')->send();return;}
                Notification::make()->success()->title('DNS template assigned')->send();
            })];
    }
    /** Failed checks retry on their own, so only the setup list offers a template. */
    protected function canAssign(): bool{return true;}
}
