<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,RegistrarSetup};
use Illuminate\Support\Facades\{Blade,DB,Schema};
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation,Price};
use Paymenter\Extensions\Servers\Domains\Presentation\AccountStatus;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AccountSpendingHolds,CatalogueAcceptances,EnomLive,EnomTestSendLimit,NameSiloSandbox,PriceSync,RegistrarBalances};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** Facts shared by the Registrars list and the registrar account page. Values are null when Missing; 'cause' says why. */
final class RegistrarFacts
{
    public const DUE_HELP='Registrar cost of renewals due in the next 30 days, plus open renewal requests not sent yet, whatever their date.';
    /** Warning text colour for sub-lines such as "Low balance", in both themes. */
    public const STYLE='<style>.domains-warn{color:var(--warning-600)}.dark .domains-warn{color:var(--warning-400)}.domains-err{color:var(--danger-600)}.dark .domains-err{color:var(--danger-400)}'
        // One label width on the account page, so values line up with and without a button.
        .'@media (min-width:40rem){.domains-account .fi-in-entry.fi-in-entry-has-inline-label{grid-template-columns:14rem minmax(0,1fr)}.domains-account .fi-in-entry-has-inline-label .fi-in-entry-content-col{grid-column:auto}}</style>';
    /** The account's own page, or the Registrars list when the account is gone. Null without access. */
    public static function accountUrl(?int $server): ?string
    {
        if (!RegistrarAccount::canAccess()) { return null; }
        return Server::where('extension','Domains')->whereKey($server)->exists()?RegistrarAccount::getUrl(['account'=>$server]):RegistrarSetup::getUrl();
    }
    /** $label linked to the account's page. Null without access. */
    public static function accountLink(?int $server, string $label): ?HtmlString
    {
        $url=self::accountUrl($server);
        return $url?new HtmlString(Blade::render('<x-filament::link :href="$url" size="sm">{{ $label }}</x-filament::link>',['url'=>$url,'label'=>$label])):null;
    }
    public static function warn(string $text): HtmlString { return self::tone($text,'warning'); }
    /** A sub-line in the danger or warning colour. */
    public static function tone(string $text,string $tone): HtmlString
    { return new HtmlString('<span class="domains-'.($tone==='danger'?'err':'warn').'">'.e($text).'</span>'); }
    /** External link to the registrar's funding page. */
    public static function fundsLink(string $url): HtmlString
    {
        return new HtmlString(Blade::render('<x-filament::link :href="$url" target="_blank" rel="noopener noreferrer" icon="heroicon-m-arrow-top-right-on-square" icon-position="after" size="sm">Add funds</x-filament::link>',['url'=>$url]));
    }
    /** @return array{label:string,tone:string,reason:?string,request?:int} $request is the request that paused orders */
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
        $held=AccountSpendingHolds::query(Operation::where('server_id',$server->id))->where('state','uncertain')->orderBy('id')->value('id');
        if ($held!==null) { return [...AccountStatus::for($configuration,false,'Request #'.$held.' unconfirmed'),'request'=>$held]; }
        return AccountStatus::for($configuration,(bool)$account->processing_enabled);
    }
    /** The account's reason under its head. A request that paused orders is a job for staff, so it gets a mark and a link. */
    public static function reason(array $status): string|HtmlString|null
    {
        $url=isset($status['request'])?DomainFacts::requestUrl($status):null;
        if (!$url) { return $status['reason']; }
        return StatusMark::act(new HtmlString(e($status['reason']).'. '.DomainFacts::requestLink($url,'Check request #'.$status['request'].'.')));
    }
    /** Add funds and balance checks apply once the account is set up and its server is on. */
    public static function connected(?Account $account, array $status): bool
    { return $account!==null&&$status['label']!==AccountStatus::SET['not_connected'][0]; }
    public static function mode(?Account $account): ?string
    { return $account?($account->environment==='live'?'Live':'Test'):null; }
    /** "Enom · 2 domains" */
    public static function summary(Server $server, ?Account $account): string
    {
        $n=Domain::where('server_id',$server->id)->count();
        return ($account?TldFacts::registrar($account->registrar_key).' · ':'').$n.' '.($n===1?'domain':'domains');
    }
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
                $binding=CatalogueAcceptances::binding($account);
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
        return ['value'=>$u['day'].' of '.$u['day_limit'],'tone'=>$full?'danger':null,'sub'=>$off?EnomTestSendLimit::facts($uid):($full?'Limit reached. Try again later.':null),
            'help'=>'Our test send limit allows '.$u['day_limit'].' requests per login in any 24 hours and '.$u['minute_limit'].' per minute. Failed requests count too. Transfer checks stop at '.intdiv($u['day_limit'],2).' total requests to leave room for checkout and other work. This is our own guard against a runaway job.'];
    }
    public static function lastSync(Server $server): ?string
    {
        $account=Account::where('server_id',$server->id)->first();if(!$account){return null;}
        $key=['server_id'=>$server->id,'account_fingerprint'=>$account->account_fingerprint];
        // Worker turns also run when there is no registrar read. Only completed price reads belong here.
        $at=Price::where($key)->max('observed_at');$price=$at?\Carbon\CarbonImmutable::parse($at)->getTimestamp():0;
        $fill=Schema::hasTable(\Paymenter\Extensions\Servers\Domains\Services\SellTableSchema::FILLS)
            ?(int)DB::table(\Paymenter\Extensions\Servers\Domains\Services\SellTableSchema::FILLS)->where($key)->value('filled_at'):0;
        $latest=max($price,$fill);return $latest?gmdate('M j, Y H:i \U\T\C',$latest):null;
    }
    /** Verified Enom user ID for the account's mode. NameSilo has no user ID. */
    public static function apiUser(Account $account): ?string
    { try{return config('domains.enom_identity.environment')===$account->environment?$account->stable_identity:null;}catch(\Throwable){return null;} }
}
