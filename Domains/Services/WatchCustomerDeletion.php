<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
use Illuminate\Support\Facades\DB;
/** Caller has fenced the native owner and proved that no financial or registrar-operation evidence can cascade. */
final class WatchCustomerDeletion
{
    public static function erase(int $owner): void
    {
        if(DB::transactionLevel()<1){throw new \LogicException('Customer deletion transaction required.');}
        // Work/notice writers first lock this owner. Staff watch edits lock the watch before writing audit.
        $watches=DB::table('domains_watch_names')->where('user_id',$owner)->orderBy('id')->lockForUpdate()->pluck('id');
        DB::table('domains_watch_sources')->where('user_id',$owner)->orderBy('id')->lockForUpdate()->get();
        DB::table('domains_watch_notices')->where('user_id',$owner)->orderBy('id')->lockForUpdate()->get();
        $deliveries=DB::table('domains_watch_deliveries')->where('user_id',$owner)->orderBy('id')->lockForUpdate()->pluck('id');
        DB::table('domains_watch_mail_days')->whereIn('delivery_id',$deliveries)->delete();
        foreach(['registry','watch_dns'] as $family){
            $table=HistoryRetention::TABLES[$family];
            DB::table('domains_public_evidence_refs')->where('family',$family)->whereIn('observation_id',DB::table($table)->select('id')->where('user_id',$owner))->delete();
        }
        foreach(['registry','watch_dns','notices','deliveries','watch_audit'] as $family){
            $table=HistoryRetention::TABLES[$family];
            DB::table(RetentionSchema::MARKS)->where('family',$family)->whereIn('source_id',DB::table($table)->select('id')->where('user_id',$owner))->delete();
            DB::table($table)->where('user_id',$owner)->delete();
        }
        DB::table('domains_watch_work')->whereIn('watch_id',$watches)->delete();
        foreach(['domains_watch_notice_state','domains_watch_preferences','domains_watch_sources','domains_watch_names','domains_contact_profiles'] as $table){DB::table($table)->where('user_id',$owner)->delete();}
        DB::table(RetentionSchema::COLD)->where('user_id',$owner)->delete();
        // Opaque staff claims and replay digests retain no contact details and keep their replay protection.
        User::find($owner)?->properties()->whereIn('key',[...ContactDetails::REQUIRED_PROPERTIES,'company_name','address2'])->delete();
    }
}
