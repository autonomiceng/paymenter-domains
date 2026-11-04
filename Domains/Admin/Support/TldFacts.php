<?php
// Adapted from Enom 444f31c TldResource price preview; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Price,SalesPolicy,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestRegistration};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** Saved facts only. No reader factory, credentials or provider calls. */
final class TldFacts
{
    public const AUDIT = \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::KEY;
    /** A sync read this term and the registrar has no cost for it, so it does not sell it. Nothing to do. */
    public const NOT_OFFERED='Not offered';
    /** Why a TLD is not for sale when no term has current prices. */
    public const NEEDS_SYNC='Needs a price sync';
    public static function bound($query)
    {
        return $query->whereExists(fn($q)=>$q->selectRaw('1')->from('domains_accounts as a')
            ->join('extensions as s','s.id','=','a.server_id')->where('s.extension','Domains')->where('s.type','server')
            ->whereColumn('a.server_id','domains_tlds.server_id')->whereColumn('a.registrar_key','domains_tlds.registrar_key')
            ->whereColumn('a.account_fingerprint','domains_tlds.account_fingerprint'));
    }
    public static function registrar(string $key): string { return ['enom'=>'Enom','namesilo'=>'NameSilo'][$key]??\Paymenter\Extensions\Servers\Domains\Presentation\Missing::LABEL; }
    public static function holders(Tld $tld): int
    { return Domain::where(['server_id'=>$tld->server_id,'tld'=>$tld->tld,'account_fingerprint'=>$tld->account_fingerprint])->whereNotNull('reservation_name')->count(); }
    /** Server id of the account that takes new orders for this TLD, or null when none is selected. */
    public static function sellerId(string $tld): ?int
    { $id=SalesPolicy::where('tld',$tld)->value('server_id');return $id===null?null:(int)$id; }
    public static function prices(Tld $tld, ?StaffGrants $grants = null): array
    {return self::snapshot($tld,$grants)['prices'];}
    /** One server-only snapshot per row/render; never retain across requests or use as write authority.
     * Each row: action, term, retail (null when missing), flags (below_cost, cost_missing, not_offered, price_missing, needs_sync, unpublished),
     * fresh, observed and until; cost only with the costs permission. */
    private static function catalogue(): \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue
    { return \Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue::routed() ?? throw new \DomainException(\Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade::MESSAGE); }
    public static function snapshot(Tld $tld, ?StaffGrants $grants=null, ?\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue $catalogue=null): array
    { return ($catalogue??self::catalogue())->page(collect([$tld]))[$tld->id]; }
    /** snapshot() for a page of TLD rows in a fixed number of reads: accounts, acceptances and default rules once per
     * account, including card fees; prices, sellers and domain counts once per page. Keyed by TLD id, each with 'seller' and 'holders' added. */
    public static function page(\Illuminate\Support\Collection $tlds, ?StaffGrants $grants=null, ?\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue $catalogue=null): array
    { return ($catalogue??self::catalogue())->page($tlds); }


    /** "Default pricing", "Fixed price" or "Custom markup" for a TLD row. */
    public static function pricing(Tld $t): string
    { $catalogue=self::catalogue();return !$catalogue->own($t)?'Default pricing':($catalogue->pricing((int)$t->server_id,$t)['mode']==='fixed'?'Fixed price':'Custom markup'); }
    /** Label from the already selected legacy page; no mode query per table row. */

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
        $catalogue=self::catalogue();$t=(clone $t)->forceFill(['min_years'=>$catalogue->setting($t,'min_years'),'max_years'=>$catalogue->setting($t,'max_years')]);
        $years=static fn(int $n):string=>$n.' '.($n===1?'year':'years');
        return $t->min_years===$t->max_years?$years((int)$t->min_years):$t->min_years.' to '.$years((int)$t->max_years);
    }
    private const DANGER='fi-color fi-color-danger fi-text-color-600 dark:fi-text-color-400';
    private static function missing(string $cause): string
    { return '<span class="domains-strong '.self::DANGER.'">'.e(\Paymenter\Extensions\Servers\Domains\Presentation\Missing::LABEL).'</span><div class="domains-small">'.e($cause).'</div>'; }
    private static function notOffered(): string
    { return '<span class="domains-quiet">'.e(self::NOT_OFFERED).'</span>'; }
    private static function cell(string $html, bool $nowrap = true): string
    { return '<td'.($nowrap?' class="domains-nowrap"':'').'>'.$html.'</td>'; }
    /** Prices by registrar account: one row per account that sells this TLD. */
    public static function accountsTable(Tld $t, StaffGrants $grants): HtmlString
    {
        $rows=self::bound(Tld::query()->where('tld',$t->tld))->with('server:id,name')->orderBy('server_id')->get();
        if($rows->isEmpty()){return new HtmlString('No prices yet');}
        $facts=self::page($rows,$grants);
        $head=['Account','Pricing','Registration','Renewal','Transfer',...($grants->costs?['Cost']:[]),'Domains'];
        $html='<div class="domains-table-wrap"><table class="domains-table"><thead><tr>';
        foreach($head as $label){
            $cell=$label==='Cost'?HelpHint::beside(e($label),'Registrations and transfers below cost are held. Renewals are billed.','About cost')->toHtml():e($label);
            $html.='<th scope="col">'.$cell.'</th>';
        }
        $html.='</tr></thead><tbody>';
        foreach($rows as $row){
            $fact=$facts[$row->id];$prices=$fact['prices'];
            $first=static fn(string $action)=>collect($prices)->where('action',$action)->sortBy('term')->first();
            $price=static fn(?array $p)=>match(true){
                ($p['retail']??null)===null&&in_array('price_missing',$p['flags']??[],true)=>self::missing('Check the registrar account and read prices again.'),
                $p===null||($p['retail']===null&&!$p['synced'])=>self::missing('Use Sync prices on the TLD list.'),
                $p['retail']!==null=>e($p['retail']),
                in_array('not_offered',$p['flags'],true)=>self::notOffered(),
                default=>self::missing('Use Edit prices.'),
            };
            // Sync never fills a price for sales that are off.
            $transfer=$first('transfer');$catalogue=self::catalogue();$transfer=!$catalogue->setting($row,'transfer')&&($transfer['retail']??null)===null?'Off':$price($transfer);
            $name=(string)$row->server?->name;
            $account=(RegistrarFacts::accountLink((int)$row->server_id,$name)?->toHtml()??e($name)).($fact['demo']?' '.DemoData::tag(inline:true)->toHtml():'')
                .($fact['seller']===(int)$row->server_id?' <span class="domains-table-tag">New orders</span>':'');
            $html.='<tr>'.self::cell($account,false).self::cell(e(self::pricing($row)))
                .self::cell($price($first('register'))).self::cell($price(self::renewal($prices))).self::cell($transfer);
            if($grants->costs){
                $renew=self::renewal($prices);$note=self::costNote($renew);
                $html.=self::cell(($renew['cost']??null)===null?(in_array('not_offered',$renew['flags']??[],true)?self::notOffered():self::missing('Use Sync prices on the TLD list.'))
                    :($note?'<span class="'.self::DANGER.'">'.e($renew['cost']).'</span><div class="domains-small '.self::DANGER.'">'.e($note).'</div>':e($renew['cost'])));
            }
            $html.=self::cell(e((string)$fact['holders'])).'</tr>';
        }
        return new HtmlString($html.'</tbody></table></div>');
    }

    /** Saving What you sell records how orders are sent. Older saves catch up with one save, only where orders are offered. */

    /** The one fix for each reason a TLD with a saved price is not for sale. */
    public const FIXES=['Cost out of date'=>'Use Sync prices.','Cost missing'=>'Use Sync prices.','Prices not published'=>'Open Edit prices and save.','Below cost'=>'Raise the price in Edit prices.','Price missing'=>'Open Edit prices and save.','Prices need checking'=>'Check the registrar account and read prices again.'];
    /** For sale or Not for sale, with the reason it is not for sale. */
    public static function salesState(Tld $t, ?StaffGrants $grants = null): array
    {return self::snapshot($t,$grants)['sales'];}

    /** Stored history labels, which are the action names staff saw, mapped to what changed. */
    private const CHANGES=['Edit card fee'=>'Card fee changed','Edit default rule'=>'Default pricing changed','Edit default pricing'=>'Default pricing changed','Save TLD override'=>'Prices changed','Use default rule'=>'Switched to Default pricing',
        'Select seller'=>'Registrar for new orders changed','Change registrar for new orders'=>'Registrar for new orders changed','Publish prices'=>'Prices published','Review TLD availability'=>'Terms changed',
        'Review lifecycle facts'=>'Grace and redemption changed','Sync TLD prices'=>'Prices synced'];
    /** Large edits omit detail maps; older entries also omit whether the default changed. Neither proves a TLD was unaffected. */
    private static function unknownSalesScope(array $input): bool
    {return !isset($input['rows'],$input['changes'])||!array_key_exists('rule_changed',$input);}
    /** Newest first: time, change, reason and who made it. Null when the saved history can't be read. */
    public static function history(int $serverId,?int $tldId=null): ?array
    {
        try{
            $ending=null;
            $rows=\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::historyRows(\App\Models\Server::findOrFail($serverId));
            if($tldId){
                // A price sync is saved for the account; show it only on the TLDs it synced.
                $ending=Tld::whereKey($tldId)->value('tld');
                $rows=array_filter($rows,static function($r)use($tldId,$ending):bool {
                    if($r['tld_id']){return $r['tld_id']===$tldId;}
                    if($r['action']==='Sync TLD prices'){return is_string($ending)&&(($r['input']['ending']??null)===$ending||in_array($ending,(array)($r['input']['endings']??[]),true));}
                    if($r['action']==='Edit what you sell'){return is_string($ending)&&(self::unknownSalesScope($r['input'])||isset($r['input']['rows'][$ending])||array_key_exists($ending,(array)($r['input']['changes']??[]))||($r['input']['rule_changed']??false));}
                    return in_array($r['action'],['Edit card fee','Edit default rule','Edit default pricing','Publish prices'],true);
                });
            }
            // One row per sync, not one per TLD it wrote.
            $rows=array_filter($rows,fn($r)=>$r['phase']!=='catalogue_imported');
            $names=Staff::can('admin.users.view')?\App\Models\User::whereIn('id',array_column($rows,'actor_id'))->get(['id','first_name','last_name','email'])->mapWithKeys(fn($u)=>[$u->id=>trim($u->first_name.' '.$u->last_name)?:$u->email])->all():[];
            return array_map(static function($r)use($names,$tldId,$ending){
                $change=self::CHANGES[$r['action']]??$r['action'];
                if($r['action']==='Sync TLD prices'&&is_string($r['input']['ending']??null)){$change.=' for .'.$r['input']['ending'];}
                $detail=[];
                if($r['action']==='Edit call limits'){
                    foreach(['day'=>'Daily calls','minute'=>'Calls per minute'] as $key=>$label){
                        $old=$r['before']['call_limits'][$key]??null;$new=$r['after']['call_limits'][$key]??null;
                        if($old!==$new){$detail[]=$label.': '.($old??'Missing').' → '.($new??'Missing');}
                    }
                }
                if($r['action']==='Edit what you sell'){
                    $change='Sales settings changed';$input=$r['input'];
                    $edited=$tldId?array_intersect_key((array)($input['rows']??[]),[$ending=>true]):(array)($input['rows']??[]);
                    foreach(array_slice($edited,0,5,true) as $name=>$row){
                        $new=$row['new'];$detail[]='.'. $name.': '.match($new['choice']){'sell'=>'Sell','skip'=>"Don't sell",default=>'Use default rule'}.'; '.($new['longest']===null?'default term':'up to '.$new['longest'].' years').'; transfers '.($new['transfer']===null?'follow default':($new['transfer']?'on':'off'));
                    }
                    if(($input['rule_changed']??false)&&isset($input['rule'])){$rule=$input['rule'];$detail[]='Default: '.($rule['mode']==='only'?"don't sell new TLDs":'sell new TLDs').'; '.($rule['max_cost']===null?'no cost limit':'cost limit USD '.$rule['max_cost']).'; up to '.$rule['years'].' years; transfers '.($rule['transfers']?'on':'off');}
                    if($detail===[]&&is_string($ending)&&array_key_exists($ending,(array)($input['changes']??[]))){$detail[]='.'. $ending.': '.($input['changes'][$ending]===null?'New sales stopped':'Sales terms updated');}
                    if(self::unknownSalesScope($input)&&$edited===[]&&(!is_string($ending)||!array_key_exists($ending,(array)($input['changes']??[])))){
                        $counts=$input['counts']??[];$count=max((int)($counts['rows']??0),(int)($counts['added']??0)+(int)($counts['removed']??0),(int)($counts['cleared']??0));
                        if($count>0){$change='Sales changed for '.$count.' TLDs';}
                        $detail[]='For this account; affected TLDs were not recorded.';
                    }
                }
                $phase=(string)$r['phase'];
                if(str_ends_with($phase,'_requested')){$change.=': started';}elseif(str_ends_with($phase,'_refused')){$change.=': refused';}elseif($phase==='publication_completed'){$change.=': publishing finished';}
                try{$at=\Carbon\CarbonImmutable::parse((string)$r['at'])->utc()->toIso8601String();}catch(\Throwable){$at=(string)$r['at'];}
                return ['time'=>$at,'change'=>$change,'reason'=>implode('. ',[...$detail,...($r['reason']===''?[]:[(string)$r['reason']])]),'by'=>$r['actor_id']===null?'System':($names[$r['actor_id']]??'Staff #'.$r['actor_id'])];
            },array_reverse(array_values($rows)));
        }catch(\Throwable){return null;}
    }
    /** Plain causes for the stored catalogue failure codes. */
    public const CAUSES=['read_failed'=>'Registrar read failed','read_unavailable'=>'Registrar read failed',
        'read_exception'=>'Unexpected error during the read','read_deadline'=>"Registrar didn't answer in time",
        'read_no_evidence'=>'Registrar returned no prices','read_identity_changed'=>'Registrar account changed during the read',
        'account_unavailable'=>'Registrar account not found','binding_mismatch'=>'Registrar account changed',
        'configuration_unverified'=>"Couldn't verify the registrar account settings",
        'encryption_unavailable'=>"Can't read saved credentials. Ask your site admin",'storage_unavailable'=>'Database error',
        'catalogue_reader_unavailable'=>"Couldn't load the registrar account's settings",
        'catalogue_acceptance_unavailable'=>'What you sell not saved for this account','catalogue_acceptance_changed'=>'What you sell changed during the sync',
        'catalogue_evidence_missing'=>'Registrar returned no prices for this TLD',
        'catalogue_evidence_invalid'=>'Registrar returned prices for terms this account does not support',
        'catalogue_call_capacity'=>"Our call limit cannot cover the price sync",
        'catalogue_import_failed'=>"Couldn't save the new prices",'catalogue_interrupted'=>'Sync stopped before it finished'];
    public static function syncCause(?string $code): string { return self::CAUSES[$code]??self::CAUSES['read_exception']; }
    public static function refreshState(int $serverId): string
    {
        $failure=self::syncFailure($serverId);
        return $failure?'Sync failed: '.$failure['cause']:'Synced';
    }
    /** Sales stop at this saved-cost age; a sync restores eligible prices. */
    public const SYNC_WARN_DAYS=PriceCatalogue::MAX_COST_AGE_SECONDS/86400;
    /** When this TLD's costs were last read, from its saved price rows. Null when none were. */
    public static function lastSynced(array $prices): ?\Carbon\CarbonImmutable
    {
        $at=collect($prices)->where('synced',true)->pluck('observed')->filter()->max();
        return $at===null?null:\Carbon\CarbonImmutable::parse($at)->utc();
    }
    /** Latest saved price time for an account, the time of its last good sync. */
    public static function lastPrices(int $serverId): ?\Carbon\CarbonImmutable
    { $at=self::catalogue()->progress($serverId)['succeeded_at']??null;return $at===null?null:\Carbon\CarbonImmutable::createFromTimestamp((int)$at); }
    /** The current price sync failure: an active hold, or a retained failure newer than the latest saved prices.
     * Returns the plain cause and the failure time (null when the stored failure has no time). */
    public static function syncFailure(int $serverId, ?\Carbon\CarbonImmutable $lastPrices=null): ?array
    { $s=self::catalogue()->progress($serverId);return empty($s['failure'])?null:['cause'=>self::syncCause($s['failure']),'at'=>isset($s['failed_at'])?gmdate('M j, Y H:i',(int)$s['failed_at']).' UTC':null]; }
    /** The plain cause for a failure code. A call-capacity failure names the calls needed and left when we know them.
     * The TLD list and the staff alert use the same words; the alert keeps the numbers from when it fired. */
    public static function currentCause(int $serverId, ?string $code): string { return self::syncCause($code); }
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
            if(($failure['kind']??null)==='price_sync'&&$row->catalogue_hold===null){
                // Automatic retry and recovery are quiet. A partial price update cannot clear an account failure.
                return ($failure['state']??null)==='failed'?array_intersect_key($failure,array_flip(['kind','code','server_id','account_fingerprint','at'])):null;
            }
            if(in_array($failure['code']??null,\Paymenter\Extensions\Servers\Domains\Services\ReadFailure::CODES,true)
                &&($row->catalogue_hold===null||$failure['code']===$row->catalogue_hold)){
                return array_intersect_key($failure,array_flip(['code','server_id','account_fingerprint','at']));
            }
        }
        return $row->catalogue_hold===null?null:['code'=>self::refreshCode($row->catalogue_hold),'server_id'=>(int)$row->server_id,
            'account_fingerprint'=>$row->account_fingerprint,'at'=>null];
    }
}
