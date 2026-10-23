<?php
// Enom 444f31c owned DomainController paths; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Http\Controllers;
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,WatchName};
use Paymenter\Extensions\Servers\Domains\Presentation\DomainStatus;
use Paymenter\Extensions\Servers\Domains\Services\DomainSummaries;
use Paymenter\Extensions\Servers\Domains\Services\OwnedDomains;
/** Owner pages; creation and writes have distinct controllers. Dormant until39. */
final readonly class OwnedDomainController
{
    public function __construct(private OwnedDomains $owners) {}
    public function index(Request $request)
    {
        $base=Domain::where('user_id',$request->user()->id)->whereHas('service',fn($q)=>$q->where('user_id',$request->user()->id)->whereHas('order',fn($q)=>$q->where('user_id',$request->user()->id)));
        $raw=$request->query('q');$search=is_string($raw)?trim(substr($raw,0,253)):'';
        $raw=$request->query('status');$filter=is_string($raw)&&isset(DomainStatus::SET[$raw])?$raw:'';
        $sort=in_array($request->query('sort'),['expiry','-expiry','name','-name'],true)?$request->query('sort'):'expiry';
        // Status depends on the order, renewal stops and expiry, not only the status column, so chips count resolved rows.
        $summaries=new DomainSummaries();$now=now('UTC')->getTimestamp();$dtos=[];$keys=[];
        foreach ((clone $base)->pluck('id')->chunk(250) as $ids) { $dtos+=$summaries->batch($request->user(),$ids->all(),[],$now); }
        foreach ($dtos as $key=>$dto) { if ($dto->statusState!==null) { $keys[(int)substr($key,8)]=DomainStatus::key($dto->statusState); } }
        $query=clone $base;
        if ($search!=='') { $query->where('domain','like','%'.addcslashes(strtolower($search),'%_\\').'%'); }
        if ($filter!=='') { $query->whereIn('id',array_keys($keys,$filter,true)); }
        if (str_contains($sort,'expiry')) { $query->orderByRaw('provider_expires_at IS NULL')->orderBy('provider_expires_at',$sort[0]==='-'?'desc':'asc'); }
        $query->orderBy('domain',$sort==='-name'?'desc':'asc');
        $domains=$query->paginate(25)->appends(array_filter(['q'=>$search,'status'=>$filter,'sort'=>$sort],fn($v)=>$v!==''));
        $domains->setCollection($domains->getCollection()->map(fn($d)=>$dtos['managed:'.$d->id]??null)->filter()->values());
        $filterCounts=[''=>count($dtos),...array_count_values($keys)];
        $watches=WatchName::where('user_id',$request->user()->id)->whereNull('managed_domain_id')->whereIn('state',['active','paused','retired'])
            ->whereNotIn('canonical_name',(clone $base)->select('domain'))->orderBy('canonical_name')->paginate(25,['*'],'related_page')->withQueryString();
        $dtos=$summaries->batch($request->user(),[],$watches->getCollection()->pluck('id')->all());
        $watches->setCollection($watches->getCollection()->map(fn($w)=>$dtos['watch:'.$w->id]??null)->filter()->values());
        return view('domains::index',compact('domains','watches','search','filter','sort','filterCounts'));
    }
    public function show(Request $request,int $domain) { return $this->page($request,$domain,'show'); }
    public function setup(Request $request,int $domain) { return $this->page($request,$domain,'setup'); }
    public function code(Request $request,int $domain) { return $this->page($request,$domain,'code'); }
    private function page(Request $request,int $id,string $page)
    {
        $holding=$this->owners->owned($request->user(),$id);
        $summary=$page==='show'?(new DomainSummaries())->one($request->user(),'managed',$id,now('UTC')->getTimestamp()):null;
        $domain=$this->owners->present($holding);
        abort_if($page==='code'&&!isset($domain->actions['code']),404);
        abort_if($page==='setup'&&!isset($domain->actions['setup']),404);
        $keys=[];
        foreach (['setup','recovery','code','renew','auto-renew',...OwnerActionController::ACTIONS] as $action) {
            $keys[$action]=\Paymenter\Extensions\Servers\Domains\Http\FormKeys::issue($request,'owner:'.$id.':'.$action);
        }
        return view('domains::'.$page,compact('domain','summary','keys'));
    }
}
