<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
/** Durable independent public HTTP family. A host circuit never holds DNS or another registry. */
final class RdapQuota
{
    public const TABLE='domains_public_rdap_pool';
    public int $blockedUntil=0;
    private array $claims=[];
    public function reserve(string $url,int $now): bool
    {
        if (DB::transactionLevel()!==0 || !Rdap::safeUrl($url) || $now<0) { return false; }
        $host=hash('sha256',strtolower(parse_url($url,PHP_URL_HOST)));
        return DB::transaction(function()use($host,$now):bool {
            foreach (['global',$host] as $scope) { DB::table(self::TABLE)->insertOrIgnore(['scope'=>$scope,'window_at'=>$now]); }
            $g=DB::table(self::TABLE)->where('scope','global')->lockForUpdate()->first();
            $h=DB::table(self::TABLE)->where('scope',$host)->lockForUpdate()->first();
            $this->blockedUntil=max($now+60,(int)$h->backoff_until);
            if ($now<(int)$g->window_at || $now<(int)$h->window_at || $h->lease_until>$now || $h->backoff_until>$now) { return false; }
            $gu=$now>=$g->window_at+60?0:(int)$g->used; $hu=$now>=$h->window_at+60?0:(int)$h->used;
            if ($gu>=30 || $hu>=6) { return false; }
            $claim=bin2hex(random_bytes(32));
            DB::table(self::TABLE)->where('scope','global')->update(['used'=>$gu+1,'window_at'=>$now>=$g->window_at+60?$now:$g->window_at]);
            DB::table(self::TABLE)->where('scope',$host)->update(['used'=>$hu+1,'window_at'=>$now>=$h->window_at+60?$now:$h->window_at,'claim'=>$claim,'lease_until'=>$now+120]);
            $this->claims[$host]=$claim; return true;
        });
    }
    public function complete(string $url,?array $response,int $now): void
    {
        $host=hash('sha256',strtolower(parse_url($url,PHP_URL_HOST))); $claim=$this->claims[$host]??null;
        if ($claim===null) { return; } unset($this->claims[$host]);
        DB::transaction(function()use($host,$claim,$response,$now):void {
            $q=DB::table(self::TABLE)->where('scope',$host); $h=$q->lockForUpdate()->first();
            if (!$h || $h->claim!==$claim || $now>=(int)$h->lease_until) { return; }
            $deferred=$response['deferred']??false; $status=$response['status']??null;
            $failed=!$deferred && (($response['failed']??false) || !in_array($status,[200,404],true));
            $failures=$deferred?(int)$h->failures:($failed?min(30,(int)$h->failures+1):0);
            $retry=$status===429?self::retryAfter($response['retry_after']??null,$now):0;
            $until=max($retry,$failed && $failures>=5?$now+3600:0);
            $this->blockedUntil=max($this->blockedUntil,$until);
            $q->update(['claim'=>null,'lease_until'=>null,'failures'=>$failures,'backoff_until'=>$until]);
        });
    }
    public static function retryAfter(mixed $value,int $now): int
    {
        if (!is_string($value) || strlen($value)>128) { return 0; }
        if (preg_match('/\A[0-9]{1,10}\z/D',$value)) { return $now+min(86400,(int)$value); }
        $date=\DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T',$value,new \DateTimeZone('UTC'));
        $errors=\DateTimeImmutable::getLastErrors();
        return $date && (!$errors || (!$errors['warning_count'] && !$errors['error_count'])) ? min($now+86400,max($now,$date->getTimestamp())) : 0;
    }
}
