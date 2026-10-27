<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{WatchNoticeTarget,WatchNoticePreferences,WatchNoticeDelivery,DomainSummaries};
use Paymenter\Extensions\Servers\Domains\Models\WatchNotice;
/** Own first-party detail/preferences boundary; GETs read local evidence only. */
final class WatchNoticeController
{
    public function show(Request $request,string $kind,int $id)
    {
        $owner=(int)$request->user()->id;$summary=(new DomainSummaries())->one($request->user(),$kind,$id);abort_unless($summary,404);
        $t=WatchNoticeTarget::capture($kind,$id,$owner,time(),false);$editable=$t!==null;$t??=['name'=>$summary->name];
        $preferences=WatchNoticePreferences::read($owner,$t['name']);$scope=WatchNoticePreferences::scope($owner,$t['name']);
        $notices=WatchNotice::where('scope',$scope)->where('target_kind',$kind)->where($kind==='watch'?'watch_id':'managed_domain_id',$id)->where('state','open')->orderBy('id')->get()
            ->map(fn($n)=>['kind'=>$n->kind,...WatchNoticeDelivery::data($n)])->all();
        $portal=$editable?$summary->noticeKeys:[];
        $name=$t['name'];$key=$editable?FormKeys::issue($request,'watch-preferences:'.$kind.':'.$id):null;
        return view('domains::watch-notices',compact('summary','editable','name','kind','id','preferences','notices','portal','key'));
    }
    public function save(Request $request,string $kind,int $id)
    {
        $data=$request->validate(['account_revision'=>'required|integer|min:1','target_revision'=>'required|integer|min:1','email'=>'required|boolean','notices'=>'required|boolean']);
        FormKeys::consume($request,'watch-preferences:'.$kind.':'.$id);
        try {
            DB::transaction(function()use($request,$kind,$id,$data):void {
                $owner=(int)$request->user()->id;$t=WatchNoticeTarget::capture($kind,$id,$owner,time());abort_unless($t,404);
                WatchNoticePreferences::save($owner,$t['name'],(int)$data['account_revision'],(int)$data['target_revision'],(bool)$data['email'],(bool)$data['notices']);
            });
        } catch (\DomainException) { return back()->withErrors(['preferences'=>'Couldn’t save. Try again.']); }
        return redirect(WatchNoticeDelivery::url($kind,$id))->with('watch_saved',$data['email']&&$data['notices']?'Alerts turned on.':'Alerts turned off.');
    }
}
