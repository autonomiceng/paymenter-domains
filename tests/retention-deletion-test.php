<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\NativeUserDeletion;
$f=noticeFixture();extract($f,EXTR_SKIP);NativeUserDeletion::boot();
$owner->properties()->create(['key'=>'phone','name'=>'Phone','value'=>'+1.6502530000']);
$profileId=DB::table('domains_contact_profiles')->insertGetId(['user_id'=>$uid,'server_id'=>$server->id,'account_fingerprint'=>str_repeat('c',64),'profile_fingerprint'=>str_repeat('d',64),
    'details'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode(['contact'=>['email_address'=>'synthetic@example.com']]))]);
$other->properties()->create(['key'=>'phone','name'=>'Phone','value'=>'+1.6502530000']);
$unrelated=(array)$watch->getRawOriginal();unset($unrelated['id']);$unrelated['user_id']=$other->id;$unrelated['canonical_name']='unrelated.example.com';
$unrelatedWatch=DB::table('domains_watch_names')->insertGetId($unrelated);
$deleting=$owner->fresh();$deleting->id=$other->id;$deleting->delete();
expect(DB::table('domains_watch_names')->where('id',$unrelatedWatch)->exists(),'Changed in-memory key cannot erase another customer watch');
expect($other->properties()->where('key','phone')->exists(),'Changed in-memory key cannot erase another customer contact');
expect(!DB::table('domains_contact_profiles')->where('id',$profileId)->exists(),'Customer deletion removes owned contact details');
expect(!DB::table('domains_watch_names')->where('id',$watch->id)->exists(),'Customer deletion removes watch names');
expect(!DB::table('domains_watch_sources')->where('user_id',$uid)->exists(),'Customer deletion removes watch sources');
expect(!DB::table('properties')->where('model_type',App\Models\User::class)->where('model_id',$uid)->where('key','phone')->exists(),'Customer deletion removes contact properties');
// A native outer rollback restores personal data together with watch work and the deletion fence.
$ownerRow=$other->fresh();
$attrs=(array)$watch->getRawOriginal();unset($attrs['id']);$attrs['user_id']=$other->id;$attrs['canonical_name']='other.example.com';
$otherWatch=DB::table('domains_watch_names')->insertGetId($attrs);

DB::beginTransaction();$other->fresh()->delete();expect(!DB::table('domains_watch_names')->where('id',$otherWatch)->exists(),'Outer delete removes watch');DB::rollBack();
expect(DB::table('domains_watch_names')->where('id',$otherWatch)->exists(),'Rollback restores watch');
expect($other->properties()->where('key','phone')->exists(),'Rollback restores contact details');
// Core invoice deletion cascades cannot remove retained money.
$invoice=App\Models\Invoice::create(['user_id'=>$other->id,'currency_code'=>'USD','status'=>'paid','due_at'=>now()]);
try{$other->fresh()->delete();expect(false,'Financial owner deletion must refuse');}catch(DomainException $e){same(NativeUserDeletion::RETAINED,$e->getMessage(),'Financial evidence has the existing refusal');}
expect(App\Models\Invoice::whereKey($invoice->id)->exists(),'Invoice survives deletion attempt');
expect(DB::table('domains_watch_names')->where('id',$otherWatch)->exists(),'Refusal makes no partial privacy changes');
echo "Watch-only deletion, contact removal, rollback and native invoice retention passed.\n";

$nativeSuccessful=true;
