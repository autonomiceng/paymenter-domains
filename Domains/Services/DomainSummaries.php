<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{User,Service,ServiceCancellation,Server,Invoice,InvoiceItem};
use Illuminate\Support\Facades\{DB,Crypt};
use Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence;
use Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,WatchName,WatchDnsObservation,ManagedPublicObservation,WatchRegistryObservation,Adoption,Operation,Account};
use Paymenter\Extensions\Servers\Domains\Presentation\DomainSummary;
/** Batched local projection. No transport, writes, locks, lazy model relations or actor cache. */
final class DomainSummaries
{
    public function one(User $owner,string $kind,int $id,?int $now=null): ?DomainSummary
    { return $this->batch($owner,$kind==='managed'?[$id]:[],$kind==='watch'?[$id]:[],$now)[$kind.':'.$id]??null; }
    public function services(User $owner,array $ids,?int $now=null): array
    {
        if (count($ids)>250) { throw new \InvalidArgumentException('At most 250 services per batch.'); }
        $native=Service::whereIn('id',$ids)->where('user_id',$owner->id)->whereHas('order',fn($q)=>$q->where('user_id',$owner->id))->pluck('id')->all();
        $domains=Domain::where('user_id',$owner->id)->whereIn('service_id',$native)->pluck('id','service_id')->all();
        $sources=\Paymenter\Extensions\Servers\Domains\Models\WatchSource::where('user_id',$owner->id)->whereIn('service_id',$native)->whereIn('state',['watch','managed_link'])->get();
        $pending=Domain::where('user_id',$owner->id)->whereIn('domain',$sources->pluck('registrable_name'))->whereHas('service',fn($q)=>$q->where('user_id',$owner->id)->whereHas('order',fn($q)=>$q->where('user_id',$owner->id)))->pluck('id','domain')->all();
        $dtos=$this->batch($owner,[...array_values($domains),...array_values($pending),...$sources->pluck('managed_domain_id')->filter()->all()],$sources->filter(fn($s)=>!isset($pending[$s->registrable_name]))->pluck('watch_id')->filter()->all(),$now);$out=[];
        foreach ($domains as $service=>$id) { if (isset($dtos['managed:'.$id])) { $out[$service]=$dtos['managed:'.$id]; } }
        foreach ($sources as $s) { $dto=$dtos[isset($pending[$s->registrable_name])?'managed:'.$pending[$s->registrable_name]:($s->state==='watch'?'watch:'.$s->watch_id:'managed:'.$s->managed_domain_id)]??null;
            if ($dto && in_array((int)$s->service_id,array_column($dto->relatedServices,'id'),true)) { $out[$s->service_id]=$dto; } }
        return $out;
    }
    private function latest(string $model,string $column,array $ids,?int $owner): array
    {
        $q=$model::whereIn($column,$ids);
        if ($owner!==null) { $q->where('user_id',$owner); }
        else { $q->whereExists(fn($q)=>$q->selectRaw('1')->from('domains_watch_names as w')->whereColumn('w.id',$model::make()->getTable().'.watch_id')->whereColumn('w.user_id',$model::make()->getTable().'.user_id')); }
        $group=[$column];if ($model!==WatchRegistryObservation::class) { $group[]='check_kind'; }
        $ids=(clone $q)->selectRaw('max(id)')->groupBy(...$group);
        foreach ($model===WatchRegistryObservation::class?['result']:['dns','website','email'] as $axis) {
            $good=(clone $q)->when($axis==='result',fn($q)=>$q->where('result','facts'),fn($q)=>$q->whereNotNull($axis));
            $ids->union($good->selectRaw('max(id)')->groupBy(...$group));
        }
        return $model::whereIn('id',$ids)->orderByDesc('id')->get()->groupBy($column)->all();
    }
    public function batch(User $owner,array $domainIds,array $watchIds,?int $now=null): array
    {
        $domainIds=array_unique($domainIds);$watchIds=array_unique($watchIds);
        if (count($domainIds)+count($watchIds)>250) { throw new \InvalidArgumentException('At most 250 targets per batch.'); }
        return $this->summaries(new DomainSummaryContext((int)$owner->id,$domainIds,$watchIds),$now??time());
    }
    /** Staff-only multi-owner watch projection. Customer batch() always retains its owner-scoped context. */
    public function staffWatches(array $watchIds,?int $now=null): array
    { return $this->summaries(DomainSummaryContext::staffWatches(array_unique($watchIds)),$now??time()); }
    private function summaries(DomainSummaryContext $c,int $now): array
    {
        if ($c->staff && $c->domains) { throw new \LogicException('Staff watch context cannot include managed domains.'); }
        $uid=$c->owner;
        $dids=array_keys($c->domains);$wids=array_keys($c->watches);
        $dns=['watch'=>$this->latest(WatchDnsObservation::class,'watch_id',$wids,$c->staff?null:$uid),'managed'=>$this->latest(ManagedPublicObservation::class,'domain_id',$dids,$uid)];
        $registry=$this->latest(WatchRegistryObservation::class,'watch_id',$wids,$c->staff?null:$uid);
        $work=['watch'=>DB::table('domains_watch_work')->whereIn('watch_id',$wids)->get()->groupBy('watch_id')->all(),
            'managed'=>DB::table(ManagedPublicWorkSchema::TABLE)->whereIn('domain_id',$dids)->whereNull('retired_at')->whereExists(fn($q)=>$q->selectRaw('1')->from('domains_domains as d')->whereColumn('d.id','domain_id')->whereColumn('d.authority_generation','generation'))->get()->groupBy('domain_id')->all()];
        $adoptions=Adoption::whereIn('id',array_map(fn($d)=>$d->adoption_id,$c->domains))->get()->keyBy('id');
        $cancels=ServiceCancellation::whereIn('service_id',array_map(fn($d)=>$d->service_id,$c->domains))->pluck('service_id')->all();
        $intents=DB::table('domains_native_intents')->whereIn('service_id',array_map(fn($d)=>$d->service_id,$c->domains))->pluck('service_id')->all();
        $testServers=Account::whereIn('server_id',array_map(fn($d)=>$d->server_id,$c->domains))->where('environment','test')->pluck('server_id')->all();
        $servers=Server::with('settings')->whereIn('id',array_map(fn($d)=>$d->server_id,$c->domains))->get()->keyBy('id');
        $cycles=Operation::whereIn('id',Operation::selectRaw('max(domains_operations.id)')->join('domains_domains as d','d.id','=','domains_operations.domain_id')->whereIn('d.id',$dids)->whereColumn('d.authority_generation','domains_operations.authority_generation')->whereColumn('d.account_fingerprint','domains_operations.account_fingerprint')->where('action','renew')->groupBy('domain_id'))->limit(250)->get()->groupBy('domain_id');
        $acquisitions=Operation::whereIn('id',Operation::selectRaw('max(domains_operations.id)')->join('domains_domains as d','d.id','=','domains_operations.domain_id')->whereIn('d.id',$dids)->whereColumn('d.authority_generation','domains_operations.authority_generation')->whereColumn('d.account_fingerprint','domains_operations.account_fingerprint')->whereIn('action',['register','transfer'])->groupBy('domain_id'))->limit(250)->get()->keyBy('domain_id');
        $invoices=Invoice::where('user_id',$uid)->whereIn('id',$cycles->flatten()->pluck('invoice_id'))->get()->keyBy('id');
        $targets=[...$c->domains,...$c->watches];
        $scopes=array_map(fn($t)=>WatchNoticePreferences::scope((int)$t->user_id,$t instanceof WatchName?$t->canonical_name:$t->domain),$targets);
        $noticeStates=DB::table('domains_watch_notice_state')->whereIn('user_id',$c->staff?array_map(fn($w)=>$w->user_id,$c->watches):[$uid])->whereIn('scope',$scopes)->get()->keyBy('scope');
        $transferEndings=PolicyEndings::transferEnabled(array_map(fn($w)=>$w->canonical_name,$c->watches));
        $items=InvoiceItem::whereIn('id',$cycles->flatten()->pluck('invoice_item_id'))->get()->keyBy('id');$out=[];
        foreach (['managed'=>$c->domains,'watch'=>$c->watches] as $kind=>$targets) {
            foreach ($targets as $id=>$t) {
                $uid=(int)$t->user_id;$stamp=$c->stamp($kind,$id);$rows=$dns[$kind][$id]??collect();$jobs=$work[$kind][$id]??collect();$fresh=[];$signals=[];$lastKnown=[];
                $nameserverFact = null;
                if ($kind === 'managed') {
                    $nameserverFact = self::checkedNameservers($rows, $jobs, $t, $c->services[$t->service_id], $stamp, $now);
                    if (!$nameserverFact) {
                        $stored = $t->observation['nameservers'] ?? null;
                        if (is_array($stored) && ($stored['name'] ?? null) === $t->domain
                            && ($stored['generation'] ?? null) === $t->authority_generation
                            && ($stored['account_fingerprint'] ?? null) === $t->account_fingerprint) {
                            $nameserverFact = NameserverEvidence::fromArray(array_intersect_key($stored, array_flip(['nameservers', 'state', 'source', 'at'])));
                        }
                    }
                }
                foreach (['dns'=>'delegation','website'=>'points','email'=>'points'] as $axis=>$family) {
                    $attempt=$rows->firstWhere('check_kind',$family);$row=$rows->first(fn($r)=>$r->check_kind===$family && $r->{$axis}!==null);$job=$jobs->firstWhere('kind',$family);
                    $match=$row && $stamp && hash_equals($stamp,$row->facts['presentation_stamp']??'');
                    $current=$match && $job && ($job->retired_at??null)===null && (in_array($job->safe_code,[null,'ready','rotation','budget_exhausted'],true) || ($row->partial && $attempt?->id===$row->id && $job->safe_code===$row->safe_code))
                        && $attempt?->{$axis}!==null && $now>=$row->started_at && $now<$row->valid_until && $row->{$axis}!==null;
                    if ($kind==='managed') { $current=$current && in_array($t->status,['active','grace'],true) && $c->services[$t->service_id]->status==='active' && $t->provider_expires_at?->format('Y-m-d')>gmdate('Y-m-d',$now) && (int)$job->generation===(int)$t->authority_generation && $row->account_fingerprint===$t->account_fingerprint && (int)$row->generation===(int)$t->authority_generation; }
                    $valid=$current && (!$row->partial || $row->{$axis}===true);
                    $signals[$axis]=['value'=>$valid?$row->{$axis}:null,'available'=>$match && $row->{$axis}!==null,'stale'=>!$valid,'mixed'=>$match && $row->mixed];
                    $lastKnown[$axis]=['value'=>$row?->{$axis},'checkedAt'=>$row?->started_at,'stale'=>!$valid,'partial'=>$row?->partial??true,'inScope'=>(bool)$match];
                    $familyCurrent=$attempt && $stamp && hash_equals($stamp,$attempt->facts['presentation_stamp']??'') && $job && ($job->retired_at??null)===null && !in_array($job->safe_code,['target_changed','awaiting_acceptance','clock_reversal','deadline'],true) && $now>=$attempt->started_at && $now<$attempt->valid_until;
                    if ($kind==='managed') { $familyCurrent=$familyCurrent && in_array($t->status,['active','grace'],true) && $c->services[$t->service_id]->status==='active' && $t->provider_expires_at?->format('Y-m-d')>gmdate('Y-m-d',$now) && (int)$job->generation===(int)$t->authority_generation && $attempt->account_fingerprint===$t->account_fingerprint && (int)$attempt->generation===(int)$t->authority_generation; }
                    $fresh[$family]=['checkedAt'=>$attempt?->started_at,'stale'=>!$familyCurrent,'partial'=>$attempt?->partial??true,'nextCheck'=>$job?->due_at];
                }
                $signals['partial']=$signals['dns']['stale']||$signals['website']['stale']||$signals['email']['stale'];
                $keys=DnsSignalLabels::projection($signals);$states=[];$expiry=null;$precision=null;$source=null;$due=null;$price=null;$off=false;$reminderOff=false;$projected=false;
                if ($kind==='watch') {
                    $registryRows=$registry[$id]??collect();$r=$registryRows->first();$good=$registryRows->firstWhere('result','facts');$j=$jobs->firstWhere('kind','expiry');$match=$r && ($registryStamp=$c->stamp($kind,$id,'registry')) && hash_equals($registryStamp,$r->facts['presentation_stamp']??'');
                    $until=$r?->valid_until;if ($r && WatchRegistryChecks::urgent($r->facts['expiry']??null,$now)) { $until=min((int)$until,$r->started_at+43200); }
                    $fresh['registry']=['checkedAt'=>$r?->started_at,'stale'=>!($match && $r->result==='facts' && $now>=$r->started_at && $now<$until && $j && in_array($j->safe_code,[null,'budget_exhausted'],true)),'partial'=>false,'nextCheck'=>$j?->due_at];
                    if ($good) { $expiry=$good->facts['expiry']??null;$precision=$good->facts['precision']??null;$source='Public registry';$lastKnown['registry']=['value'=>$expiry,'checkedAt'=>$good->started_at,'stale'=>$fresh['registry']['stale'],'partial'=>false,'inScope'=>(bool)($good && $registryStamp && hash_equals($registryStamp,$good->facts['presentation_stamp']??''))]; }
                    if ($t->state!=='active') { $states[]='watch.'.$t->state; }
                    $factsCurrent=!$fresh['registry']['stale'];
                    $semanticCurrent=$match && $j && $now>=$r->started_at && $now<$r->started_at+($r->reason==='no_coverage'?604800:172800) && !in_array($j->safe_code,['target_changed','expiry_unknown','deadline'],true) && in_array($r->reason,['not_registered','no_coverage'],true);
                    $fresh['registry']['stale']=!($factsCurrent || $semanticCurrent);
                    if (!$factsCurrent) { $expiry=$precision=$source=null; }
                    if ($semanticCurrent) { $states[]='watch.'.$r->reason; }
                    if ($expiry && $factsCurrent && substr($expiry,0,10)<=gmdate('Y-m-d',$now)) { $states[]='watch.expired'; }
                    if ($factsCurrent && array_intersect($r->facts['status']??[],['redemption period','pending delete','redemptionPeriod','pendingDelete'])) { $states[]='watch.redemption'; }
                    $registration='domain.registration.'.($t->registration==='external' && !in_array('watch.not_registered',$states,true)?'external':'unknown');
                    $url=route('domains.checks.show',['kind'=>'watch','id'=>$id]);
                } else {
                    $pending=$t->adoptionPending();$registration='domain.registration.'.($pending?'unknown':(in_array($t->status,['active','expired','grace','redemption'],true)?'platform':'unknown'));
                    $states[]=$pending?'adopt.pending':match($t->status){'active'=>$t->provider_expires_at===null?'domain.review':($t->provider_expires_at->format('Y-m-d')<=gmdate('Y-m-d',$now)?'watch.expired':'domain.active'),'expired','grace'=>'domain.expired','redemption'=>'domain.redemption','registration_pending'=>'domain.pending','transfer_pending'=>'domain.transfer','cancelled'=>'domain.cancelled',default=>'domain.review'};
                    if (!$pending) { $expiry=$t->provider_expires_at?->format('Y-m-d');$precision=$expiry?'date':null;$source=$expiry?'Confirmed registration':null; }
                    $op=$acquisitions[$id]??null;if(!$pending && $op && (int)$op->service_id===(int)$t->service_id && (int)$op->server_id===(int)$t->server_id && $op->registrar_key===$t->registrar_key){if($op->state==='awaiting_payment'){$states=['domain.awaiting_payment'];}elseif(in_array($op->state,['held','uncertain'],true)){$states=[$op->action==='register' && !in_array($op->safe_code,['contact_details_required','transfer_setup_review_required','nameservers_required'],true)?'domain.processing':'domain.review'];}}
                    $s=$c->services[$t->service_id];$a=$adoptions[$t->adoption_id]??null;
                    $off=$pending || ($t->renewal_policy['owner_auto_renew']??true)===false || ($t->provenance==='adopted' && (!$a || $a->do_not_renew)) || ($t->renewal_policy['renewals_stopped']??false) || in_array($s->id,[...$cancels,...$intents],true);
                    $reminderOff=$t->provenance==='adopted' && $a && $a->do_not_renew;
                    if ($t->provenance==='adopted') { $states[]='adopt.provenance'; }
                    if ($off && !$pending) { $states[]='adopt.renewal_off'; }
                    if (!$pending) { try { if ($s->currency_code==='USD') { $price=Storefront::money(RetailLine::decimal($s->price)); } } catch (\Throwable) {} }
                    $cycle=($cycles[$id]??collect())->first(fn($o)=>(int)$o->authority_generation===(int)$t->authority_generation && (int)$o->service_id===(int)$t->service_id && (int)$o->server_id===(int)$t->server_id && $o->registrar_key===$t->registrar_key && $o->account_fingerprint===$t->account_fingerprint && $o->base_expires_at?->format('Y-m-d')===$expiry);
                    if (!$off && $expiry) {
                        if ($cycle) { $inv=$invoices[$cycle->invoice_id]??null;$item=$items[$cycle->invoice_item_id]??null;
                            try { if ($inv && $item && (int)$item->invoice_id===(int)$inv->id && in_array($inv->status,['pending','paid'],true) && $inv->currency_code===$cycle->currency && $item->reference_id===null && $item->reference_type===null && (string)$item->quantity==='1' && PricingRule::minor((string)$item->price)===PricingRule::minor((string)$cycle->amount) && $inv->due_at?->format('Y-m-d')===($cycle->policy['calendar']['due']??null)) { $due=$inv->due_at->format('Y-m-d'); } } catch (\Throwable) {} }
                        else { try { $projected=true;$timing=BillingCalendar::timing($servers[$t->server_id]->settings);$due=BillingCalendar::dates($t->provider_expires_at->toDateTimeImmutable(),$timing['issue'],$timing['due'])['due']; } catch (\Throwable) {} }
                    }
                    $url=route('domains.domains.show',$id);
                    if ($due && $due<=gmdate('Y-m-d',$now)) { $due=null; }
                }
                $delegation=$rows->firstWhere('check_kind','delegation');$expected=$kind==='watch'?$t->expected_dns:($c->configs[$id]->expected_dns??null);
                $scope=WatchNoticePreferences::scope($uid,$kind==='watch'?$t->canonical_name:$t->domain);$episode=$noticeStates[$scope]??null;if ($episode && (int)$episode->user_id!==$uid) { $episode=null; }
                try { $episode=$episode?json_decode(Crypt::decryptString($episode->data),true,64,JSON_THROW_ON_ERROR):[]; if (!is_array($episode) || !is_array($episode['portal']??[]) || array_filter($episode['portal']??[],fn($v)=>!is_array($v) || array_filter($v,fn($key)=>!is_string($key)))) { $episode=[]; } } catch (\Throwable) { $episode=[]; }
                $noticeKeys=($episode['target']??null)===[$kind,(int)$id]?array_values(array_unique(array_merge(...array_values($episode['portal']??[[]])))):[];$noticeDates=[];
                if ($expected==='platform' && !$signals['dns']['stale'] && $signals['dns']['value']==='external') {
                    $changed=in_array('ns_changed',$noticeKeys,true);$states[]=$changed?'watch.ns_changed':'watch.ns_pending';
                    $noticeDates['dns']=(int)($changed?($episode['dns']['episode']??0):$delegation?->started_at);
                }
                if (array_filter($fresh,fn($f)=>$f['stale'])) { $states[]=array_filter($fresh,fn($f)=>$f['checkedAt']!==null)?'watch.stale':'watch.not_checked'; }
                if (array_filter($fresh,fn($f)=>$f['partial'] && !$f['stale'])) { $states[]='watch.partial'; }
                $related=[];$hosts=[];
                foreach ($c->related($kind,$id) as $s) { if (!$c->validSource($s)) { continue; }$h=$c->services[$s->service_id];$p=$c->products[$h->product_id];
                    $related[]=['id'=>(int)$h->id,'label'=>$p->name,'url'=>route('services.show',$h->id)];$hosts[]=$s->hostname; }
                $out[$kind.':'.$id]=new DomainSummary($kind==='watch'?$t->canonical_name:$t->domain,$kind==='watch'?$t->display_name:null,$kind,$registration,$keys,$signals['website']['value'],$signals['email']['value'],array_values(array_unique($states)),DomainSummaryStrings::all(),$expiry,$precision,$source,$fresh,$due,$price,$related,array_values(array_unique($hosts)),['detail'=>$url,'preferences'=>($kind==='watch' && $t->state==='active' && $stamp)||($kind==='managed' && !$pending && isset($c->configs[$id]) && $c->services[$t->service_id]->status==='active' && $expiry>gmdate('Y-m-d',$now))?route('domains.checks.show',['kind'=>$kind,'id'=>$id]):null,'invoiceHistory'=>$kind==='managed' && $pending?route('invoices'):null,'compareTransfer'=>config('domains.watch_transfer.enabled',false) && $kind==='watch' && $t->state==='active' && $t->registration==='external' && $factsCurrent && WatchTransferPolicy::window(substr($expiry??'',0,10),$now) && !in_array('watch.redemption',$states,true) && array_filter($transferEndings,fn($ending)=>str_ends_with($t->canonical_name,'.'.$ending))?route('domains.watch.transfer',$id):null,'support'=>route('tickets.create')],$off,$lastKnown,max(0,count($c->related($kind,$id))-3),$noticeKeys,$noticeDates,$reminderOff,$projected,$kind==='managed' && in_array($t->server_id,$testServers),$nameserverFact?->toArray());
            }
        }
        return $out;
    }

    private static function checkedNameservers($rows, $jobs, Domain $domain, Service $service, ?string $stamp, int $now): ?NameserverEvidence
    {
        if (!$stamp || !in_array($domain->status, ['active', 'grace'], true) || $service->status !== 'active'
            || !$domain->provider_expires_at || $domain->provider_expires_at->format('Y-m-d') <= gmdate('Y-m-d', $now)) { return null; }
        $attempt = $rows->firstWhere('check_kind', 'delegation');
        $job = $jobs->firstWhere('kind', 'delegation');
        if (!$job || ($job->retired_at ?? null) !== null || (int) $job->generation !== (int) $domain->authority_generation) { return null; }
        foreach ($rows as $row) {
            $workCurrent = in_array($job->safe_code, [null, 'ready', 'rotation', 'budget_exhausted'], true)
                || ($row->partial && $attempt?->id === $row->id && $job->safe_code === $row->safe_code);
            if ($row->check_kind !== 'delegation' || !$row->dns || $now < $row->started_at || $now >= $row->valid_until
                || $row->account_fingerprint !== $domain->account_fingerprint || (int) $row->generation !== (int) $domain->authority_generation
                || !hash_equals($stamp, $row->facts['presentation_stamp'] ?? '')
                || !$workCurrent || ($attempt?->dns === null)) { continue; }
            foreach ($row->facts['queries'] ?? [] as $query) {
                if (($query['name'] ?? null) !== $domain->domain || ($query['type'] ?? null) !== 'NS'
                    || ($query['state'] ?? null) !== 'rrset' || !is_array($query['records'] ?? null)) { continue; }
                $nameservers = array_column($query['records'], 'value');
                try {
                    $at = (new \DateTimeImmutable('@'.(int) $row->started_at))->setTimezone(new \DateTimeZone('UTC'));
                    return NameserverEvidence::observed($nameservers, 'public_dns_check', $at);
                } catch (\Throwable) { continue; }
            }
        }
        return null;
    }
}
