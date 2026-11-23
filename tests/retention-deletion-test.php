<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{NativeUserDeletion,UserDeletionSchema};
extract(dnsFixture());$uid=$owner->id;NativeUserDeletion::boot();
$owner->properties()->create(['key'=>'phone','name'=>'Phone','value'=>'+1.2025550100']);
$profileId=DB::table('domains_contact_profiles')->insertGetId(['user_id'=>$uid,'server_id'=>$server->id,'account_fingerprint'=>str_repeat('c',64),'profile_fingerprint'=>str_repeat('d',64),
    'details'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode(['contact'=>['email_address'=>'synthetic@example.com']]))]);
$other->properties()->create(['key'=>'phone','name'=>'Phone','value'=>'+1.2025550100']);
$deleting=$owner->fresh();$deleting->id=$other->id;$deleting->delete();
expect($other->properties()->where('key','phone')->exists(),'Changed in-memory key cannot erase another customer contact');
expect(DB::table(UserDeletionSchema::TABLE)->where('user_id',$uid)->exists(),'Deletion retains the fence for the erased customer');
expect(!DB::table(UserDeletionSchema::TABLE)->where('user_id',$other->id)->exists(),'Changed in-memory key cannot fence another customer');
DB::transaction(fn()=>NativeUserDeletion::requireOwner((int)$other->id));
expect(!DB::table('domains_contact_profiles')->where('id',$profileId)->exists(),'Customer deletion removes owned contact details');
expect(!DB::table('properties')->where('model_type',App\Models\User::class)->where('model_id',$uid)->where('key','phone')->exists(),'Customer deletion removes contact properties');
DB::beginTransaction();
$other->fresh()->delete();
DB::rollBack();
expect($other->properties()->where('key','phone')->exists(),'Rollback restores contact details');
// Core invoice deletion cascades cannot remove retained money.
$invoice=App\Models\Invoice::create(['user_id'=>$other->id,'currency_code'=>'USD','status'=>'paid','due_at'=>now()]);
try{$other->fresh()->delete();expect(false,'Financial owner deletion must refuse');}catch(DomainException $e){same(NativeUserDeletion::RETAINED,$e->getMessage(),'Financial evidence has the existing refusal');}
expect(App\Models\Invoice::whereKey($invoice->id)->exists(),'Invoice survives deletion attempt');
expect($other->properties()->where('key','phone')->exists(),'Refusal preserves contact details');
echo "Watch-only deletion, contact removal, rollback and native invoice retention passed.\n";

$nativeSuccessful=true;
