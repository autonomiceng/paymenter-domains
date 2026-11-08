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
    public const LABEL='Complimentary';
    /** $lock requires the caller's existing native user lock; use a current read inside that transaction. */
    public static function current(int $user,bool $lock=false):?array
    {
        if($lock&&DB::transactionLevel()<1){throw new \LogicException('Current funding requires the owner transaction.');}
        if(!Schema::hasTable('domains_complimentary_accounts')){return null;}
        $query=DB::table('domains_complimentary_accounts')->where('user_id',$user)->orderByDesc('id');
        $row=($lock?$query->lockForUpdate():$query)->first();
        return $row?['id'=>(int)$row->id,'user_id'=>(int)$row->user_id,'enabled'=>(bool)$row->enabled,'actor'=>(int)$row->actor_id,'reason'=>$row->reason,'at'=>$row->created_at]:null;
    }
    /** $seen is the setting row id the staff confirm showed (0 when never set); a different latest row refuses, so no one overwrites a change they did not see. */
    public static function set(int $user,User $actor,bool $enabled,string $reason,Clock $clock,?int $seen=null):void
    {
        OperationClaims::outside();$reason=trim($reason);
        if($reason===''||mb_strlen($reason)>500){throw new \DomainException('A reason is required.');}
        DB::transaction(static function()use($user,$actor,$enabled,$reason,$clock,$seen):void {
            $users=User::whereIn('id',[$user,$actor->id])->orderBy('id')->lockForUpdate()->get();
            $staff=$users->firstWhere('id',$actor->id);$staff?->setRelation('role',$staff->role()->lockForUpdate()->first());
            if(!$users->contains('id',$user)||!$staff?->hasPermission('admin.domains.fund')){throw new \DomainException('Domain funding permission required.');}
            if($seen!==null&&(self::current($user)['id']??0)!==$seen){throw new ComplimentaryAccountChanged('Complimentary domains changed after this opened');}
            if((self::current($user)['enabled']??false)===$enabled){return;}
            DB::table('domains_complimentary_accounts')->insert(['user_id'=>$user,'actor_id'=>$staff->id,'enabled'=>$enabled,'reason'=>$reason,'created_at'=>$clock->now()]);
            self::audit($users->firstWhere('id',$user),$enabled,$reason);
            // Dedicated audited transition. No core service price or funded operation is rewritten.
            Domain::where('user_id',$user)->update(['price_mode'=>$enabled?'complimentary':'standard']);
        });
    }
    public static function nativeAuditAvailable():bool
    {return config('audit.enabled',true)&&class_exists(\OwenIt\Auditing\Events\AuditCustom::class)&&\Illuminate\Support\Facades\Event::hasListeners(\OwenIt\Auditing\Events\AuditCustom::class);}
    /** The same change in the customer's native Audit Log, through the auditing package's public custom event, as core's extension actions do. */
    private static function audit(User $customer,bool $enabled,string $reason):void
    {
        if(!self::nativeAuditAvailable()||!$customer instanceof \OwenIt\Auditing\Contracts\Auditable){return;}
        // The stock log prints "<staff> <event> User (#id)" and lists the values under it, so the reason stays whole and the header stays short.
        $customer->auditEvent=($enabled?'turned on':'turned off').' complimentary domains for';$customer->isCustomEvent=true;
        $customer->auditCustomOld=[];$customer->auditCustomNew=['reason'=>$reason];
        try{\Illuminate\Support\Facades\Event::dispatch(new \OwenIt\Auditing\Events\AuditCustom($customer));}
        finally{$customer->isCustomEvent=false;$customer->auditCustomNew=null;$customer->auditCustomOld=null;}
    }
    public static function authorization(int $user,bool $lock=false):?array
    {
        $a=self::current($user,$lock);return ($a['enabled']??false)?$a:null;
    }
    /** Issued grants survive switch-off; prove their exact retained audit row, never a caller boolean. */
    public static function require(array $a,int $user):void
    {
        $row=DB::table('domains_complimentary_accounts')->where('id',$a['id']??0)->where('user_id',$user)->where('enabled',true)->first();
        if(!$row||$a!==['id'=>(int)$row->id,'user_id'=>(int)$row->user_id,'enabled'=>true,'actor'=>(int)$row->actor_id,'reason'=>$row->reason,'at'=>$row->created_at]){throw new \DomainException('Complimentary account authorization changed.');}
    }
}
