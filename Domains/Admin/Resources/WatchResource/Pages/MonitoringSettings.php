<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DefaultNameservers,HelpHint,Sheet,Staff,StaffTabs,WatchActions};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Head,Row,SettingsRow,Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{ResourceSettingsPage,Section};
use Paymenter\Extensions\Servers\Domains\Dns\DohEndpoint;
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact,Plain,Reader,RecordFamilies,State};
use Paymenter\Extensions\Servers\Domains\Services\{DnsResolverSettings,SiteDnsDefault,StaffWatchWrites,WatchProducts,WatchWork};
/** Domains > Monitoring settings, a Settings page: which hosting products are monitored (Ilya, decision 2) and the DNS
 * resolver monitoring checks use. Settings live in native encrypted settings.
 * The default nameservers are shown here and changed in Domains > DNS templates. */
class MonitoringSettings extends ResourceSettingsPage
{
    protected static string $resource=WatchResource::class;
    protected static ?string $title='Monitoring settings';
    protected static ?string $breadcrumb='Monitoring settings';
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
    /** One resolver form: in the header once a resolver is saved, otherwise as the fix in the Resolver row. */
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
    private function getHeaderActionsList(): array
    {
        return [$this->edit('configure')->visible(fn()=>Staff::can(PublicSuffixList::MANAGE)&&app(DnsResolverSettings::class)->configuration()[0]!==null),
            Action::make('check')->label('Test resolver')->color('gray')->visible(fn()=>Staff::can(PublicSuffixList::MANAGE)&&app(DnsResolverSettings::class)->configuration()[0]!==null)
                ->action(function(): void{
                    self::requireManage();$result=app(DnsResolverSettings::class)->check();
                    $notice=Notification::make()->title($result['message']);($result['ok']?$notice->success():$notice->warning())->send();
                })];
    }
    protected function head(): Head { return Head::make('Monitoring settings', Reader::Staff); }
    protected function actions(): array { return $this->getHeaderActionsList(); }
    protected function sections(): array
    {
        abort_unless(self::canAccess(),403);
        [$endpoint,$reason,$forced]=app(DnsResolverSettings::class)->configuration();
        $default=SiteDnsDefault::profile();$staff=Reader::Staff;$who='Set by staff who manage domain monitoring.';$system='Set by the system.';
        $canEdit=Staff::canEditDefaultDns();$refusal=SiteDnsDefault::refusal($canEdit);
        $fix=Staff::can(PublicSuffixList::MANAGE)?$this->edit('edit')->link():null;
        $missingDefault=!$default||$refusal;
        $nameservers=SettingsRow::setting(DefaultNameservers::TITLE,$refusal===null&&$default?Value::fact(Fact::names([$default->name]),implode(', ',$default->nameservers).'.'):Value::state(State::NotSet,$staff,$refusal??'New domains have no nameservers to use.'),'Set by staff in Domains > DNS templates.')
            ->actions(Sheet::link('defaults',$canEdit?'Change':'View',DefaultNameservers::url())->extraAttributes(['aria-label'=>($canEdit?'Change':'View').' default nameservers in DNS templates']));
        $resolver=SettingsRow::setting('Resolver',$endpoint?Value::fact(Fact::names([$endpoint->host])):($forced?Value::plain(Plain::ServerDefault):Value::state(State::NotSet,$staff,$reason)),$who)
            ->help("If the resolver fails, checks retry once with the server's resolver.");
        if(!$endpoint&&!$forced&&$fix!==null){$resolver->actions($fix);}
        return [
            ...(($products=$this->products())!==[]?[Section::make('Hosting products',$products,null,'hosting-products')]:[]),
            Section::make('Monitoring',[
                ...($missingDefault?[Row::notice('default-nameservers',Value::state(State::NotSet,$staff,$refusal??($canEdit?SiteDnsDefault::NONE:'New domains have no nameservers to use. '.SiteDnsDefault::ASK)))]:[]),
                $nameservers,
                SettingsRow::setting('Registrar refresh',Value::plain(Plain::Daily,'Daily refresh reads domain facts. Use Sync now on a domain to read DNS, SRV, DS and glue records. Unsupported families remain unsupported.'),$system)
                    ->help('Registrar changes come from the registrar only. '.RecordFamilies::EVIDENCE),
                SettingsRow::setting('Public DNS checks',Value::plain(Plain::Default,'Nameservers at least every 6 hours, records daily. Failed checks retry while monitoring and its background worker are on. Paused checks wait; resume monitoring first.'),$system),
                SettingsRow::setting('Registrar read failures',Value::plain(Plain::None,'Shown on the domain page, beside Resume sync. There is no email alert for them.'),$system),
            ]),
            Section::make('DNS resolver',[
                ...($forced?[Row::notice('resolver-forced',Value::state(State::NotSupported,$staff,$reason))]:[]),
                $resolver,
                ...($endpoint!==null?[SettingsRow::setting('Resolver IP address',Value::fact(Fact::code($endpoint->ip)),$who)]:[]),
            ]),
        ];
    }
}
