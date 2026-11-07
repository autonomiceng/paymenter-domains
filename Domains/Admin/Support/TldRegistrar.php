<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Catalogue\SellerAvailability;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
/** Registrar-choice wording lives here so the TLD page and dialog use the same causes. */
final class TldRegistrar
{
    public static function missingPrice(string $registrar,string $ending): string
    {return 'No '.(['enom'=>'Enom','namesilo'=>'NameSilo'][$registrar]??'registrar').' price for .'.$ending.' yet. Sync prices first.';}
    /** @return array<int,array{label:string,reason:?string,disabled:bool}> */
    public static function choices(int $id): array
    {
        $ending=Tld::findOrFail($id)->tld;$out=[];
        $rows=Tld::where('tld',$ending)->get()->keyBy('server_id');
        $servers=Server::whereIn('id',Account::pluck('server_id'))->orderBy('id')->get()->keyBy('id');
        foreach(Account::whereIn('server_id',$servers->keys())->orderBy('server_id')->get() as $account){
            $server=$servers[$account->server_id];$disabled=false;
            if(!SellerAvailability::hasPrices($account,$rows[$account->server_id]??null)){$reason=self::missingPrice($account->registrar_key,$ending);$disabled=true;}
            elseif(!$server->enabled||$server->extension!=='Domains'){$reason='This registrar account is off. Turn it on in Servers.';$disabled=true;}
            else{$reason=$account->processing_enabled?null:'Orders are paused. Turn on Orders on this registrar account.';}
            $out[(int)$account->server_id]=['label'=>(string)$server->name,'reason'=>$reason,'disabled'=>$disabled];
        }
        return $out;
    }
    public static function refusal(\Throwable $error,array $data,?int $id): ?string
    {
        $ending=$id?Tld::whereKey($id)->value('tld'):null;
        if($ending===null){return 'This TLD changed. Reload the page and choose its registrar again.';}
        $account=Account::where('server_id',(int)($data['seller']??0))->first();
        return match($error->getMessage()){
            'Seller offer missing.','Seller price missing.'=>self::missingPrice($account?->registrar_key??'',$ending),
            'Seller account missing.'=>'This registrar account was removed. Choose another registrar.',
            'Offer binding or conversion changed.','Price binding changed.','Seller offer group changed; retry.','Offer disappeared.'=>'Saved registrar prices changed. Reopen Change registrar and try again.',
            default=>null,
        };
    }
}
