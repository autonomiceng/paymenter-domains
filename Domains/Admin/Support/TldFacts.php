<?php
// Adapted from Enom 444f31c TldResource price preview; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Price,SalesPolicy,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,EnomTestRegistration,PriceCatalogue};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** Saved facts only. No reader factory, credentials or provider calls. */
final class TldFacts
{
    public const AUDIT = \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::KEY;
    public static function bound($query)
    {
        return $query->whereExists(fn($q)=>$q->selectRaw('1')->from('domains_accounts as a')
            ->join('extensions as s','s.id','=','a.server_id')->where('s.extension','Domains')->where('s.type','server')
            ->whereColumn('a.server_id','domains_tlds.server_id')->whereColumn('a.registrar_key','domains_tlds.registrar_key')
            ->whereColumn('a.account_fingerprint','domains_tlds.account_fingerprint'));
    }
    public static function registrar(string $key): string { return ['enom'=>'Enom','namesilo'=>'NameSilo'][$key]??'Unavailable'; }
    public static function holders(Tld $tld): int
    { return Domain::where(['server_id'=>$tld->server_id,'tld'=>$tld->tld,'account_fingerprint'=>$tld->account_fingerprint])->whereNotNull('reservation_name')->count(); }
    /** Server id of the account that takes new orders for this TLD, or null when none is selected. */
    public static function sellerId(string $tld): ?int
    { $id=SalesPolicy::where('tld',$tld)->value('server_id');return $id===null?null:(int)$id; }
    public static function prices(Tld $tld, ?StaffGrants $grants = null): array
    {return self::snapshot($tld,$grants)['prices'];}
    /** One server-only snapshot per row/render; never retain across requests or use as write authority.
     * Each row: action, term, retail (null when missing), flags (below_cost, cost_missing, price_missing, needs_sync, unpublished),
     * fresh, observed and until; cost only with the costs permission. */
    public static function snapshot(Tld $tld, ?StaffGrants $grants = null): array
    {
        $grants ??= Staff::requireView();
        try{$accepted=CatalogueAcceptances::load(CatalogueAcceptances::binding(Account::where('server_id',$tld->server_id)->firstOrFail()));}catch(\Throwable){$accepted=null;}
        try{$ruleHash=self::ruleHash((new \Paymenter\Extensions\Servers\Domains\Services\PricingRules)->rule($tld));}catch(\Throwable){$ruleHash=null;}
        $saved=Price::where(['server_id'=>$tld->server_id,'account_fingerprint'=>$tld->account_fingerprint,'tld'=>$tld->tld])->orderBy('action')->orderBy('term')->get();
        return self::build($tld,$grants,$accepted,$ruleHash,$saved,static fn():?int=>self::sellerId($tld->tld));
    }
    /** snapshot() for a page of TLD rows in a fixed number of reads: accounts, acceptances and default rules once per
     * account; prices, sellers and domain counts once per page. Keyed by TLD id, each with 'seller' and 'holders' added. */
    public static function page(\Illuminate\Support\Collection $tlds, ?StaffGrants $grants = null): array
    {
        $grants ??= Staff::requireView();
        if($tlds->isEmpty()){return [];}
        $servers=$tlds->pluck('server_id')->map(fn($id)=>(int)$id)->unique()->values()->all();$names=$tlds->pluck('tld')->unique()->values()->all();
        $accounts=Account::whereIn('server_id',$servers)->orderBy('id')->get()->groupBy('server_id')->map->first();
        $rules=new \Paymenter\Extensions\Servers\Domains\Services\PricingRules;$accepted=[];$defaults=[];
        foreach($servers as $id){
            try{$accepted[$id]=CatalogueAcceptances::load(CatalogueAcceptances::binding($accounts[$id]??throw new \DomainException));}catch(\Throwable){$accepted[$id]=null;}
            try{$defaults[$id]=$tlds->contains(fn(Tld $t)=>(int)$t->server_id===$id&&$t->use_default_rule)?self::ruleHash($rules->defaultRule($id)):null;}catch(\Throwable){$defaults[$id]=null;}
        }
        $key=static fn($row)=>(int)$row->server_id.'|'.$row->account_fingerprint.'|'.$row->tld;
        $prices=Price::whereIn('server_id',$servers)->whereIn('tld',$names)->orderBy('action')->orderBy('term')->get()->groupBy($key);
        $sellers=SalesPolicy::whereIn('tld',$names)->pluck('server_id','tld');
        $holders=Domain::whereIn('server_id',$servers)->whereIn('tld',$names)->whereNotNull('reservation_name')
            ->selectRaw('server_id, tld, account_fingerprint, count(*) as holders')->groupBy('server_id','tld','account_fingerprint')->get()->keyBy($key);
        $out=[];
        foreach($tlds as $t){
            if($t->use_default_rule){$hash=$defaults[(int)$t->server_id];}else{try{$hash=self::ruleHash($rules->rule($t));}catch(\Throwable){$hash=null;}}
            $seller=isset($sellers[$t->tld])?(int)$sellers[$t->tld]:null;
            $out[$t->id]=[...self::build($t,$grants,$accepted[(int)$t->server_id],$hash,$prices[$key($t)]??collect(),static fn():?int=>$seller),
                'seller'=>$seller,'holders'=>(int)($holders[$key($t)]->holders??0),'demo'=>DemoData::is($accounts[(int)$t->server_id]??null)];
        }
        return $out;
    }
    private static function ruleHash(array $rule): string
    {return hash('sha256',json_encode($rule,JSON_THROW_ON_ERROR));}
    private static function build(Tld $tld, StaffGrants $grants, ?\Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance $accepted, ?string $ruleHash, $saved, \Closure $seller): array
    {
        $out=[];
        foreach($saved as $p){
            $unavailable=($p->eligibility['price_unavailable']??false)===true;
            $flags=[];
            if(($p->hold_reason??($p->eligibility['staff_alert']??null))==='below_cost'){$flags[]='below_cost';}
            if($p->wholesale===null||($p->hold_reason??($p->eligibility['staff_alert']??null))==='unknown_price'){$flags[]='cost_missing';}
            if($unavailable){$flags[]='price_missing';}
            if($p->action!=='renew'&&($accepted===null||($p->eligibility['catalogue_revision']??null)!==$accepted->revision)){$flags[]='needs_sync';}
            if($ruleHash===null||($p->eligibility['rule_hash']??null)!==$ruleHash){$flags[]='unpublished';}
            $row=['action'=>$p->action,'term'=>(int)$p->term,'retail'=>$unavailable&&$p->action!=='renew'?null:self::money($p->retail),'flags'=>$flags,
                'fresh'=>$p->observed_at<=now()&&$p->fresh_until&&$p->fresh_until>now(),'observed'=>$p->observed_at,'until'=>$p->fresh_until];
            if($grants->costs){$row['cost']=self::money($p->wholesale,4);}
            $out[]=$row;
        }
        return ['prices'=>$out,'sales'=>self::saleStatus($tld,$saved,$accepted,$ruleHash,$out,$seller)];
    }
    /** "Default pricing", "Fixed price" or "Custom markup" for a TLD row. */
    public static function pricing(Tld $t): string
    {
        if($t->use_default_rule){return 'Default pricing';}
        try{return (new \Paymenter\Extensions\Servers\Domains\Services\PricingRules)->rule($t)['mode']==='fixed'?'Fixed price':'Custom markup';}catch(\Throwable){return 'Custom markup';}
    }
    /** "USD 12.00"; costs keep their exact precision ("USD 12.3456"). Null when there is no amount. */
    public static function money(?string $value,int $places=2): ?string
    { return $value===null?null:'USD '.($places===4?self::exactMoney(PriceCatalogue::cost($value)):PriceCatalogue::retail($value)); }
    private static function exactMoney(string $value): string
    { return preg_replace('/(\.\d{2}\d*?)0+$/D','$1',$value); }
    /** The renewal price row for one year, the row the list and the prices table show. */
    public static function renewal(array $rows): ?array
    { return collect($rows)->first(fn($r)=>$r['action']==='renew'&&$r['term']===1)??collect($rows)->firstWhere('action','renew'); }
    /** "Below cost" when the cost is known and above the price, null otherwise. */
    public static function costNote(?array $row): ?string
    { return $row!==null&&in_array('below_cost',$row['flags'],true)?'Below cost':null; }
    /** "1 year", "3 years" or "1 to 3 years". */
    public static function terms(Tld $t): string
    {
        $years=static fn(int $n):string=>$n.' '.($n===1?'year':'years');
        return $t->min_years===$t->max_years?$years((int)$t->min_years):$t->min_years.' to '.$years((int)$t->max_years);
    }
    private const DANGER='fi-color fi-color-danger fi-text-color-600 dark:fi-text-color-400';
    private static function missing(string $cause): string
    { return '<span class="'.self::DANGER.'" style="font-weight:500">'.e(\Paymenter\Extensions\Servers\Domains\Presentation\Missing::LABEL).'</span><div style="font-size:.75rem;opacity:.75">'.e($cause).'</div>'; }
    private static function cell(string $html, bool $nowrap = true): string
    { return '<td style="padding:8px 12px;vertical-align:top'.($nowrap?';white-space:nowrap':'').'">'.$html.'</td>'; }
    /** Prices by registrar account: one row per account that sells this TLD. */
    public static function accountsTable(Tld $t, StaffGrants $grants): HtmlString
    {
        $rows=self::bound(Tld::query()->where('tld',$t->tld))->with('server:id,name')->orderBy('server_id')->get();
        if($rows->isEmpty()){return new HtmlString('No prices yet');}
        $facts=self::page($rows,$grants);
        $head=['Account','Pricing','Registration','Renewal','Transfer',...($grants->costs?['Cost']:[]),'Domains'];
        $html='<div style="max-width:100%;overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.875rem"><thead><tr>';
        foreach($head as $label){
            $help=$label==='Cost'?' <span title="Registrations and transfers below cost are held. Renewals are billed." style="cursor:help;opacity:.6">?</span>':'';
            $html.='<th scope="col" style="padding:8px 12px;text-align:left;white-space:nowrap;font-weight:500">'.e($label).$help.'</th>';
        }
        $html.='</tr></thead><tbody>';
        foreach($rows as $row){
            $fact=$facts[$row->id];$prices=$fact['prices'];
            $first=static fn(string $action)=>collect($prices)->where('action',$action)->sortBy('term')->first();
            $price=static fn(?array $p)=>$p===null?self::missing('Sync TLD prices needed'):($p['retail']===null?self::missing('Price missing'):e($p['retail']));
            $account=e((string)$row->server?->name).($fact['demo']?' '.DemoData::tag(inline:true)->toHtml():'')
                .($fact['seller']===(int)$row->server_id?' <span style="margin-inline-start:6px;padding:1px 6px;border-radius:4px;border:1px solid currentColor;font-size:11px;opacity:.8;white-space:nowrap">New orders</span>':'');
            $html.='<tr style="border-top:1px solid rgb(127 127 127 / .2)">'.self::cell($account,false).self::cell(e(self::pricing($row)))
                .self::cell($price($first('register'))).self::cell($price(self::renewal($prices))).self::cell($price($first('transfer')));
            if($grants->costs){
                $renew=self::renewal($prices);$note=self::costNote($renew);
                $html.=self::cell(($renew['cost']??null)===null?self::missing('No cost saved')
                    :($note?'<span class="'.self::DANGER.'">'.e($renew['cost']).'</span><div class="'.self::DANGER.'" style="font-size:.75rem">'.e($note).'</div>':e($renew['cost'])));
            }
            $html.=self::cell(e((string)$fact['holders'])).'</tr>';
        }
        return new HtmlString($html.'</tbody></table></div>');
    }
    /** History as a table: time, change with its reason below, and who made it. */
    public static function historyTable(?array $rows): HtmlString
    {
        if($rows===null){return new HtmlString(e(DomainFacts::UNREADABLE));}
        if(!$rows){return new HtmlString('No changes yet');}
        $html='<div style="max-width:100%;overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.875rem"><thead><tr>';
        foreach(['Time','Change','By'] as $label){$html.='<th scope="col" style="padding:8px 12px;text-align:left;white-space:nowrap;font-weight:500">'.e($label).'</th>';}
        $html.='</tr></thead><tbody>';
        foreach($rows as $r){
            $html.='<tr style="border-top:1px solid rgb(127 127 127 / .2)">'.self::cell(e($r['time'])).self::cell(e($r['change']).($r['reason']!==''?'<div style="font-size:.75rem;opacity:.75;overflow-wrap:anywhere">'.e($r['reason']).'</div>':''),false).self::cell(e($r['by'])).'</tr>';
        }
        return new HtmlString($html.'</tbody></table></div>');
    }
    public static function acceptance(Account $a): string
    {
        try{$binding=CatalogueAcceptances::binding($a);$accepted=CatalogueAcceptances::load($binding);$count=count($accepted->endings);$text='Revision '.$accepted->revision.' · '.$count.' '.($count===1?'ending':'endings');
            if($a->registrar_key==='enom'&&$a->environment==='test'){$protocol=\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::registrationAcceptance($binding);
                // The unaccepted warning is actionable only where the deployment offers TEST registration acceptance.
                if($accepted->registrationRoute===$protocol['route']&&$accepted->registrationIntervalSeconds===$protocol['interval_seconds']){$text.=config('domains.enom_writes.transfer')===true?' · Enom TEST route and request interval accepted':' · Enom TEST Purchase route and interval accepted';}
                elseif(EnomTestRegistration::deploymentEnabled()||\Paymenter\Extensions\Servers\Domains\Services\EnomTestTransfer::deploymentEnabled()){$text.=config('domains.enom_writes.transfer')===true?' · Enom TEST route and request interval not accepted':' · Enom TEST Purchase route and interval not accepted';}}
            if($a->registrar_key==='enom'&&$a->environment==='live'){
                $protocol=\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::registrationAcceptance($binding);
                $text.=($accepted->registrationRoute===$protocol['route']&&$accepted->registrationIntervalSeconds===$protocol['interval_seconds'])?' · Enom live Purchase route and interval accepted':' · Enom live Purchase route and interval not accepted';
            }
            return $text;}
        catch(\Throwable){return 'Unavailable · new sales held';}
    }
    /** For sale or Not for sale, with the reason it is not for sale. */
    public static function salesState(Tld $t, ?StaffGrants $grants = null): array
    {return self::snapshot($t,$grants)['sales'];}
    private static function saleStatus(Tld $t, $saved, ?\Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance $accepted, ?string $ruleHash, array $rows, \Closure $seller): array
    {
        $off=static fn(string $reason):array=>['label'=>'Not for sale','tone'=>'gray','reason'=>$reason];
        $flagged=static fn(string $flag):bool=>collect($rows)->contains(fn($r)=>in_array($flag,$r['flags'],true));
        if($flagged('price_missing')){return $off('Price missing');}
        if(!$t->enabled||(!$t->register_enabled&&!$t->transfer_enabled)){return $off('Stopped by staff');}
        if($seller()===null){return $off('No registrar selected');}
        if(!$t->renew_enabled){return $off('Renewals off for this registrar');}
        $action=$t->register_enabled?'register':'transfer';$term=$action==='register'?$t->min_years:1;
        $price=static fn($kind,$years)=>$saved->first(fn($p)=>$p->action===$kind&&$p->term===$years&&$p->currency==='USD');
        $renew=$price('renew',1);$sale=$price($action,$term);
        try{if(!$accepted||$accepted->binding->serverId!==$t->server_id||$accepted->binding->registrarKey!==$t->registrar_key
                ||$accepted->binding->fingerprint!==$t->account_fingerprint){throw new \DomainException;}
            \Paymenter\Extensions\Servers\Domains\Services\PublicationEligibility::requireEvidence($t,$action,$term,$accepted,$renew,$sale);
        }catch(\Throwable){return $off('Sync TLD prices needed');}
        try{
            $now=now()->toDateTimeImmutable();
            PriceCatalogue::currentEvidence($renew,$ruleHash,$now);PriceCatalogue::currentEvidence($sale,$ruleHash,$now);
            return ['label'=>'For sale','tone'=>'success','reason'=>null];
        }catch(\Throwable){
            // Saving prices publishes them; a TLD still on an older rule missed a default change because its costs were out of date.
            if($flagged('unpublished')){return $off('Prices not published');}
            if($flagged('cost_missing')){return $off('Cost missing');}
            if(collect($rows)->contains(fn($r)=>!$r['fresh'])){return $off('Prices out of date');}
            return $off($flagged('below_cost')?'Below cost':'Price missing');
        }
    }
    /** Stored history labels, which are the action names staff saw, mapped to what changed. */
    private const CHANGES=['Edit default rule'=>'Default pricing changed','Save TLD override'=>'Prices changed','Use default rule'=>'Switched to the account default',
        'Select seller'=>'Registrar for new orders changed','Publish prices'=>'Prices published','Review TLD availability'=>'Terms changed',
        'Review lifecycle facts'=>'Grace and redemption changed'];
    /** Newest first: time, change, reason and who made it. Null when the saved history can't be read. */
    public static function history(int $serverId,?int $tldId=null): ?array
    {
        try{
            $rows=\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::historyRows(\App\Models\Server::findOrFail($serverId));
            if($tldId){$rows=array_filter($rows,fn($r)=>!$r['tld_id']||$r['tld_id']===$tldId);}
            return array_map(static function($r){
                $change=self::CHANGES[$r['action']]??$r['action'];
                $phase=(string)$r['phase'];
                if(str_ends_with($phase,'_requested')){$change.=': started';}elseif(str_ends_with($phase,'_refused')){$change.=': refused';}elseif($phase==='publication_completed'){$change.=': publishing finished';}
                try{$at=\Carbon\CarbonImmutable::parse((string)$r['at'])->utc()->format('M j, Y H:i').' UTC';}catch(\Throwable){$at=(string)$r['at'];}
                return ['time'=>$at,'change'=>$change,'reason'=>(string)$r['reason'],'by'=>'Staff #'.$r['actor_id']];
            },array_reverse(array_values($rows)));
        }catch(\Throwable){return null;}
    }
    /** Plain causes for the stored catalogue failure codes. */
    public const CAUSES=['read_failed'=>'Registrar read failed','read_unavailable'=>'Registrar read failed',
        'read_exception'=>'Unexpected error during the read','read_deadline'=>"Registrar didn't answer in time",
        'read_no_evidence'=>'Registrar returned no prices','read_identity_changed'=>'Registrar account changed during the read',
        'account_unavailable'=>'Registrar account not found','binding_mismatch'=>'Registrar account changed',
        'configuration_unverified'=>"Couldn't verify the registrar account settings",
        'encryption_unavailable'=>"Can't read saved credentials. Check the app encryption key",'storage_unavailable'=>'Database error',
        'catalogue_reader_unavailable'=>"Couldn't load the registrar account's settings",
        'catalogue_acceptance_unavailable'=>'Supported TLDs not saved for this account','catalogue_acceptance_changed'=>'Supported TLDs changed during the sync',
        'catalogue_evidence_missing'=>'Registrar returned no prices for this TLD',
        'catalogue_evidence_invalid'=>'Registrar returned prices for terms this account does not support',
        'catalogue_import_failed'=>"Couldn't save the new prices",'catalogue_interrupted'=>'Sync stopped before it finished'];
    public static function refreshState(int $serverId): string
    {
        $failure=self::syncFailure($serverId);
        return $failure?'Sync failed: '.$failure['cause']:'Synced';
    }
    /** Latest saved price time for an account, the time of its last good sync. */
    public static function lastPrices(int $serverId): ?\Carbon\CarbonImmutable
    {
        $at=Price::where('server_id',$serverId)->max('observed_at');
        return $at===null?null:\Carbon\CarbonImmutable::parse($at)->utc();
    }
    /** The current price sync failure: an active hold, or a retained failure newer than the latest saved prices.
     * Returns the plain cause and the failure time (null when the stored failure has no time). */
    public static function syncFailure(int $serverId, ?\Carbon\CarbonImmutable $lastPrices = null): ?array
    {
        $row=self::queue($serverId);$facts=self::refreshFacts($serverId,$row);
        if($facts===null){return null;}
        try{$at=isset($facts['at'])&&is_string($facts['at'])?\Carbon\CarbonImmutable::parse($facts['at'])->utc():null;}catch(\Throwable){$at=null;}
        if($row->catalogue_hold===null){
            $last=$lastPrices??self::lastPrices($serverId);
            if($at===null||($last!==null&&$last>=$at)){return null;}
        }
        return ['cause'=>self::CAUSES[$facts['code']]??self::CAUSES['read_exception'],'at'=>$at===null?null:$at->format('M j, Y H:i').' UTC'];
    }
    private static function queue(int $serverId): ?object
    {
        if(!\Illuminate\Support\Facades\Schema::hasTable('domains_observation_queues')){return null;}
        return \Illuminate\Support\Facades\DB::table('domains_observation_queues')->where('server_id',$serverId)->first();
    }
    private static function refreshCode(?string $code): ?string
    { return $code===null?null:(in_array($code,['read_unavailable',...\Paymenter\Extensions\Servers\Domains\Services\ReadFailure::CODES],true)?$code:'read_exception'); }
    /** Active hold or last retained failure after resume/success. at is the retained failure time, not refresh time. */
    public static function refreshFacts(int $serverId, ?object $row = null): ?array
    {
        $row??=self::queue($serverId);
        if(!$row){return null;}
        try{$audit=json_decode($row->catalogue_audit??'[]',true,8,JSON_THROW_ON_ERROR);}
        catch(\JsonException){$audit=[];}
        if(!is_array($audit)){$audit=[];}
        foreach(array_reverse($audit) as $failure){
            if(in_array($failure['code']??null,\Paymenter\Extensions\Servers\Domains\Services\ReadFailure::CODES,true)
                &&($row->catalogue_hold===null||$failure['code']===$row->catalogue_hold)){
                return array_intersect_key($failure,array_flip(['code','server_id','account_fingerprint','at']));
            }
        }
        return $row->catalogue_hold===null?null:['code'=>self::refreshCode($row->catalogue_hold),'server_id'=>(int)$row->server_id,
            'account_fingerprint'=>$row->account_fingerprint,'at'=>null];
    }
}
