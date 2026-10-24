<?php
// Adapted from Enom 444f31c TldResource price preview; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Price,SalesPolicy,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,EnomTestRegistration,PriceCatalogue,PricingRule};
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
    public static function seller(Tld $tld): string
    {
        $p=SalesPolicy::where('tld',$tld->tld)->first();
        return !$p?->server_id?'No seller':self::registrar((string)$p->registrar_key).' · account #'.$p->server_id.' · revision '.$p->revision;
    }
    public static function prices(Tld $tld, ?StaffGrants $grants = null): array
    {return self::snapshot($tld,$grants)['prices'];}
    /** One server-only snapshot per row/render; never retain across requests or use as write authority. */
    public static function snapshot(Tld $tld, ?StaffGrants $grants = null): array
    {
        $grants ??= Staff::requireView();
        $out=[];
        try{$accepted=CatalogueAcceptances::load(CatalogueAcceptances::binding(Account::where('server_id',$tld->server_id)->firstOrFail()));}catch(\Throwable){$accepted=null;}
        try{$ruleHash=hash('sha256',json_encode((new \Paymenter\Extensions\Servers\Domains\Services\PricingRules)->rule($tld),JSON_THROW_ON_ERROR));}catch(\Throwable){$ruleHash=null;}
        $saved=Price::where(['server_id'=>$tld->server_id,'account_fingerprint'=>$tld->account_fingerprint,'tld'=>$tld->tld])->orderBy('action')->orderBy('term')->get();
        foreach($saved as $p){
            $fresh=$p->observed_at<=now()&&$p->fresh_until&&$p->fresh_until>now();
            $row=['action'=>['register'=>'Registration','renew'=>'Renewal','transfer'=>'Transfer'][$p->action]??'Unavailable',
                'term'=>$p->term.' '.($p->term===1?'year':'years'),'retail'=>self::money($p->retail),
                'revision'=>(string)$p->revision,'freshness'=>$fresh?'Current':'Stale',
                'warning'=>match($p->hold_reason??($p->eligibility['staff_alert']??null)){
                    'below_cost'=>$p->action==='renew'?'Below cost · billed; staff alert':'Below cost · held; staff alert',
                    'unknown_price'=>'Cost unknown · '.($p->action==='renew'?'retained retail; staff alert':'held'),
                    null=>'None',default=>'Review required'},
            ];
            if($p->wholesale===null){$row['warning']='Cost unknown · '.($p->action==='renew'?'retained retail; staff alert':'held');}
            if(($p->eligibility['price_unavailable']??false)===true){$row['warning']='Price unavailable · '.($p->action==='renew'?'retained renewal retail':'off');if($p->action!=='renew'){$row['retail']='Price unavailable';}}
            if($p->action!=='renew'&&($accepted===null||($p->eligibility['catalogue_revision']??null)!==$accepted->revision)){$row['warning'].=' · Account acceptance changed; new sales held pending import';}
            if($ruleHash===null||($p->eligibility['rule_hash']??null)!==$ruleHash){$row['warning'].=' · Rule changed · publish required';}
            $row['warning']=trim(str_replace('None · ','',$row['warning']));
            if($grants->costs){
                $row['cost']=$p->wholesale===null?(($p->eligibility['price_unavailable']??false)?'Price unavailable':'Unknown'):self::money($p->wholesale,4);
                $row['margin']=$p->wholesale===null||$p->retail===null?'Unknown':self::exactMoney(PricingRule::decimal(PricingRule::units(PriceCatalogue::retail($p->retail),2)*100-PricingRule::units(PriceCatalogue::cost($p->wholesale)),4));
                $row['source']=$p->source.' · '.match($p->eligibility['source_unit']??$p->unit){'exact_term_annual_rate'=>'annual rate for exact term','annual'=>'annual rate',default=>'term total'};
                $row['observed']=$p->observed_at->format('Y-m-d H:i').' UTC';
                $row['until']=$p->fresh_until?->format('Y-m-d H:i').' UTC';
            }
            $out[]=$row;
        }
        return ['prices'=>$out,'sales'=>self::saleStatus($tld,$saved,$accepted,$ruleHash,$out)];
    }
    public static function rule(Tld $t): string
    {try{$r=(new \Paymenter\Extensions\Servers\Domains\Services\PricingRules)->rule($t);return match($r['mode']){'fixed'=>'Fixed annual retail','markup_amount'=>$r['markup'].' USD markup',default=>$r['markup'].'% markup'}.' · rounding: '.str_replace('_',' ',$r['round_up']);}catch(\Throwable){return 'Unavailable · publication held';}}
    public static function money(?string $value,int $places=2): string
    { return $value===null?'Unknown':($places===4?self::exactMoney(PriceCatalogue::cost($value)):PriceCatalogue::retail($value).' USD'); }
    private static function exactMoney(string $value): string
    { return preg_replace('/(\.\d{2}\d*?)0+$/D','$1',$value).' USD'; }
    public static function table(Tld $tld, ?StaffGrants $grants = null, ?array $rows = null): HtmlString
    {
        $grants??=Staff::requireView();
        $rows??=self::prices($tld,$grants); if(!$rows){return new HtmlString('No saved prices. Unknown cost is never zero.');}
        $head=['action'=>'Action','term'=>'Term','retail'=>'Your price','revision'=>'Revision'];
        $html='<div style="max-width:100%;overflow-x:auto"><table style="width:100%;border-collapse:collapse"><caption style="text-align:left;padding:8px">Term totals before tax. Cost and retail use the same term.</caption><thead><tr>';
        foreach($head as $label){$html.='<th scope="col" style="padding:8px;text-align:left;white-space:nowrap">'.e($label).'</th>';}$html.='</tr></thead><tbody>';
        foreach($rows as $row){
            $html.='<tr>';foreach($head as $key=>$label){$html.='<td style="padding:8px;white-space:nowrap;vertical-align:top">'.e($row[$key]??'Unknown').'</td>';}$html.='</tr>';
            $detail='';
            if($grants->costs){$detail='Cost <span style="white-space:nowrap">'.e($row['cost']).'</span> · Margin <span style="white-space:nowrap">'.e($row['margin']).'</span><br>';}
            if($row['warning']!=='None'){$detail.='Needs attention: '.e($row['warning']).'<br>';}
            $detail.='Cost '.strtolower($row['freshness']);
            if($grants->costs){$detail.=' · '.e($row['source']).'<br>Observed '.e($row['observed']).' · fresh until '.e($row['until']??'Unknown');}
            $html.='<tr><td colspan="4" style="padding:0 8px 16px;font-size:0.875rem">'.$detail.'</td></tr>';
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
            return $text;}
        catch(\Throwable){return 'Unavailable · new sales held';}
    }
    public static function salesState(Tld $t, ?StaffGrants $grants = null): string
    {return self::snapshot($t,$grants)['sales'];}
    private static function saleStatus(Tld $t, $saved, ?\Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance $accepted, ?string $ruleHash, array $rows): string
    {
        if($saved->contains(fn($p)=>($p->eligibility['price_unavailable']??false)===true)){return 'Price unavailable · registration and transfer off';}
        if(!$t->enabled||(!$t->register_enabled&&!$t->transfer_enabled)){return 'New sales paused';}
        if(!$t->renew_enabled){return 'New sales held · renewal is turned off. Enable Annual renewal catalogue support to sell this ending.';}
        $action=$t->register_enabled?'register':'transfer';$term=$action==='register'?$t->min_years:1;
        $price=static fn($kind,$years)=>$saved->first(fn($p)=>$p->action===$kind&&$p->term===$years&&$p->currency==='USD');
        $renew=$price('renew',1);$sale=$price($action,$term);
        try{if(!$accepted||$accepted->binding->serverId!==$t->server_id||$accepted->binding->registrarKey!==$t->registrar_key
                ||$accepted->binding->fingerprint!==$t->account_fingerprint){throw new \DomainException;}
            \Paymenter\Extensions\Servers\Domains\Services\PublicationEligibility::requireEvidence($t,$action,$term,$accepted,$renew,$sale);
        }catch(\Throwable){return 'New sales held · pending import or acceptance';}
        try{
            $now=now()->toDateTimeImmutable();
            PriceCatalogue::currentEvidence($renew,$ruleHash,$now);PriceCatalogue::currentEvidence($sale,$ruleHash,$now);
            return 'Price eligible · check selected seller';
        }catch(\Throwable){
            $text=implode(' ',array_column($rows,'warning'));
            if(str_contains($text,'Rule changed')){return 'New sales held · rule changed, publish required';}
            if(collect($rows)->contains(fn($r)=>str_contains($r['warning'],'Cost unknown'))){return 'New sales held · cost unknown';}
            if(collect($rows)->contains(fn($r)=>$r['freshness']==='Stale')){return 'New sales held · cost stale';}
            return str_contains($text,'below cost')||str_contains($text,'Below cost')?'New sales held · below cost':'New sales held · price unavailable';
        }
    }
    public static function history(int $serverId,?int $tldId=null): array
    {
        try{
            $rows=\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::historyRows(\App\Models\Server::findOrFail($serverId));
            if($tldId){$rows=array_filter($rows,fn($r)=>!$r['tld_id']||$r['tld_id']===$tldId);}
            return array_map(static function($r){
                $prices=[];foreach($r['after']['prices']??[] as $p){$before=collect($r['before']['prices']??[])->firstWhere('id',$p['id']);$prices[]='#'.$p['id'].' r'.($before['revision']??'?').' / '.self::money($before['retail']??null).' → r'.$p['revision'].' / '.self::money($p['retail']??null);}
                return $r['at'].' · staff #'.$r['actor_id'].' · '.($r['tld_id']?'TLD #'.$r['tld_id']:'Account').' · '.$r['action'].' · '.str_replace('_',' ',$r['phase']).' · '.$r['reason'].($r['action']==='Publish prices'?' · '.implode('; ',$prices):'');
            },array_reverse(array_values($rows)));
        }catch(\Throwable){return ['History unavailable · changes held'];}
    }
    public static function refreshState(int $serverId): string
    {
        if(!\Illuminate\Support\Facades\Schema::hasTable('domains_observation_queues')){return 'No refresh result saved';}
        $row=\Illuminate\Support\Facades\DB::table('domains_observation_queues')->where('server_id',$serverId)->first();
        return $row?->catalogue_hold!==null?'Refresh failed or interrupted · saved prices retained':'No failed refresh recorded';
    }
}
