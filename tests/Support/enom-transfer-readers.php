<?php
// Offline readers shared by boot and transfer scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Illuminate\Support\Facades\DB;
function enomTransferReaders(): array
{
    static $readers;
    if($readers!==null){return $readers;}
$clock=new class implements Clock {public DateTimeImmutable $at;public function __construct(){$this->at=new DateTimeImmutable('2026-10-22T00:10:00Z');}public function now():DateTimeImmutable{return $this->at;}};
$rdapMode=(object)['mode'=>'ok','calls'=>0];$rdap=new \Paymenter\Extensions\Servers\Domains\Services\Rdap(static function(string $url,int $timeout)use($rdapMode):array {
    same(0,DB::transactionLevel(),'Transfer RDAP outside locks');expect($timeout>0&&$timeout<=8000,'Bounded RDAP');$rdapMode->calls++;
    if(str_ends_with(parse_url($url,PHP_URL_PATH),'dns.json')){return ['status'=>200,'body'=>json_encode(['services'=>[[['com'],['https://rdap.example.com/']]]])];}
    if($rdapMode->mode==='unknown'){throw new RuntimeException('Synthetic unavailable registry lookup');}
    if($rdapMode->mode==='404'){return ['status'=>404,'body'=>'{"errorCode":404}'];}
    return ['status'=>200,'body'=>json_encode(['objectClassName'=>'domain','ldhName'=>basename(parse_url($url,PHP_URL_PATH)),'status'=>[$rdapMode->mode==='locked'?'client transfer prohibited':'active'],'events'=>[['eventAction'=>'registration','eventDate'=>$rdapMode->mode==='young'?'2026-10-21T00:00:00Z':'2020-01-01T00:00:00Z'],['eventAction'=>'transfer','eventDate'=>in_array($rdapMode->mode,['young','recent'],true)?'2026-10-21T00:00:00Z':'2020-01-01T00:00:00Z'],['eventAction'=>'expiration','eventDate'=>'2027-10-21T00:00:00Z']]])];
});
    return $readers=[$clock,$rdapMode,$rdap];
}
