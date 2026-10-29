<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\Server;
use Illuminate\Support\Facades\{Blade,DB,Schema};
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\AccountStatus;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AccountSpendingHolds,EnomTestSendLimit,NameSiloSandbox,RegistrarBalances};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** Facts shared by the Registrars list and the registrar account page. Values are null when Missing; 'cause' says why. */
final class RegistrarFacts
{
    public const DUE_HELP='Registrar cost of renewals due in the next 30 days, plus open renewal requests not sent yet, whatever their date.';
    /** Warning text colour for sub-lines such as "Low balance", in both themes. */
    public const STYLE='<style>.domains-warn{color:var(--warning-600)}.dark .domains-warn{color:var(--warning-400)}.domains-err{color:var(--danger-600)}.dark .domains-err{color:var(--danger-400)}'
        // One label width on the account page, so values line up with and without a button.
        .'@media (min-width:40rem){.domains-account .fi-in-entry.fi-in-entry-has-inline-label{grid-template-columns:14rem minmax(0,1fr)}.domains-account .fi-in-entry-has-inline-label .fi-in-entry-content-col{grid-column:auto}}</style>';
    public static function warn(string $text): HtmlString { return self::tone($text,'warning'); }
    /** A sub-line in the danger or warning colour. */
    public static function tone(string $text,string $tone): HtmlString
    { return new HtmlString('<span class="domains-'.($tone==='danger'?'err':'warn').'">'.e($text).'</span>'); }
    /** External link to the registrar's funding page. */
    public static function fundsLink(string $url): HtmlString
    {
        return new HtmlString(Blade::render('<x-filament::link :href="$url" target="_blank" rel="noopener noreferrer" icon="heroicon-m-arrow-top-right-on-square" icon-position="after" size="sm">Add funds</x-filament::link>',['url'=>$url]));
    }
    /** @return array{label:string,tone:string,reason:?string} */
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
        if ($held!==null) { return AccountStatus::for($configuration,false,'Request #'.$held.' unconfirmed'); }
        return AccountStatus::for($configuration,(bool)$account->processing_enabled);
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
        return ['value'=>$f['balance'],'checked'=>$f['checked'],'low'=>$f['low'],'cause'=>$f['cause'],'due'=>$f['total'],'without_cost'=>$f['unknown'],
            'funds'=>RegistrarBalances::fundsUrl($account->registrar_key,$account->environment)];
    }
    /** Each Enom test login has its own rolling send limit. @return ?array{value:?string,tone:?string,sub:?string,help:?string} */
    public static function calls(Account $account): ?array
    {
        if ($account->registrar_key!=='enom'||$account->environment!=='test') { return null; }
        $uid=EnomTestSendLimit::login($account);$u=EnomTestSendLimit::usage((string)$uid);
        if ($uid===null||$u['day_limit']===null||$u['minute_limit']===null||$u['day']===null) { return ['value'=>null,'tone'=>null,'sub'=>EnomTestSendLimit::facts($uid),'help'=>null]; }
        $full=$u['day']>=$u['day_limit']||$u['minute']>=$u['minute_limit'];
        $off=$u['day_limit']===0||$u['minute_limit']===0;
        return ['value'=>$u['day'].' of '.$u['day_limit'],'tone'=>$full?'danger':null,'sub'=>$off?EnomTestSendLimit::facts($uid):($full?'Limit reached. Try again later.':null),
            'help'=>'Our TEST send limit allows '.$u['day_limit'].' requests per login in any 24 hours and '.$u['minute_limit'].' per minute. Failed requests count too.'];
    }
    public static function lastSync(Server $server): ?string
    {
        if (!Schema::hasTable('domains_runtime_turns')) { return null; }
        $at=DB::table('domains_runtime_turns')->where('server_id',$server->id)->value('finished_at');
        return $at?gmdate('M j, Y H:i \U\T\C',(int)$at):null;
    }
    /** Verified Enom user ID for the account's mode. NameSilo has no user ID. */
    public static function apiUser(Account $account): ?string
    { try{return config('domains.enom_identity.environment')===$account->environment?$account->stable_identity:null;}catch(\Throwable){return null;} }
}
