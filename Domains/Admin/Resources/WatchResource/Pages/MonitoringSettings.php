<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\{Schema,Components\Callout,Components\Section};
use Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{MissingEntry,Staff};
use Paymenter\Extensions\Servers\Domains\Dns\DohEndpoint;
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Services\DnsResolverSettings;
/** Domain monitoring > Settings: the DNS resolver monitoring checks use. Settings live in native encrypted settings. */
class MonitoringSettings extends Page
{
    protected static string $resource=WatchResource::class;
    protected static ?string $title='Domain monitoring';
    protected static ?string $breadcrumb='Settings';
    public static function canAccess(array $parameters=[]): bool{return Staff::can(PublicSuffixList::VIEW);}
    public function mount(): void{abort_unless(self::canAccess(),403);}
    public function getSubNavigation(): array{return WatchResource::tabs('settings');}
    private static function requireManage(): void{abort_unless(self::canAccess()&&Staff::can(PublicSuffixList::MANAGE),403);}
    /** One resolver form: in the header once a resolver is saved, otherwise as the fix beside Missing. */
    private function edit(string $name): Action
    {
        $saved=fn()=>app(DnsResolverSettings::class)->configuration()[0];
        return Action::make($name)->label('Edit resolver')->modalHeading('Edit resolver')->modalSubmitActionLabel('Save')
            ->schema([
                TextInput::make('url')->label('Resolver URL (DNS over HTTPS)')->required()->maxLength(2048)->default(fn()=>$saved()?->url??DohEndpoint::URL)
                    ->helperText('Default: '.DohEndpoint::URL)->hintIcon('heroicon-m-question-mark-circle','Checked domain names are sent to this resolver.')
                    ->rules([fn()=>function($attribute,$value,$fail): void{try{new DohEndpoint($value,DohEndpoint::IP);}catch(\Throwable){$fail('Use an HTTPS URL with a public hostname.');}}]),
                TextInput::make('ip')->label('Bootstrap IP')->required()->maxLength(45)->default(fn()=>$saved()?->ip??DohEndpoint::IP)
                    ->helperText('Default: '.DohEndpoint::IP.". The resolver's public IP.")
                    ->rules([fn()=>function($attribute,$value,$fail): void{if(!is_string($value)||!filter_var($value,FILTER_VALIDATE_IP,FILTER_FLAG_GLOBAL_RANGE)){$fail('Use a public IPv4 or IPv6 address.');}}]),
            ])->action(function(array $data): void{
                self::requireManage();app(DnsResolverSettings::class)->save($data['url'],$data['ip']);
                unset($this->cachedSchemas['content']);Notification::make()->title('Resolver saved')->success()->send();
            });
    }
    /** Missing: no saved resolver although the server could use one. */
    private static function missing(): bool{[$endpoint,,$forced]=app(DnsResolverSettings::class)->configuration();return $endpoint===null&&!$forced;}
    protected function getHeaderActions(): array
    {
        return [$this->edit('configure')->visible(fn()=>Staff::can(PublicSuffixList::MANAGE)&&!self::missing()),
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
        $resolver=TextEntry::make('resolver')->label('Resolver')->inlineLabel()->state($endpoint?->host??($forced?'Server default':Missing::LABEL))
            ->hintIcon('heroicon-m-question-mark-circle',"If the resolver fails, checks retry once with the server's resolver.");
        return $schema->components([
            ...($forced?[Callout::make($reason)->status('danger')->columnSpanFull()]:[]),
            Section::make('DNS resolver')->schema([MissingEntry::apply($resolver,$fix,$forced?null:$reason),
                TextEntry::make('bootstrap')->label('Bootstrap IP')->inlineLabel()->state($endpoint?->ip)->visible($endpoint!==null)]),
        ]);
    }
}
