<?php
// Offline gateway port fixture; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
class MoneyReceiptGateway extends \App\Classes\Extension\Gateway {
    public static array $returns=[];public static bool $lost=true;public static int $sends=0;public static ?\Closure $onAllocated=null;
    public static string $outcome='refunded';public static ?\Closure $onStatus=null;
    public function pay(\App\Models\Invoice $invoice,$total){return '';}
    public function receiptReturnStatus(\App\Models\Invoice $invoice,string $receipt,string $key):?array {
        $result=self::$returns[$key==='excess'?$receipt.':excess':$key]??null;
        $next=self::$onStatus;self::$onStatus=null;$next?->__invoke();return $result;
    }
    public function allocateReceiptReturn(\App\Models\Invoice $invoice,string $receipt,string $key,int $minor):array {
        expect($key!=='excess','Domains must never allocate the gateway-owned closed return');
        same(0,\Illuminate\Support\Facades\DB::transactionLevel(),'Gateway I/O follows the Domains transaction');
        if(!isset(self::$returns[$key])){
            self::$sends++;self::$returns[$key]=['state'=>self::$outcome,'amount_minor'=>$minor,'refund_id'=>'synthetic-return','key'=>'fixture-return:'.$key];
            if(self::$outcome==='refunded'){self::complete($invoice,$key);}
            $next=self::$onAllocated;self::$onAllocated=null;$next?->__invoke();
        }
        same($minor,self::$returns[$key]['amount_minor'],'Allocation key freezes exact amount');
        if(self::$lost){self::$lost=false;throw new RuntimeException('Synthetic reply lost after acceptance');}
        return self::$returns[$key];
    }
    public static function automatic(\App\Models\Invoice $invoice,string $receipt,int $minor,string $state='refunded'):void {
        $key=$receipt.':excess';self::$sends++;
        self::$returns[$key]=['state'=>$state,'amount_minor'=>$minor,'refund_id'=>'synthetic-auto-return','key'=>'fixture-return:'.$key];
        if($state==='refunded'){self::complete($invoice,$key);}
    }
    public static function credit(\App\Models\Invoice $invoice,string $key):void {
        if(self::$returns[$key]['state']==='credited'){return;}
        \Illuminate\Support\Facades\DB::transaction(function()use($invoice,$key):void {
            $credit=\App\Models\Credit::firstOrCreate(['user_id'=>$invoice->user_id,'currency_code'=>$invoice->currency_code],['amount'=>'0.00']);
            $credit->update(['amount'=>\Paymenter\Extensions\Servers\Domains\Services\PricingRule::decimal(\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($credit->amount)+self::$returns[$key]['amount_minor'])]);
            $row=\App\Models\InvoiceTransaction::where('transaction_id',self::$returns[$key]['key'])->lockForUpdate()->firstOrFail();
            \Illuminate\Support\Facades\DB::table('invoice_transactions')->where('id',$row->id)->update(['is_credit_transaction'=>true]);
        });
        self::$returns[$key]['state']='credited';
    }
    public static function complete(\App\Models\Invoice $invoice,string $key):void {
        if(!\App\Models\InvoiceTransaction::where('transaction_id',self::$returns[$key]['key'])->exists()){
            \App\Models\InvoiceTransaction::create(['invoice_id'=>$invoice->id,'amount'=>\Paymenter\Extensions\Servers\Domains\Services\PricingRule::decimal(-self::$returns[$key]['amount_minor']),'fee'=>'0.00','status'=>'succeeded','transaction_id'=>self::$returns[$key]['key']]);
        }
        self::$returns[$key]['state']='refunded';
    }
}
class_alias(MoneyReceiptGateway::class,'Paymenter\\Extensions\\Gateways\\MoneyReceipt\\MoneyReceipt');
