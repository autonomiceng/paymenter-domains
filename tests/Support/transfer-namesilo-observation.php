<?php
// Synthetic transfer read replies; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, TransportResponse};
$observe=(object)['name'=>'','fault'=>'','status'=>'Transfer Completed','calls'=>[],'during'=>null,'beforeRead'=>null,'expiration'=>['expiration'=>'2028-01-01']];
$wire=new class($observe) implements DeadlineTransport {
    public function __construct(private $s) {}
    public function send($e,$c,$r,#[SensitiveParameter] string $k): TransportResponse { throw new LogicException; }
    public function sendWithin($e,$c,$r,#[SensitiveParameter] string $k,int $t): TransportResponse {
        $s=$this->s; if ($s->beforeRead) { ($s->beforeRead)(); } expect($t>0 && $t<=25000,'Read deadline bounded'); $s=$this->s; $s->calls[]=$r->command;
        if ($s->during) { $hook=$s->during; $s->during=null; $hook(); }
        $row=['description'=>$s->name.' - transfer','years_qty'=>'1','status'=>'Processed','subtotal'=>'99.00'];
        if ($s->fault==='credited') { $row['credited_amount']='0.00'; }
        if ($s->fault==='unknowncharge') { $row['status']='Pending'; }
        $reply=['code'=>300,'detail'=>'synthetic']+match($r->command) {
            'checkTransferStatus'=>['date'=>'2026-10-18','status'=>$s->status,'message'=>'synthetic']+$s->expiration,
            'getDomainInfo'=>['created'=>'2020-01-01','expires'=>$s->fault==='short'?'2027-01-01':'2028-01-01',
                'status'=>$s->fault==='inactive'?'active':'Active','auto_renew'=>$s->fault==='auto'?'Yes':'No',
                'private'=>$s->fault==='privacy'?'Yes':'No','locked'=>'Yes',
                ...($s->fault==='missing_nameservers'?[]:['nameservers'=>[['nameserver'=>'ns1.provider.example'],['nameserver'=>'ns2.provider.example']]])],
            'listOrders'=>['order'=>in_array($s->fault,['nocharge','reject','rejectowned'],true)?[]:[['order_number'=>'9001']]]+($s->fault==='paged'?['pager'=>['page'=>1]]:[]),
            'orderDetails'=>['order_number'=>'9001','order_date'=>$s->fault==='late'?'2026-10-18 05:10:00':'2026-10-18 05:00:01',
                'total'=>'99.00','order_details'=>$s->fault==='duplicate'?[$row,$row]:[$row]],
            'getPrices'=>['com'=>['registration'=>'8.95','transfer'=>'8.95','renew'=>'8.95']],
            'getAccountBalance'=>['balance'=>'100.00'],
            default=>throw new LogicException('Unexpected read'),
        };
        if ($r->command==='getDomainInfo' && in_array($s->fault,['owner','reject','rejectcharge'],true)) { $reply=['code'=>200,'detail'=>'synthetic']; }
        return new TransportResponse(200,json_encode(['request'=>['operation'=>$r->command],'reply'=>$reply]));
    }
};
