<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\Server;
use Illuminate\Support\{Facades\Blade,HtmlString};
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState,SellerAvailability,StaffCatalogue,Writer};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{AdmittedTransport,NameSiloLiveReservationPolicy,TestReservationPolicy};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,TestCatalogue};
/** Registrar-choice wording lives here so the TLD page and dialog use the same causes. */
final class TldRegistrar
{
    private static function name(string $registrar): string {return ['enom'=>'Enom','namesilo'=>'NameSilo'][$registrar]??'registrar';}
    private static function missingPrice(string $registrar,string $ending): string
    {return 'No eligible '.self::name($registrar).' prices for .'.$ending.' are saved.';}
    private static function unreadablePrice(string $registrar,string $ending): string
    {return 'The saved '.self::name($registrar).' price for .'.$ending." can't be read.";}

    /** @return array<int,array{label:string,reason:?string,disabled:bool,price_issue:bool}> */
    public static function choices(int $id): array
    {
        $ending=Tld::findOrFail($id)->tld;$out=[];
        $rows=Tld::where('tld',$ending)->get()->keyBy('server_id');
        $servers=Server::whereIn('id',Account::pluck('server_id'))->orderBy('id')->get()->keyBy('id');
        foreach(Account::whereIn('server_id',$servers->keys())->orderBy('server_id')->get() as $account){
            $server=$servers[$account->server_id];
            $row=$rows[$account->server_id]??null;
            $prices=SellerAvailability::state($account,$row);
            $off=!$server->enabled||$server->extension!=='Domains';
            $unsupported=self::unsupported($account,$row,$ending);
            $priceIssue=!$off&&$unsupported===null&&$prices!==SellerAvailability::READY;
            $reason=match(true){
                $off=>'This registrar account is off.',
                $unsupported!==null=>$unsupported,
                $prices===SellerAvailability::UNREADABLE=>self::unreadablePrice($account->registrar_key,$ending),
                $priceIssue=>self::missingPrice($account->registrar_key,$ending),
                !$account->processing_enabled=>'Orders are paused on this account.',
                default=>null,
            };
            $out[(int)$account->server_id]=['label'=>(string)$server->name,'reason'=>$reason,'disabled'=>$off||$unsupported!==null||$priceIssue,'price_issue'=>$priceIssue];
        }
        return $out;
    }

    public static function selectable(array $choices, ?int $except=null): bool
    {
        foreach($choices as $id=>$choice){
            if($id!==$except&&!$choice['disabled']){return true;}
        }
        return false;
    }

    public static function none(string $ending, bool $other=false): string
    {return 'No '.($other?'other ':'').'connected registrar can be selected for .'.$ending.' right now.';}

    /** Status comes from the account feed, even when the account has no local row for this TLD. */
    public static function priceRecovery(array $choices): array
    {
        $catalogue=StaffCatalogue::routed();
        $out=[];
        foreach($choices as $id=>$choice){
            if(!$choice['price_issue']){continue;}
            try{$status=$catalogue?->progress($id)??[];}catch(\Throwable){$status=[];}
            $state=$status['state']??null;
            $queued=$state==='queued'&&(isset($status['due_at'])||!empty($status['remaining']));
            $failure=$status['failure']??null;
            $completed=$state==='ready'&&isset($status['succeeded_at']);
            $reason=match(true){
                $failure!==null=>$choice['reason'].' Price sync failed. '.rtrim(TldFacts::syncCause($failure),'.').'.',
                $state==='running'=>$choice['reason'].' Price sync is running for this account.',
                $queued=>$choice['reason'].' Price sync is queued for this account.',
                $completed=>'The last price sync finished. '.$choice['reason'],
                default=>$choice['reason'],
            };
            $url=null;
            if($failure===null&&!$queued&&$state!=='running'&&!$completed&&self::canRecheck($id)){
                $url=RegistrarFacts::accountUrl($id);
                if($url!==null){$reason.=' Use Sync prices on this account to check for new prices.';}
            }
            $out[$id]=['label'=>$choice['label'],'reason'=>$reason,'url'=>$url];
        }
        return $out;
    }

    private static function canRecheck(int $id): bool
    {
        // The account action also needs a converted TLD to enter the existing import writer.
        if(!SettingsActions::syncAll($id)->isVisible()){return false;}
        try{
            $binding=AccountBindings::binding(Account::where('server_id',$id)->sole());
            $first=Tld::where('server_id',$id)->where('catalogue_converted',true)->orderBy('id')->first();
            if($first===null||$first->account_fingerprint!==$binding->fingerprint||$first->registrar_key!==$binding->registrarKey){return false;}
            if($binding->registrarKey==='enom'){return TestCatalogue::enabled($binding);}
            if($binding->registrarKey!=='namesilo'||!class_exists(AdmittedTransport::class)){return false;}
            $policy=$binding->environment===Environment::Test?new TestReservationPolicy:new NameSiloLiveReservationPolicy;
            $policy->requireFeature($binding,'catalogue');
            return true;
        }catch(\Throwable){return false;}
    }

    /** Give every option a readable status before the native select opens. */
    public static function choiceSummary(array $choices, string $ending): HtmlString
    {
        $prices=self::priceRecovery($choices);
        $lines=self::selectable($choices)?[]:[e(self::none($ending))];
        foreach($choices as $id=>$choice){
            $price=$prices[$id]??null;
            $reason=$price['reason']??$choice['reason'];
            $line='<strong>'.e($choice['label']).'</strong><br>';
            $line.=$choice['disabled']?e($reason):e('Can be selected.'.($reason===null?'':' '.$reason));
            if(($url=$price['url']??null)!==null){
                $line.=' '.Blade::render('<x-filament::link :href="$url" size="sm">{{ $label }}</x-filament::link>',['url'=>$url,'label'=>'Open '.$choice['label']]);
            }
            $lines[]=$line;
        }
        return new HtmlString(implode('<br><br>',$lines));
    }

    /** A requirement in this account's current registrar terms that checkout can't meet, named as the TLD page names it.
     * The save refuses it too, so a retry with this registrar can't work. Null when its terms allow a sale or are not current. */
    private static function unsupported(Account $account,?Tld $row,string $ending): ?string
    {
        if($row===null||!$row->catalogue_converted||$row->account_fingerprint!==$account->account_fingerprint||$row->registrar_key!==$account->registrar_key){return null;}
        try{$facts=OfferState::facts($row->catalogue_facts);}catch(\Throwable){return null;}
        if(Writer::termsBlock($facts,(string)$account->registrar_key,$ending,false,1,false,app(Clock::class)->now())!=='unsupported_facts'){return null;}
        return 'This registrar '.Writer::unsupportedCause($facts,(string)$account->registrar_key,$ending).' for .'.$ending.'.';
    }
    public static function refusal(\Throwable $error,array $data,?int $id): ?string
    {
        $ending=$id?Tld::whereKey($id)->value('tld'):null;
        if($ending===null){return 'This TLD changed. Reload the page and choose its registrar again.';}
        $account=Account::where('server_id',(int)($data['seller']??0))->first();
        return match($error->getMessage()){
            'Seller offer missing.','Seller price missing.'=>self::missingPrice($account?->registrar_key??'',$ending),
            'Seller price unreadable.'=>self::unreadablePrice($account?->registrar_key??'',$ending),
            'Seller account missing.'=>'This registrar account was removed. Choose another registrar.',
            'Seller account off.'=>'This registrar account is off. Turn it on in Servers.',
            'Explicit verified widening required.'=>$account&&($row=Tld::where('server_id',$account->server_id)->where('tld',$ending)->first())&&($unsupported=self::unsupported($account,$row,$ending))!==null
                ?$unsupported.' Nothing was saved.':null,
            'Offer binding or conversion changed.','Price binding changed.','Seller offer group changed; retry.','Offer disappeared.'=>'Saved registrar prices changed. Reopen Change registrar and try again.',
            default=>null,
        };
    }
}
