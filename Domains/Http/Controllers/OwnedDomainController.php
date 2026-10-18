<?php
// Enom 444f31c owned DomainController paths; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Http\Controllers;
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\OwnedDomains;
/** Owner pages; creation and writes have distinct controllers. Dormant until39. */
final readonly class OwnedDomainController
{
    public const FILTERS=['active'=>['Active',['active']], 'pending'=>['In progress',['registration_pending','transfer_pending']],
        'expiring'=>['Expiring soon',['active']], 'expired'=>['Expired',['expired','grace','redemption']],
        'attention'=>['Needs attention',['registration_rejected','transfer_rejected','needs_review']]];
    public function __construct(private OwnedDomains $owners) {}
    public function index(Request $request)
    {
        $base=Domain::with('service.cancellation')->where('user_id',$request->user()->id)->whereHas('service',fn($q)=>$q->where('user_id',$request->user()->id));
        $raw=$request->query('q');$search=is_string($raw)?trim(substr($raw,0,253)):'';
        $raw=$request->query('status');$filter=is_string($raw)&&isset(self::FILTERS[$raw])?$raw:'';
        $sort=in_array($request->query('sort'),['expiry','-expiry','name','-name'],true)?$request->query('sort'):'expiry';
        $query=clone $base;
        if ($search!=='') { $query->where('domain','like','%'.addcslashes(strtolower($search),'%_\\').'%'); }
        if ($filter!=='') { $query->whereIn('status',self::FILTERS[$filter][1]); }
        $range=[now('UTC')->startOfDay(),now('UTC')->addDays(30)->endOfDay()];
        if ($filter==='expiring') { $query->whereBetween('provider_expires_at',$range); }
        if (str_contains($sort,'expiry')) { $query->orderByRaw('provider_expires_at IS NULL')->orderBy('provider_expires_at',$sort[0]==='-'?'desc':'asc'); }
        $query->orderBy('domain',$sort==='-name'?'desc':'asc');
        $domains=$query->paginate(25)->appends(array_filter(['q'=>$search,'status'=>$filter,'sort'=>$sort],fn($v)=>$v!==''));
        $domains->setCollection($domains->getCollection()->map(fn($d)=>$this->owners->present($d,false)));
        $counts=(clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total','status');
        $filterCounts=[''=>(int)$counts->sum()];
        foreach (self::FILTERS as $key=>[, $statuses]) { $filterCounts[$key]=(int)$counts->only($statuses)->sum(); }
        $filterCounts['expiring']=(clone $base)->where('status','active')->whereBetween('provider_expires_at',$range)->count();
        return view('domains::index',compact('domains','search','filter','sort','filterCounts'));
    }
    public function show(Request $request,int $domain) { return $this->page($request,$domain,'show'); }
    public function setup(Request $request,int $domain) { return $this->page($request,$domain,'setup'); }
    public function code(Request $request,int $domain) { return $this->page($request,$domain,'code'); }
    private function page(Request $request,int $id,string $page)
    {
        $domain=$this->owners->present($this->owners->owned($request->user(),$id));
        abort_if($page==='code'&&!isset($domain->actions['code']),404);
        abort_if($page==='setup'&&!isset($domain->actions['setup']),404);
        $keys=[];
        foreach (['setup','recovery','code',...OwnerActionController::ACTIONS] as $action) {
            $keys[$action]=\Paymenter\Extensions\Servers\Domains\Http\FormKeys::issue($request,'owner:'.$id.':'.$action);
        }
        return view('domains::'.$page,compact('domain','keys'));
    }
}
