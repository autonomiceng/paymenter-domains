<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,CanonicalName};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
/** Native encrypted settings join existing account locks, review stamps and retained upgrades. */
final class LifecyclePolicies
{
    public static function key(string $tld): string
    {CanonicalName::parse('example.'.$tld,[$tld]);return 'domains_lifecycle.'.$tld;}
    public static function read(AccountBinding $binding,string $tld): ?array
    {
        try{
            $a=Account::where('server_id',$binding->serverId)->firstOrFail();$binding->requireMatch(CatalogueAcceptances::binding($a));
            if(!Tld::where(['server_id'=>$binding->serverId,'tld'=>$tld,'registrar_key'=>$binding->registrarKey,'account_fingerprint'=>$binding->fingerprint])->exists()){return null;}
            $s=Server::whereKey($binding->serverId)->where('extension','Domains')->firstOrFail();
            $rows=$s->settings()->where('key',self::key($tld))->get();
            if($rows->count()!==1||!$rows[0]->encrypted){return null;}
            $v=json_decode($rows[0]->value,true,8,JSON_THROW_ON_ERROR);
            if(!is_array($v)||($v['binding']??null)!==$binding->fingerprint||($v['tld']??null)!==$tld||!is_array($v['facts']??null)){return null;}
            return LifecycleFacts::validate($v['facts']);
        }catch(\Throwable){return null;}
    }
    public static function until(AccountBinding $binding,string $tld,string $action,\DateTimeImmutable $now): ?\DateTimeImmutable
    { $v=self::read($binding,$tld);return $v===null?null:LifecycleFacts::until($v,$action,$now); }
    /** Policy eligibility only. Owner checkout and provider writes require separate acceptance. */
    public static function capabilities(AccountBinding $binding,string $tld,\DateTimeImmutable $now,bool $restoreAccepted=false): array
    {
        return ['grace'=>self::until($binding,$tld,'grace',$now)!==null,
            'restore'=>$restoreAccepted&&self::until($binding,$tld,'restore',$now)!==null];
    }
    public static function save(StaffSettingsReview $r,array $data,\DateTimeImmutable $now): void
    {
        $r->local(function()use($r,$data,$now):void {
            if(array_diff(array_keys($data),[...LifecycleFacts::FIELDS,'verified','review_key','reason'])){throw new \DomainException('Invalid lifecycle input.');}
            if(($data['verified']??false)!==true){throw new \DomainException('Independent lifecycle verification required.');}
            $s=Server::whereKey($r->serverId)->lockForUpdate()->firstOrFail();
            $a=Account::where('server_id',$r->serverId)->lockForUpdate()->firstOrFail();$binding=CatalogueAcceptances::binding($a);
            $t=Tld::where(['id'=>$r->tldId??0,'server_id'=>$r->serverId,'registrar_key'=>$binding->registrarKey,'account_fingerprint'=>$binding->fingerprint])->lockForUpdate()->firstOrFail();
            $v=array_intersect_key($data,array_flip(LifecycleFacts::FIELDS));
            foreach(['grace_days','restore_days'] as $key){
                $value=$v[$key]??null;
                if((is_int($value)||is_float($value)||is_string($value))&&($days=filter_var($value,FILTER_VALIDATE_INT))!==false){$v[$key]=$days;}
            }
            $v=LifecycleFacts::validate($v);
            foreach(['verified_at','grace_verified_at','restore_verified_at'] as $key){
                if(new \DateTimeImmutable($v[$key])>$now){throw new \DomainException('Lifecycle verification cannot be in the future.');}
            }
            $rows=$s->settings()->where('key',self::key($t->tld))->lockForUpdate()->get();
            if($rows->count()>1){throw new \DomainException('Conflicting lifecycle settings require repair.');}
            $row=$rows->first()??$s->settings()->make(['key'=>self::key($t->tld)]);
            $row->encrypted=true;$row->type='string';
            $row->value=json_encode(['binding'=>$binding->fingerprint,'tld'=>$t->tld,'facts'=>$v],JSON_THROW_ON_ERROR);$row->save();
        });
    }
    public const GRACE_HELP='Days after expiry when the domain can still be renewed at the normal price.';
    public const REDEMPTION_HELP='Period after grace when the domain can only be restored, for a restore fee.';
    /** Staff rows for the TLD page: label => value (null is Missing), sub (the cause when Missing), help and whether Edit applies. */
    public static function facts(Tld $t,\DateTimeImmutable $now,bool $costs): array
    {
        try{$a=Account::where('server_id',$t->server_id)->firstOrFail();$b=CatalogueAcceptances::binding($a);$v=self::read($b,$t->tld);}
        catch(\Throwable){$v=null;}
        $row=static fn(?string $value,?string $sub,?string $help=null,bool $edit=false)=>compact('value','sub','help','edit');
        if($v===null){return ['Renewal grace period'=>$row(null,'No lifecycle policy saved',self::GRACE_HELP,true),
            'Redemption period'=>$row(null,'No lifecycle policy saved',self::REDEMPTION_HELP,true)];}
        $period=static function(string $action)use($v,$now,$row):array {
            $help=$action==='grace'?self::GRACE_HELP:self::REDEMPTION_HELP;
            if($v['conflicting']){return $row(null,'Sources disagree',$help,true);}
            if(!$v[$action.'_supported']||($action==='grace'&&$v['auto_renew_only'])){return $row('Not supported',null,$help,true);}
            if($v[$action.'_currency']!=='USD'){return $row(null,'Fee not in USD',$help,true);}
            if($v[$action.'_days']!==0&&LifecycleFacts::until($v,$action,$now)===null){return $row(null,'Policy out of date',$help,true);}
            $days=(int)$v[$action.'_days'];
            return $row($days.' '.($days===1?'day':'days'),'Updated '.(new \DateTimeImmutable($v['verified_at']))->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y'),$help,true);
        };
        $out=['Renewal grace period'=>$period('grace'),'Redemption period'=>$period('restore')];
        if($v['auto_renew_only']){$out['Registry auto-renew only']=$row('Yes',null);}
        if($costs){
            $money=static fn(string $amount,string $currency)=>$currency.' '.preg_replace('/(\.\d{2}\d*?)0+$/D','$1',PriceCatalogue::cost($amount));
            if(PricingRule::units($v['grace_extra'],4)!==0){$out['Late renewal fee']=$row($money($v['grace_extra'],$v['grace_currency']),null);}
            $out['Restore fee']=$row($money($v['restore_fee'],$v['restore_currency']),null);
        }
        return $out;
    }
}
