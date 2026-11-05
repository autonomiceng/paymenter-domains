<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Append-only account authorization. The native user lock serializes checkout, billing and changes. */
final class ComplimentaryAccounts
{
    public const LABEL='Complimentary (account setting)';
    public static function current(int $user):?array
    {
        if(!Schema::hasTable('domains_complimentary_accounts')){return null;}
        $row=DB::table('domains_complimentary_accounts')->where('user_id',$user)->orderByDesc('id')->first();
        return $row?['id'=>(int)$row->id,'user_id'=>(int)$row->user_id,'enabled'=>(bool)$row->enabled,'actor'=>(int)$row->actor_id,'reason'=>$row->reason,'at'=>$row->created_at]:null;
    }
    public static function set(int $user,User $actor,bool $enabled,string $reason,Clock $clock):void
    {
        OperationClaims::outside();$reason=trim($reason);
        if($reason===''||strlen($reason)>500){throw new \DomainException('A reason is required.');}
        DB::transaction(static function()use($user,$actor,$enabled,$reason,$clock):void {
            $users=User::whereIn('id',[$user,$actor->id])->orderBy('id')->lockForUpdate()->get();
            $staff=$users->firstWhere('id',$actor->id);$staff?->setRelation('role',$staff->role()->lockForUpdate()->first());
            if(!$users->contains('id',$user)||!$staff?->hasPermission('admin.domains.fund')){throw new \DomainException('Domain funding permission required.');}
            if((self::current($user)['enabled']??false)===$enabled){return;}
            DB::table('domains_complimentary_accounts')->insert(['user_id'=>$user,'actor_id'=>$staff->id,'enabled'=>$enabled,'reason'=>$reason,'created_at'=>$clock->now()]);
            // Dedicated audited transition. No core service price or funded operation is rewritten.
            Domain::where('user_id',$user)->update(['price_mode'=>$enabled?'complimentary':'standard']);
        });
    }
    public static function authorization(int $user):?array
    {
        $a=self::current($user);return ($a['enabled']??false)?$a:null;
    }
    /** Issued grants survive switch-off; prove their exact retained audit row, never a caller boolean. */
    public static function require(array $a,int $user):void
    {
        $row=DB::table('domains_complimentary_accounts')->where('id',$a['id']??0)->where('user_id',$user)->where('enabled',true)->first();
        if(!$row||$a!==['id'=>(int)$row->id,'user_id'=>(int)$row->user_id,'enabled'=>true,'actor'=>(int)$row->actor_id,'reason'=>$row->reason,'at'=>$row->created_at]){throw new \DomainException('Complimentary account authorization changed.');}
    }
}
