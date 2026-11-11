<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-dns.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{HistoryRetention,NativeUserDeletion};
extract(dnsFixture());NativeUserDeletion::boot();
$invoice=App\Models\Invoice::create(['user_id'=>$owner->id,'currency_code'=>'USD','status'=>'paid','due_at'=>now()]);
try{$owner->fresh()->delete();}catch(DomainException $e){same(NativeUserDeletion::RETAINED,$e->getMessage(),'Existing financial refusal');}
expect(App\Models\Invoice::whereKey($invoice->id)->exists(),'Customer deletion cannot cascade retained invoices');
expect(App\Models\User::whereKey($owner->id)->exists(),'Financial invoice still has its native owner');
$rows=DB::table('invoices')->get()->toJson();
if(is_file(dirname(__DIR__).'/Domains/Services/HistoryRetention.php')){HistoryRetention::tick(strtotime('2036-11-11 UTC'));}
same($rows,DB::table('invoices')->get()->toJson(),'Even after seven years money is never automatically deleted');
echo "Native invoice cascade is refused and money does not expire automatically.\n";
$nativeSuccessful=true;
