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
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AccountSpendingHolds,EnomTestCalls,RegistrarBalances};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** Facts shared by the Registrars list and the registrar account page. Values are null when Missing; 'cause' says why. */
final class RegistrarFacts
{
    public const DUE_HELP='Registrar cost of renewals due in the next 30 days, plus open renewal requests not sent yet, whatever their date.';
    /** Warning text colour for sub-lines such as "Low balance", in both themes. */
    public const STYLE='<style>.domains-warn{color:var(--warning-600)}.dark .domains-warn{color:var(--warning-400)}</style>';
    public static function warn(string $text): HtmlString
    { return new HtmlString('<span class="domains-warn">'.e($text).'</span>'); }
    /** External link to the registrar's funding page. */
    public static function fundsLink(string $url): HtmlString
    {
        return new HtmlString(Blade::render('<x-filament::link :href="$url" target="_blank" rel="noopener noreferrer" icon="heroicon-m-arrow-top-right-on-square" icon-position="after" size="sm">Add funds</x-filament::link>',['url'=>$url]));
    }
    /** @return array{label:string,tone:string,reason:?string} */
    public static function status(Server $server, ?Account $account): array
    {
        $configuration=app(AccountBindings::class)->configurationStatus((int)$server->id);
        if ($configuration!=='matched'||!$account) { return AccountStatus::for($configuration); }
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
    /** Enom test accounts share one daily call limit. @return ?array{value:?string,tone:?string,sub:?string,help:?string} */
    public static function calls(Account $account): ?array
    {
        if ($account->registrar_key!=='enom'||$account->environment!=='test') { return null; }
        ['used'=>$used,'limit'=>$limit]=EnomTestCalls::usage();
        if ($limit===null) { return ['value'=>null,'tone'=>null,'sub'=>'Enom test call limit is not set in server config.','help'=>null]; }
        if ($used===null) { return ['value'=>null,'tone'=>null,'sub'=>"Couldn't read today's call count",'help'=>null]; }
        $full=$used>=$limit;
        return ['value'=>$used.' of '.$limit,'tone'=>$full?'danger':null,'sub'=>$full?'Resets 00:00 UTC':null,
            'help'=>'Enom limits test accounts to '.$limit.' calls per day (UTC), shared by all test accounts.'];
    }
    public static function lastSync(Server $server): ?string
    {
        if (!Schema::hasTable('domains_runtime_turns')) { return null; }
        $at=DB::table('domains_runtime_turns')->where('server_id',$server->id)->value('finished_at');
        return $at?gmdate('M j, Y H:i \U\T\C',(int)$at):null;
    }
    /** Verified Enom user ID for the account's mode. NameSilo has no user ID. */
    public static function apiUser(Account $account): ?string
    { return config('domains.enom_identity.environment')===$account->environment?$account->stable_identity:null; }
}
