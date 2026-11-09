<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource;
use Filament\Schemas\View\Components\TextComponent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\{Blade,DB,Schema};
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation,Price};
use Paymenter\Extensions\Servers\Domains\Presentation\AccountStatus;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,EnomLive,EnomTestSendLimit,NameSiloSandbox,RegistrarBalances};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** Facts shared by the Registrars list and the registrar account page. Values are null when Missing; 'cause' says why. */
final class RegistrarFacts
{
    public const DUE_HELP='Registrar cost of renewals due in the next 30 days, plus open renewal requests not sent yet, whatever their date.';
    public const MODE_HELP="Test and Live are separate registrar accounts. Each has its own login, domains and money, so a Test account can't become Live. To go live, connect a Live account.";
    /** Rows a link can land on: each switch's anchor name and row on the account page. */
    public const SWITCHES=['orders'=>'Orders','dns'=>'DNS, nameservers and contacts'];
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
    /** A sub-line in the danger or warning colour. Filament's own colour classes, so it reads the same without a skin. With $url it links there. */
    public static function tone(string $text,string $tone,?string $url=null): HtmlString
    {
        $class=e(Arr::toCssClasses(['domains-'.($tone==='danger'?'err':'warn'),...\Filament\Support\get_component_color_classes(TextComponent::class,$tone),'underline'=>$url!==null]));
        return new HtmlString($url===null?'<span class="'.$class.'">'.e($text).'</span>':'<a class="'.$class.'" href="'.e($url).'">'.e($text).'</a>');
    }
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
    /** Registrar requests list words that are still open: all but Completed, Failed and Cancelled. */
    public const OPEN_REQUESTS=['awaiting_payment','action_required','in_progress','on_hold','under_review'];
    /** Requests on this account, counted with the Registrar requests list's own query and Status filter, so each count's link opens exactly
     * those rows. 'staff': On hold and Under review, the statuses that wait on staff (the menu count), by status.
     * @return array{open:int,staff:array<string,int>} */
    public static function requests(int $server): array
    {
        // One read of the Orders switches, so the counts agree with each other.
        $off=OperationQueries::ordersOffAccounts();
        $count=fn(array $keys)=>OperationQueries::status(OperationResource::getEloquentQuery()->where('server_id',$server),$keys,$off)->count();
        $statuses=array_combine(self::OPEN_REQUESTS,array_map(fn(string $key)=>$count([$key]),self::OPEN_REQUESTS));
        return ['open'=>array_sum($statuses),'staff'=>array_intersect_key($statuses,array_flip(OperationResource::NEEDS_STAFF)),'statuses'=>$statuses];
    }
    /** The Registrar requests list for this account with these statuses ticked in its Status filter. Null without access. */
    public static function requestsUrl(int $server,array $statuses): ?string
    {
        try { return OperationResource::getUrl('index',['filters'=>['server_id'=>['values'=>[(string)$server]],'status'=>['values'=>array_values($statuses)]]]); }
        catch (\Throwable) { return null; }
    }
    /** Add funds and balance checks apply once the account is set up and its server is on. */
    public static function connected(?Account $account, array $status): bool
    { return $account!==null&&$status['label']!==AccountStatus::SET['not_connected'][0]; }
    public static function mode(?Account $account): ?string
    { return $account?($account->environment==='live'?'Live':'Test'):null; }
    /** @return array{value:?string,checked:?string,low:bool,cause:?string,due:string,without_cost:int,funds:?string} */
    public static function balance(Account $account): array
    {
        $f=RegistrarBalances::facts($account,app(Clock::class)->now());
        return ['value'=>$f['balance'],'checked'=>$f['checked'],'low'=>$f['low'],'cause'=>$f['cause'],'due'=>$f['total'],'without_cost'=>$f['unknown'],'unreadable'=>$f['unreadable'],'unpriced'=>$f['unpriced'],'unobserved'=>$f['unobserved'],
            'funds'=>RegistrarBalances::fundsUrl($account->registrar_key,$account->environment)];
    }
    /** Why Due in 30 days leaves renewals out, and who fills them. Only a gap staff must fix acts (amber).
     * A renewal waiting on the daily price read fills in by itself; 'at' is when that read is next due.
     * @return ?array{text:string,act:bool,at:?int} */
    public static function dueGap(Account $account,array $b): ?array
    {
        $n=static fn(int $count)=>$count.' '.($count===1?'renewal':'renewals');
        if ($b['unreadable']>0) { return ['text'=>DomainFacts::UNREADABLE_RENEWAL,'act'=>true,'at'=>null]; }
        $undated=$b['without_cost']-$b['unpriced'];
        $gap=$undated>0?['text'=>$n($undated).' with no known date, so not counted. Check the invoice days and the domain expiry.','act'=>true,'at'=>null]:null;
        if ($b['unpriced']===0) { return $gap; }
        try { $catalogue=\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue::routed();$read=$catalogue?->progress((int)$account->server_id)??[]; }
        catch (\Throwable) { $catalogue=null;$read=[]; }
        $priced=$catalogue===null
            ?['text'=>$n($b['unpriced']).' not priced yet. '.\Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade::MESSAGE,'act'=>true,'at'=>null]
            :self::readGap($n($b['unpriced']),$read,app(Clock::class)->now()->getTimestamp(),$b['unobserved']??[]);
        return $gap===null?$priced:[...$gap,'text'=>$gap['text'].' '.$priced['text']];
    }
    /** Unpriced renewals by the price read's state. Beco retries a failed read by itself (OfferWork backoff), so only a
     * cause staff must fix (TldFacts::SYNC_STEPS) is amber, with its step. @return array{text:string,act:bool,at:?int} */
    public static function readGap(string $count,array $read,int $now,array $unobserved=[]): array
    {
        $at=isset($read['due_at'])&&(int)$read['due_at']>$now?(int)$read['due_at']:null;
        if (!empty($read['failure'])) {
            $cause=strtok(TldFacts::syncCause($read['failure']),'.');
            if (($step=TldFacts::syncStep($read['failure']))!==null) {
                return ['text'=>$count.' not priced yet. The price read failed: '.$cause.'. '.$step.' Beco then fills the cost on the next read.','act'=>true,'at'=>null];
            }
            return ['text'=>$count.' not priced yet. The price read failed: '.$cause.'. '.\Paymenter\Extensions\Servers\Domains\Presentation\Missing::fills('after Beco retries the read'),
                'act'=>false,'at'=>($read['state']??null)==='retrying'?$at:null];
        }
        if($unobserved&&($read['succeeded_at']??0)>$now-86400&&empty($read['remaining'])){
            $steps=array_map(static fn(string $tld)=>'Ask the registrar for a 1-year renewal cost for .'.$tld.'.',array_keys($unobserved));
            return ['text'=>$count.' not priced yet. '.implode(' ',$steps),'act'=>true,'at'=>null];
        }
        $at=($read['state']??null)==='ready'?$at:null;
        return ['text'=>$count.' not priced yet. '.\Paymenter\Extensions\Servers\Domains\Presentation\Missing::fills($at===null?'after the price read now queued':'after the next price read'),'act'=>false,'at'=>$at];
    }
    /** The gap as a page sub-line: amber when staff act, plain otherwise. */
    public static function dueLine(?array $gap): ?HtmlString
    {
        if ($gap===null) { return null; }
        if ($gap['act']) { return self::warn($gap['text']); }
        return new HtmlString(e($gap['text']).($gap['at']===null?'':', '.Sheet::moment($gap['at'])->toHtml()).'.');
    }
    /** Each Enom login shares our saved cap; TEST rolls for 24 hours and Live resets at UTC midnight. @return ?array{value:?string,tone:?string,sub:?string,help:?string} */
    public static function calls(Account $account): ?array
    {
        if ($account->registrar_key!=='enom') { return null; }
        if ($account->environment==='live') {
            try {
                $binding=AccountBindings::binding($account);
                $u=EnomLive::deployment($binding)?\Paymenter\Extensions\Servers\Domains\Services\ApiCallLimits::usage($binding):null;
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
        $feed=(int)DB::table('domains_observation_queues')->where($key)->value('offer_feed_succeeded_at');
        return max($price,$feed)?:null;
    }
    /** Verified Enom user ID for the account's mode. NameSilo has no user ID. */
    public static function apiUser(Account $account): ?string
    { try{return config('domains.enom_identity.environment')===$account->environment?$account->stable_identity:null;}catch(\Throwable){return null;} }
}
