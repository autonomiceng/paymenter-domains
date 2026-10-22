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
    public static function facts(Tld $t,\DateTimeImmutable $now,bool $costs): array
    {
        try{$a=Account::where('server_id',$t->server_id)->firstOrFail();$b=CatalogueAcceptances::binding($a);$v=self::read($b,$t->tld);}
        catch(\Throwable){$v=null;}
        if($v===null){return ['Lifecycle facts'=>'Not verified · grace renewal and restore unavailable'];}
        $out=['Grace renewal policy'=>LifecycleFacts::until($v,'grace',$now)?$v['grace_days'].' days · verified':'Unavailable · review support, age, currency and conflicts',
            'Restore policy'=>LifecycleFacts::until($v,'restore',$now)?$v['restore_days'].' days · verified; execution disabled':'Unavailable · review support, age, currency and conflicts',
            'Auto-renew only'=>$v['auto_renew_only']?'Yes':'No','Conflicting evidence'=>$v['conflicting']?'Yes · unavailable':'No',
            'Policy verified'=>$v['verified_at'],'Policy source'=>$v['source']];
        if($costs){foreach(['grace'=>'grace_extra','restore'=>'restore_fee'] as $prefix=>$amount){
            $out[$prefix==='grace'?'Registrar extra grace charge':'Registrar restore fee']=$v[$amount].' '.$v[$prefix.'_currency'].' · verified '.$v[$prefix.'_verified_at'].' · '.$v[$prefix.'_source'];
        }}
        return $out;
    }
}
