<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\{Schema,Components\Callout};
use Filament\Support\Enums\Width;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{HelpHint,MissingEntry,Sheet,Staff,StaffTabs};
use Paymenter\Extensions\Servers\Domains\Dns\DohEndpoint;
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Services\DnsResolverSettings;
/** Domain monitoring > Settings: the DNS resolver monitoring checks use. Settings live in native encrypted settings. */
class MonitoringSettings extends Page
{
    protected static string $resource=WatchResource::class;
    protected static ?string $title='Domain monitoring';
    protected static ?string $breadcrumb='Settings';
    protected Width|string|null $maxContentWidth=Sheet::WIDTH;
    public static function canAccess(array $parameters=[]): bool{return Staff::can(PublicSuffixList::VIEW);}
    public function mount(): void{abort_unless(self::canAccess(),403);}
    public function getSubNavigation(): array{return StaffTabs::monitoring('Settings');}
    private static function requireManage(): void{abort_unless(self::canAccess()&&Staff::can(PublicSuffixList::MANAGE),403);}
    /** One resolver form: in the header once a resolver is saved, otherwise as the fix beside Missing. */
    private function edit(string $name): Action
    {
        $saved=fn()=>app(DnsResolverSettings::class)->configuration()[0];
        return Action::make($name)->label('Edit resolver')->modalHeading('Edit resolver')->modalSubmitActionLabel('Save')
            ->schema([
                TextInput::make('url')->label('Resolver URL (DNS over HTTPS)')->required()->maxLength(2048)->default(fn()=>$saved()?->url??DohEndpoint::URL)
                    ->helperText('Default: '.DohEndpoint::URL)->hintAction(HelpHint::make('Checked domain names are sent to this resolver.'))
                    ->rules([fn()=>function($attribute,$value,$fail): void{try{new DohEndpoint($value,DohEndpoint::IP);}catch(\Throwable){$fail('Use an HTTPS URL with a public hostname.');}}]),
                TextInput::make('ip')->label('Resolver IP address')->required()->maxLength(45)->default(fn()=>$saved()?->ip??DohEndpoint::IP)
                    ->helperText('Default: '.DohEndpoint::IP.". The resolver's public IP.")
                    ->rules([fn()=>function($attribute,$value,$fail): void{try{if(!is_string($value)){throw new \InvalidArgumentException;}new DohEndpoint(DohEndpoint::URL,$value);}catch(\InvalidArgumentException){$fail('Use a public IPv4 or IPv6 address.');}}]),
            ])->action(function(array $data): void{
                self::requireManage();app(DnsResolverSettings::class)->save($data['url'],$data['ip']);
                unset($this->cachedSchemas['content']);Notification::make()->title('Resolver saved')->success()->send();
            });
    }
    /** Missing: no saved resolver although the server could use one. */
    private static function missing(): bool{[$endpoint,,$forced]=app(DnsResolverSettings::class)->configuration();return $endpoint===null&&!$forced;}
    protected function getHeaderActions(): array
    {
        return [$this->edit('configure')->visible(fn()=>Staff::can(PublicSuffixList::MANAGE)&&app(DnsResolverSettings::class)->configuration()[0]!==null),
            Action::make('check')->label('Test resolver')->color('gray')->visible(fn()=>Staff::can(PublicSuffixList::MANAGE)&&app(DnsResolverSettings::class)->configuration()[0]!==null)
                ->action(function(): void{
                    self::requireManage();$result=app(DnsResolverSettings::class)->check();
                    $notice=Notification::make()->title($result['message']);($result['ok']?$notice->success():$notice->warning())->send();
                })];
    }
    public function content(Schema $schema): Schema
    {
        abort_unless(self::canAccess(),403);
        [$endpoint,$reason,$forced]=app(DnsResolverSettings::class)->configuration();
        $fix=Staff::can(PublicSuffixList::MANAGE)?$this->edit('edit')->link():null;
        $resolver=TextEntry::make('resolver')->label('Resolver')->state($endpoint?->host??($forced?'Server default':Missing::LABEL))
            ->hintAction(HelpHint::make("If the resolver fails, checks retry once with the server's resolver."));
        return $schema->components([
            ...($forced?[Callout::make($reason)->status('danger')->columnSpanFull()]:[]),
            Sheet::split([Sheet::section('Monitoring',[
                Sheet::row(TextEntry::make('registrar_refresh')->label('Registrar refresh')->state('Daily')->belowContent('Daily refresh reads domain facts. Use Sync now on a domain to read DNS, SRV, DS and glue records. Unsupported families remain unsupported.')),
                Sheet::row(TextEntry::make('public_checks')->label('Public DNS checks')->state('Daily')->belowContent('Record comparisons run daily. Nameserver delegation is checked more often; failed checks retry automatically.')),
                Sheet::row(TextEntry::make('read_failures')->label('Registrar read failures')->state('No separate alert switch')->belowContent('Read failures appear on the domain, with the failed family and resume action. Separate registrar-failure email alerts are not available.')),
                Sheet::row(TextEntry::make('evidence')->label('Evidence')->state('Separate checks')->belowContent(\Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies::EVIDENCE))]),Sheet::section('DNS resolver',[Sheet::row(MissingEntry::apply($resolver,$fix,$forced?null:$reason)),
                Sheet::row(TextEntry::make('bootstrap')->label('Resolver IP address')->state($endpoint?->ip))->visible($endpoint!==null)])],[]),
        ]);
    }
}
