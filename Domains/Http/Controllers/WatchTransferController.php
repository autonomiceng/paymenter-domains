<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{CheckoutEligibility,WatchTransfer,WatchTransferStrings};
final class WatchTransferController
{
    public function __construct(private WatchTransfer $transfers,private CheckoutEligibility $eligibility,private Clock $clock) {}
    public function show(Request $request,int $id)
    {
        $current=$this->transfers->current($request->user(),$id,$this->clock->now()->getTimestamp());
        $action=$this->transfers->cartAction($request->user(),$current['watch']->canonical_name);
        return $this->page($request,$id,$current,$action==='transfer'?'in_cart':($action==='register'?'unavailable':'help'));
    }
    public function check(Request $request,int $id)
    {
        FormKeys::verify($request,'watch-transfer:'.$id);FormKeys::consume($request,'watch-transfer:'.$id);
        $current=$this->transfers->current($request->user(),$id,$this->clock->now()->getTimestamp());
        $review=$request->session()->pull('domains.watch-transfer.'.$id);
        $action=$this->transfers->cartAction($request->user(),$current['watch']->canonical_name);
        if ($action!==null) { return $this->page($request,$id,$current,$action==='transfer'?'in_cart':'unavailable'); }
        $confirm=$request->input('step')==='confirm';
        if ($request->input('revision')!==(string)$current['watch']->revision || ($confirm && (!is_array($review)
            || ($review['owner']??null)!==(int)$request->user()->id || ($review['stamp']??null)!==$current['stamp']
            || ($review['expires']??0)<=$this->clock->now()->getTimestamp()))) { return $this->page($request,$id,$current,'changed'); }
        if ($confirm && $request->input('authority')!=='1') { $request->session()->put('domains.watch-transfer.'.$id,$review);return $this->page($request,$id,$current,'eligible'); }
        if (!$current['offer'] || RateLimiter::hit('domains.watch-transfer:'.$request->user()->id,60)>10) { return $this->page($request,$id,$current,'unavailable'); }
        try {
            $this->eligibility->observe($current['watch']->canonical_name,$current['offer']['selection'],['watch'=>(int)$id,'owner'=>(int)$request->user()->id],true);
        } catch (\Throwable $e) { logger()->warning('Watch transfer check refused.',['exception_class'=>$e::class]);return $this->page($request,$id,$current,'unavailable'); }
        // Recheck owner, revision, reservation, price and seller after unlocked external reads.
        $after=$this->transfers->current($request->user(),$id,$this->clock->now()->getTimestamp());
        if ($after['stamp']!==$current['stamp'] || !$after['offer']) { return $this->page($request,$id,$after,'changed'); }
        if ($confirm) {
            try { return app(StorefrontController::class)->transfer($request,$after['watch']->canonical_name,$this->eligibility,
                function()use($request,$id,$after):void {
                    $latest=$this->transfers->current($request->user(),$id,$this->clock->now()->getTimestamp());
                    if (!$latest['offer'] || $latest['stamp']!==$after['stamp']) { throw new \DomainException('Transfer details changed.'); }
                    if ($this->transfers->cartAction($request->user(),$latest['watch']->canonical_name)!==null) { throw new \DomainException('Domain already in cart.'); }
                }); } catch (\Throwable $e) {
                    logger()->warning('Watch transfer handoff refused.',['exception_class'=>$e::class]);
                    $action=$this->transfers->cartAction($request->user(),$after['watch']->canonical_name);
                    $state=($e instanceof \OverflowException || $e instanceof \App\Exceptions\DisplayException)?'unavailable':
                        (($e instanceof \DomainException && $action!==null)?($action==='transfer'?'in_cart':'unavailable'):'changed');
                    return $this->page($request,$id,$after,$state);
                }
        }
        $request->session()->put('domains.watch-transfer.'.$id,['owner'=>(int)$request->user()->id,'stamp'=>$after['stamp'],
            'expires'=>$this->clock->now()->getTimestamp()+300]);
        return $this->page($request,$id,$after,'eligible');
    }
    private function page(Request $request,int $id,array $current,string $state)
    {
        return view('domains::watch-transfer',['id'=>$id,'name'=>$current['watch']->canonical_name,'revision'=>(string)$current['watch']->revision,
            'state'=>$state,'canCheck'=>(bool)$current['offer'],'price'=>$state==='eligible'?$current['offer']['price']:null,
            'renew'=>$state==='eligible'?$current['offer']['renew']:null,'strings'=>WatchTransferStrings::all(),
            'key'=>FormKeys::issue($request,'watch-transfer:'.$id)]);
    }
}
