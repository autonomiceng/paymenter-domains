<?php
// Enom 444f31c owned DomainController paths; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Paymenter\Extensions\Servers\Domains\Presentation\DomainStatus;
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries,Storefront};
use Paymenter\Extensions\Servers\Domains\Services\OwnedDomains;
/** Owner pages; creation and writes have distinct controllers. Dormant until39. */
final readonly class OwnedDomainController
{
    public function __construct(private OwnedDomains $owners) {}
    public function index(Request $request)
    {
        $raw=$request->query('q');$search=is_string($raw)?trim(substr($raw,0,253)):'';
        $raw=$request->query('status');$filter=is_string($raw)&&isset(DomainStatus::SET[$raw])?$raw:'';
        $sort=in_array($request->query('sort'),['expiry','-expiry','name','-name'],true)?$request->query('sort'):'expiry';
        // One list: registered domains and the domains your hosting uses. Status depends on the order, renewal stops
        // and expiry, not only the status column, so chips, filters and sorting work on resolved rows.
        $rows=(new DomainSummaries())->listed($request->user(),now('UTC')->getTimestamp());
        $key=fn($row)=>$row->statusState===null?null:DomainStatus::key($row->statusState);
        // Cancelled domains stay out of the way until the Cancelled chip asks for them.
        $shown=array_filter($rows,fn($row)=>$key($row)!=='cancelled');
        $filterCounts=[''=>count($shown),...array_count_values(array_filter(array_map($key,array_values($rows))))];
        $rows=$filter===''?$shown:array_filter($rows,fn($row)=>$key($row)===$filter);
        if ($search!=='') { $rows=array_filter($rows,fn($row)=>str_contains(strtolower($row->name),strtolower($search))); }
        $desc=$sort[0]==='-';$rows=array_values($rows);
        usort($rows,function($a,$b)use($sort,$desc){
            if (str_contains($sort,'expiry')) { [$x,$y]=[$a->expiry?substr($a->expiry,0,10):null,$b->expiry?substr($b->expiry,0,10):null];
                if (($x===null)!==($y===null)) { return $x===null?1:-1; }
                if ($x!==$y) { return $desc?strcmp($y,$x):strcmp($x,$y); }
                return strcmp($a->name,$b->name); }
            return $desc?strcmp($b->name,$a->name):strcmp($a->name,$b->name);
        });
        $page=max(1,(int)$request->query('page',1));
        $domains=new LengthAwarePaginator(array_slice($rows,($page-1)*25,25),count($rows),25,$page,['path'=>route('domains.domains.index'),'query'=>array_filter(['q'=>$search,'status'=>$filter,'sort'=>$sort],fn($v)=>$v!=='')]);
        $transfersOffered=app(Storefront::class)->hasTransferOffers();
        return view('domains::index',compact('domains','search','filter','sort','filterCounts','transfersOffered'));
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
