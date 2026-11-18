<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Turns a checked-out registration into an unsent, unpaid renewal with its
// route, calendar and expiry anchor, so closing it and returning its receipts validate as for a billed renewal.
declare(strict_types=1);
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
function receiptRenewalSeed(Operation $op,string $base='2027-10-18'): void
{
    $d=$op->domain()->firstOrFail();$s=$d->service()->firstOrFail();$invoice=\App\Models\Invoice::findOrFail($op->invoice_id);
    $anchor=new DateTimeImmutable($base);$term=(int)$op->term;
    DB::table($d->getTable())->where('id',$d->id)->update(['provider_expires_at'=>$anchor->format('Y-m-d')]);
    DB::table('invoice_items')->where('id',$op->invoice_item_id)->update(['reference_type'=>null,'reference_id'=>null,'price'=>$op->amount,'quantity'=>1]);
    $policy=['renewal'=>['user_id'=>(int)$s->user_id,'order_id'=>(int)$s->order_id,'product_id'=>(int)$s->product_id,'annual_plan_id'=>(int)$s->plan_id,'term'=>$term],
        'calendar'=>['due'=>$invoice->due_at?->format('Y-m-d')]];
    DB::table($op->getTable())->where('id',$op->id)->update(['action'=>'renew','state'=>'awaiting_payment','safe_code'=>null,
        'attempt'=>null,'cost'=>null,'funding'=>null,'policy'=>Crypt::encryptString(json_encode($policy,JSON_THROW_ON_ERROR)),
        'base_expires_at'=>$anchor->format('Y-m-d'),'expected_expires_at'=>$anchor->modify('+'.$term.' years')->format('Y-m-d'),
        'provider_order_id'=>null,'started_at'=>null,'request_fingerprint'=>null,'confirmed_expires_at'=>null,'lease_expires_at'=>null,'completed_at'=>null]);
}
