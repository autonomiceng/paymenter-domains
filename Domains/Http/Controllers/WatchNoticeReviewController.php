<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Http\Controllers;
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{WatchNoticeReview,WatchNoticeDelivery};
final class WatchNoticeReviewController
{
    public function show(Request $request,int $delivery)
    {
        $inspection=WatchNoticeReview::inspect($request->user(),$delivery);
        $key=FormKeys::issue($request,'watch-resend:'.$delivery);
        return view('domains::watch-notice-review',compact('inspection','key'));
    }
    public function resend(Request $request,int $delivery,WatchNoticeDelivery $sender)
    {
        $channel=WatchNoticeReview::inspect($request->user(),$delivery)['channel'];FormKeys::consume($request,'watch-resend:'.$delivery);
        $data=$request->validate(['native_id'=>'required|integer|min:1','reason'=>'required|string|min:10|max:500']);
        $key=hash('sha256',$request->input('_request_key'));
        try { $id=WatchNoticeReview::resend($request->user(),$delivery,(int)$data['native_id'],$data['reason'],$key,time(),$sender);
        } catch (\DomainException $e) { return back()->withErrors(['resend'=>$e->getMessage()]); }
        if ($id===null) { return back()->with('watch_review',WatchNoticeReview::resendResult($channel,null)); }
        $sender->handoff($id,time());
        return redirect(url('/domains/check-notices/deliveries/'.$id))->with('watch_review',WatchNoticeReview::resendResult($channel,WatchNoticeReview::inspect($request->user(),$id)['state']));
    }
}
