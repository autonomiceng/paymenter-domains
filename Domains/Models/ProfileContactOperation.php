<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
use Illuminate\Database\Eloquent\Model;
/** Internal retained authority. Provider identity is encrypted; no raw responses or contact payload. */
final class ProfileContactOperation extends Model
{
    protected $table='domains_profile_operations';
    protected $guarded=[];
    protected $hidden=['id','operation_id','user_id','server_id','account_fingerprint','authority_generation','payload_digest','attempt','created_at','updated_at'];
    protected $casts=['operation_id'=>'integer','user_id'=>'integer','server_id'=>'integer','authority_generation'=>'integer','attempt'=>'encrypted:array'];
    private static bool $writing=false;
    public static function writing(\Closure $work): mixed
    {$previous=self::$writing;self::$writing=true;try{return $work();}finally{self::$writing=$previous;}}
    protected static function booted(): void
    {
        static::saving(static function(self $row):void {
            if(!self::$writing){throw new \DomainException('Profile operation authority required.');}
            if(!in_array($row->state,['ready','sealed','created','verified','unsent','uncertain'],true)){throw new \DomainException('Invalid profile state.');}
        });
        static::updating(static function(self $row):void {
            if($row->isDirty(['id','operation_id','user_id','server_id','account_fingerprint','authority_generation','payload_digest','created_at'])){throw new \DomainException('Profile authority is immutable.');}
            $before=$row->getOriginal('attempt');$after=$row->attempt;
            foreach(['claim_key','parent_claim','worker_key','lease_until','dispatch_at','provider_id','received_at','profile_id','execution'] as $key){
                if(array_key_exists($key,$before)&&($after[$key]??null)!==$before[$key]){throw new \DomainException('Profile attempt evidence is immutable.');}
            }
            if(($before['seal']??null)==='sealed'&&($after['seal']??null)!=='sealed'){throw new \DomainException('Profile seal is retained.');}
            $allowed=['ready'=>['ready','sealed'],'sealed'=>['sealed','created','unsent','uncertain'],'created'=>['created','verified'],
                'verified'=>['verified'],'unsent'=>['unsent'],'uncertain'=>['uncertain']];
            // A matching dispatched worker may retain a late receipt; this never restores send authority.
            $late=$row->getOriginal('state')==='uncertain'&&$row->state==='created'
                &&($before['seal']??null)==='sealed'&&isset($before['worker_key'],$before['dispatch_at'])
                &&!isset($before['provider_id'])&&preg_match('/^[1-9][0-9]{0,19}$/D',$after['provider_id']??'')
                &&isset($after['received_at']);
            if(!$late&&!in_array($row->state,$allowed[$row->getOriginal('state')]??[],true)){throw new \DomainException('Profile attempt cannot replay.');}
            if(isset($before['observed_at'])&&(!isset($after['observed_at'])||new \DateTimeImmutable($after['observed_at'])<new \DateTimeImmutable($before['observed_at']))){throw new \DomainException('Profile observation cannot go backwards.');}
        });
        static::deleting(static function():never {throw new \DomainException('Profile operations are retained.');});
    }
}
