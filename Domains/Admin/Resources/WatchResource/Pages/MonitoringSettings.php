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
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DefaultNameservers,HelpHint,MissingEntry,Sheet,Staff,StaffTabs,WatchActions};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{SettingsRow,Value};
use Paymenter\Extensions\Servers\Domains\Presentation\{Reader,State};
use Paymenter\Extensions\Servers\Domains\Services\{StaffWatchWrites,WatchProducts,WatchWork};
use Paymenter\Extensions\Servers\Domains\Dns\DohEndpoint;
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Services\DnsResolverSettings;
/** Domains > Monitoring settings: which hosting products are monitored (Ilya, decision 2) and the DNS resolver monitoring
 * checks use. Settings live in native encrypted settings.
 * The default nameservers are shown here and changed in Domains > DNS templates. */
class MonitoringSettings extends Page
{
    protected static string $resource=WatchResource::class;
    protected static ?string $title='Monitoring settings';
    protected static ?string $breadcrumb='Monitoring settings';
    protected Width|string|null $maxContentWidth=Sheet::WIDTH;
    public static function canAccess(array $parameters=[]): bool{return Staff::can(PublicSuffixList::VIEW);}
    public function mount(): void{abort_unless(self::canAccess(),403);}
    public function getSubNavigation(): array{return StaffTabs::domains('Monitoring settings');}
    /** A tab of the Domains area, so no breadcrumb, like the other tabs. */
    public function getBreadcrumbs(): array{return [];}
    /** Request-scoped, so a long product list reads permission once; every write checks it again. */
    protected ?bool $canManage=null;
    public function canManageProducts(): bool{return $this->canManage??=Staff::can(StaffWatchWrites::VIEW)&&Staff::can(StaffWatchWrites::MANAGE);}
    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);
        // A switch or field choice changes the product rows. Rebuild them, so the next change reads the new revision.
        unset($this->cachedSchemas['content']);
    }
    /** One settings row per hosting product, while monitoring runs. The system finds each product's domain field; staff
     * switch a product on or off, or choose the field when the system can't tell. */
    private function products(): array
    {
        if(!app(WatchWork::class)->active()){return [];}
        $setBy='Set by staff who manage domain monitoring.';
        return array_map(function(array $line)use($setBy){
            $key='row-product-'.$line['product'];
            $can=fn()=>$this->canManageProducts();
            $pick=fn()=>WatchActions::chooseField($can)->arguments(['product_id'=>$line['product'],'map'=>$line['map'],'revision'=>$line['revision']]);
            if($line['map']!==null){
                $row=SettingsRow::toggle($line['name'],$line['on'],WatchActions::productSwitch($can)->arguments(['map'=>$line['map'],'revision'=>$line['revision']]),$setBy)->key($key);
                return $line['pick']?$row->also($pick()):$row;
            }
            return $line['pick']
                ?SettingsRow::setting($line['name'],Value::state(State::NotSet,Reader::Staff,'No field clearly holds the domain. Choose the one that does.'),$setBy)->key($key)->actions($pick())
                :SettingsRow::setting($line['name'],Value::state(State::NotReadYet,Reader::Staff,'This product has no services yet, so there is no field to read.'),$setBy)->key($key);
        },WatchProducts::lines());
    }
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
        $default=\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::profile();
        $canEdit=Staff::canEditDefaultDns();$refusal=\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::refusal($canEdit);
        $fix=Staff::can(PublicSuffixList::MANAGE)?$this->edit('edit')->link():null;
        $resolver=TextEntry::make('resolver')->label('Resolver')->state($endpoint?->host??($forced?'Server default':Missing::LABEL))
            ->hintAction(HelpHint::make("If the resolver fails, checks retry once with the server's resolver."));
        return $schema->components([
            ...(!$default||$refusal?[Callout::make($refusal??($canEdit?\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::MISSING:'Default nameservers are Missing. New domains have no nameservers to use. '.\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::ASK))->status('danger')->columnSpanFull()]:[]),
            ...($forced?[Callout::make($reason)->status('danger')->columnSpanFull()]:[]),
            Sheet::split([...(($products=$this->products())!==[]?[Sheet::section('Hosting products',$products,null,'hosting-products')]:[]),Sheet::section('Monitoring',[
                Sheet::row(MissingEntry::apply(TextEntry::make('site_dns')->label(DefaultNameservers::TITLE)->state($refusal?Missing::LABEL:($default?->name??Missing::LABEL)),cause:$refusal??null)->belowContent($default?implode(', ',$default->nameservers):null),
                    [Sheet::link('defaults',$canEdit?'Change':'View',DefaultNameservers::url())->extraAttributes(['aria-label'=>($canEdit?'Change':'View').' default nameservers in DNS templates'])]),
                Sheet::row(TextEntry::make('registrar_refresh')->label('Registrar refresh')->state('Daily')->belowContent('Daily refresh reads domain facts. Use Sync now on a domain to read DNS, SRV, DS and glue records. Unsupported families remain unsupported.')),
                Sheet::row(TextEntry::make('public_checks')->label('Public DNS checks')->state('Nameservers at least every 6 hours, records daily')->belowContent('Failed checks retry while monitoring and its background worker are on. Paused checks wait; resume monitoring first.')),
                Sheet::row(TextEntry::make('read_failures')->label('Registrar read failures')->state('No separate alert switch')->belowContent('Shown on the domain page, beside Resume sync. There is no email alert for them.')),
                Sheet::row(TextEntry::make('evidence')->label('Registrar changes')->state('From the registrar only')->belowContent(\Paymenter\Extensions\Servers\Domains\Presentation\RecordFamilies::EVIDENCE))]),Sheet::section('DNS resolver',[Sheet::row(MissingEntry::apply($resolver,$fix,$forced?null:$reason)),
                Sheet::row(TextEntry::make('bootstrap')->label('Resolver IP address')->state($endpoint?->ip))->visible($endpoint!==null)])],[]),
        ]);
    }
}
