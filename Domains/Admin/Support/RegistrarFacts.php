<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,RegistrarSetup};
use Filament\Schemas\View\Components\TextComponent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\{Blade,DB,Schema};
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation,Price};
use Paymenter\Extensions\Servers\Domains\Presentation\AccountStatus;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AccountSpendingHolds,CatalogueAcceptances,EnomLive,EnomTestSendLimit,NameSiloSandbox,PriceSync,RegistrarBalances};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** Facts shared by the Registrars list and the registrar account page. Values are null when Missing; 'cause' says why. */
final class RegistrarFacts
{
    public const DUE_HELP='Registrar cost of renewals due in the next 30 days, plus open renewal requests not sent yet, whatever their date.';
    public const MODE_HELP="Test and Live are separate registrar accounts. Each has its own login, domains and money, so a Test account can't become Live. To go live, connect a Live account.";
    /** Rows a link can land on: each one's anchor name and its row on the account page. The two switches, and the DNS template new domains use. */
    public const SWITCHES=['orders'=>'Orders','dns'=>'DNS, nameservers and contacts','template'=>'New domains use'];
    /** The account's own page, or the Registrars list when the account is gone. Null without access. $switch lands on that switch (SWITCHES). */
    public static function accountUrl(?int $server, ?string $switch=null): ?string
    {
        if (!RegistrarAccount::canAccess()) { return null; }
        if (!Server::where('extension','Domains')->whereKey($server)->exists()) { return RegistrarSetup::getUrl(); }
        return $switch===null?RegistrarAccount::getUrl(['account'=>$server]):RegistrarAccount::getUrl(['account'=>$server,'switch'=>$switch]).'#switch-'.$switch;
    }
    /** $label linked to the account's page. Null without access. */
    public static function accountLink(?int $server, string $label): ?HtmlString
    {
        $url=self::accountUrl($server);
        return $url?new HtmlString(Blade::render('<x-filament::link :href="$url" size="sm">{{ $label }}</x-filament::link>',['url'=>$url,'label'=>$label])):null;
    }
    public static function warn(string $text): HtmlString { return self::tone($text,'warning'); }
    /** A sub-line in the danger or warning colour. Filament's own colour classes, so it reads the same without a skin. */
    public static function tone(string $text,string $tone): HtmlString
    { return new HtmlString('<span class="'.e(Arr::toCssClasses(['domains-'.($tone==='danger'?'err':'warn'),...\Filament\Support\get_component_color_classes(TextComponent::class,$tone)])).'">'.e($text).'</span>'); }
    /** The account's own state. A request that needs review shows on the Registrar requests row, not here.
     * @return array{label:string,tone:string,reason:?string} */
    public static function status(Server $server, ?Account $account): array
    {
        $configuration=app(AccountBindings::class)->configurationStatus((int)$server->id);
        if ($configuration==='configuration_unavailable'&&$account&&NameSiloSandbox::enrolled($account)&&!NameSiloSandbox::enabled()) {
            return AccountStatus::for($configuration,true,AccountStatus::TEST_MODE_OFF);
        }
        if ($configuration!=='matched'||!$account) { return AccountStatus::for($configuration); }
        // The worker's last turn used other credentials than the saved ones.
        $turn=Schema::hasTable('domains_runtime_turns')?DB::table('domains_runtime_turns')->where('server_id',$server->id)->value('account_fingerprint'):null;
        if ($turn!==null&&$turn!==$account->account_fingerprint) { return AccountStatus::for('configuration_unavailable'); }
        if (DemoData::paused($account)) { return AccountStatus::for($configuration,false,AccountStatus::DEMO_PAUSED); }
        return AccountStatus::for($configuration,(bool)$account->processing_enabled);
    }
    /** @return array{total:int,review:int} Requests on this account, and those paid but not confirmed by the registrar. */
    public static function requests(int $server): array
    { return ['open'=>Operation::where('server_id',$server)->whereNotIn('state',\Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus::FINISHED)->count(),'review'=>AccountSpendingHolds::query(Operation::where('server_id',$server))->where('state','uncertain')->count()]; }
    /** Add funds and balance checks apply once the account is set up and its server is on. */
    public static function connected(?Account $account, array $status): bool
    { return $account!==null&&$status['label']!==AccountStatus::SET['not_connected'][0]; }
    public static function mode(?Account $account): ?string
    { return $account?($account->environment==='live'?'Live':'Test'):null; }
    /** @return array{value:?string,checked:?string,low:bool,cause:?string,due:string,without_cost:int,funds:?string} */
    public static function balance(Account $account): array
    {
        $f=RegistrarBalances::facts($account,app(Clock::class)->now());
        return ['value'=>$f['balance'],'checked'=>$f['checked'],'low'=>$f['low'],'cause'=>$f['cause'],'due'=>$f['total'],'without_cost'=>$f['unknown'],'unreadable'=>$f['unreadable'],
            'funds'=>RegistrarBalances::fundsUrl($account->registrar_key,$account->environment)];
    }
    /** Each Enom login shares our saved cap; TEST rolls for 24 hours and Live resets at UTC midnight. @return ?array{value:?string,tone:?string,sub:?string,help:?string} */
    public static function calls(Account $account): ?array
    {
        if ($account->registrar_key!=='enom') { return null; }
        if ($account->environment==='live') {
            try {
                $binding=AccountBindings::binding($account);
                $u=EnomLive::deployment($binding)?PriceSync::callsToday($binding):null;
            } catch (\Throwable) { $u=null; }
            if ($u===null) { return ['value'=>null,'tone'=>null,'sub'=>"Couldn't read our call count or limit. Check the API credentials and Live account setup.",'help'=>null]; }
            $full=$u['used']>=$u['limit'];$off=$u['limit']===0;
            return ['value'=>$u['used'].' of '.$u['limit'],'tone'=>$full?'danger':null,'sub'=>$off?'Our saved daily call limit is missing or 0. Ask your site admin to set a limit above 0 using Edit on API calls.':($full?'Limit reached. Further calls wait until the count resets.':null),
                'help'=>'Our daily limit on registrar calls. It stops a runaway job. Resets at midnight UTC. Failed and unsent reservations count too.'];
        }
        $uid=EnomTestSendLimit::login($account);$u=EnomTestSendLimit::usage((string)$uid);
        if ($uid===null||$u['day_limit']===null||$u['minute_limit']===null||$u['day']===null) { return ['value'=>null,'tone'=>null,'sub'=>EnomTestSendLimit::facts($uid),'help'=>null]; }
        $full=$u['day']>=$u['day_limit']||$u['minute']>=$u['minute_limit'];
        $off=$u['day_limit']===0||$u['minute_limit']===0;
        return ['value'=>number_format($u['day']).' of '.number_format($u['day_limit']),'tone'=>$full?'danger':null,'sub'=>$off?EnomTestSendLimit::facts($uid):($full?'Limit reached. Try again later.':null),
            'help'=>'Our test send limit allows '.$u['day_limit'].' requests per login in any 24 hours and '.$u['minute_limit'].' per minute. Failed requests count too. Transfer checks stop at '.intdiv($u['day_limit'],2).' total requests to leave room for checkout and other work. This is our own guard against a runaway job.'];
    }
    /** When the registrar's prices were last read, as a timestamp. */
    public static function lastSync(int $server): ?int
    {
        $account=Account::where('server_id',$server)->first();if(!$account){return null;}
        $key=['server_id'=>$server,'account_fingerprint'=>$account->account_fingerprint];
        // Worker turns also run when there is no registrar read. Only completed price reads belong here.
        $at=Price::where($key)->max('observed_at');$price=$at?\Carbon\CarbonImmutable::parse($at)->getTimestamp():0;
        $fill=Schema::hasTable(\Paymenter\Extensions\Servers\Domains\Services\SellTableSchema::FILLS)
            ?(int)DB::table(\Paymenter\Extensions\Servers\Domains\Services\SellTableSchema::FILLS)->where($key)->value('filled_at'):0;
        return max($price,$fill)?:null;
    }
    /** Verified Enom user ID for the account's mode. NameSilo has no user ID. */
    public static function apiUser(Account $account): ?string
    { try{return config('domains.enom_identity.environment')===$account->environment?$account->stable_identity:null;}catch(\Throwable){return null;} }
}
