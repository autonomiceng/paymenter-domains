<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
/** Account opt-in only. Live requires dated provider evidence in the deployment, never prefilled lifecycle settings. */
final class EnomLateRenewal
{
    public const KEY='domains_enom_test_late_renewal';
    public const PAUSED='late_renewal_paused';
    public const CONTRACT='enom_grace_v1';
    public const HELP='Renews expired domains for one year after fresh registrar checks. The invoice uses the current cost plus the regular margin. Auto Pay follows the domain’s settings and tries once. Redemption needs support.';
    public static function available(AccountBinding $b): bool
    {
        if(!EnomTestRenewal::accepted($b)){return false;}
        if($b->environment===Environment::Test){return true;}
        try{
            $e=EnomLive::account($b)['late_renewal_evidence']??null;
            return is_array($e)&&($e['contract']??null)===self::CONTRACT
                &&is_string($e['reference']??null)&&trim($e['reference'])!==''&&strlen($e['reference'])<=255
                &&is_string($e['sha256']??null)&&preg_match('/^[a-f0-9]{64}$/D',$e['sha256'])===1
                &&is_string($e['verified_at']??null)&&preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D',$e['verified_at'])
                &&($at=\DateTimeImmutable::createFromFormat(DATE_ATOM,$e['verified_at']))!==false&&$at->format(DATE_ATOM)===$e['verified_at']&&$at<=app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()
                &&($e['expiry_timezone']??null)==='America/Los_Angeles'
                &&($e['deadline']??null)==='expiry_plus_delete_day'
                &&($e['price']??null)==='getextendinfo_check_renewal';
        }catch(\Throwable){return false;}
    }
    public static function stored(AccountBinding $b): bool
    {
        try{$s=Server::findOrFail($b->serverId)->settings()->where('key',EnomLive::key($b,self::KEY))->first();
            return $s?->encrypted===true&&json_decode($s->value,true,8,JSON_THROW_ON_ERROR)===self::agreement($b);
        }catch(\Throwable){return false;}
    }
    private static function agreement(AccountBinding $b): array
    {return ['fingerprint'=>$b->fingerprint,'contract'=>self::CONTRACT,...($b->environment===Environment::Live?['evidence'=>hash('sha256',json_encode(EnomLive::account($b)['late_renewal_evidence']??null,JSON_THROW_ON_ERROR))]:[])];}
    public static function accepted(AccountBinding $b): bool {return self::available($b)&&self::stored($b);}
    public static function review(StaffSettingsReview $r,array $data): void
    {
        $r->local(function()use($r,$data):void{
            $b=AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$r->serverId)->sole());
            if($b->registrarKey!=='enom'||$r->tldId!==null){throw new \DomainException('Enom account required.');}
            $on=($data['accept']??false)===true;
            // This step cannot establish provider semantics: only the site operator can record the dated Enom answer.
            if($on&&!EnomTestRenewal::accepted($b)){throw new \DomainException('Renewals Off. Turn on Renewals for this account first.');}
            if($on&&!self::available($b)){throw new \DomainException('Late renewal evidence Missing. Ask your site admin to record Enom’s answer for this account.');}
            $s=Server::findOrFail($b->serverId)->settings()->firstOrNew(['key'=>EnomLive::key($b,self::KEY)]);
            $s->encrypted=true;$s->type='string';$s->value=json_encode($on?self::agreement($b):null,JSON_THROW_ON_ERROR);$s->save();
        });
    }
}
