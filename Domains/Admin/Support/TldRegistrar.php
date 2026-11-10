<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState,SellerAvailability,Writer};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
/** Registrar-choice wording lives here so the TLD page and dialog use the same causes. */
final class TldRegistrar
{
    private static function name(string $registrar): string {return ['enom'=>'Enom','namesilo'=>'NameSilo'][$registrar]??'registrar';}
    public static function missingPrice(string $registrar,string $ending): string
    {return 'No '.self::name($registrar).' price for .'.$ending.' yet. Sync prices first.';}
    /** The saved 1-year cost is damaged, not absent. The next price sync replaces it. */
    public static function unreadablePrice(string $registrar,string $ending): string
    {return 'The saved '.self::name($registrar).' price for .'.$ending." can't be read. Sync prices to replace it.";}
    /** @return array<int,array{label:string,reason:?string,disabled:bool}> */
    public static function choices(int $id): array
    {
        $ending=Tld::findOrFail($id)->tld;$out=[];
        $rows=Tld::where('tld',$ending)->get()->keyBy('server_id');
        $servers=Server::whereIn('id',Account::pluck('server_id'))->orderBy('id')->get()->keyBy('id');
        foreach(Account::whereIn('server_id',$servers->keys())->orderBy('server_id')->get() as $account){
            $server=$servers[$account->server_id];$disabled=false;
            $prices=SellerAvailability::state($account,$rows[$account->server_id]??null);
            if($prices!==SellerAvailability::READY){$reason=$prices===SellerAvailability::UNREADABLE?self::unreadablePrice($account->registrar_key,$ending):self::missingPrice($account->registrar_key,$ending);$disabled=true;}
            elseif(!$server->enabled||$server->extension!=='Domains'){$reason='This registrar account is off. Turn it on in Servers.';$disabled=true;}
            elseif(($unsupported=self::unsupported($account,$rows[$account->server_id]??null,$ending))!==null){$reason=$unsupported;$disabled=true;}
            else{$reason=$account->processing_enabled?null:'Orders are paused. Turn on Orders on this registrar account.';}
            $out[(int)$account->server_id]=['label'=>(string)$server->name,'reason'=>$reason,'disabled'=>$disabled];
        }
        return $out;
    }
    /** A requirement in this account's current registrar terms that checkout can't meet, named as the TLD page names it.
     * The save refuses it too, so a retry with this registrar can't work. Null when its terms allow a sale or are not current. */
    private static function unsupported(Account $account,?Tld $row,string $ending): ?string
    {
        if($row===null){return null;}
        try{$facts=OfferState::facts($row->catalogue_facts);}catch(\Throwable){return null;}
        if(Writer::termsBlock($facts,(string)$account->registrar_key,$ending,false,1,false,app(Clock::class)->now())!=='unsupported_facts'){return null;}
        return 'This registrar '.Writer::unsupportedCause($facts,(string)$account->registrar_key,$ending).' for .'.$ending.'. Choose another registrar.';
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
