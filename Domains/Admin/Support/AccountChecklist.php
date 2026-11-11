<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Admin\Pages\Settings;
use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource,TldResource};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Price};
use Paymenter\Extensions\Servers\Domains\Presentation\{AccountStatus,Missing};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,DomainBilling,EnomLiveActivation,EnomTestManagement,EnomTestRenewal,EnomTestTransfer,NativePaidHooks,NativeServerGuard,NativeServiceRefusal,UnpaidHoldSetting,
    RenewalBillingSettings,RuntimePorts,WatchWork,CardFee,PricingRules,PricingRule};
/** Rows for a registrar account's sections and System checks.
 * A row is {label, value (null = Missing), tone, cause, help, action}; a cause shows under its value.
 * action names a SettingsActions setup action. A value of On or Off shows as a switch. Every row says what it is in help. */
final class AccountChecklist
{
    /** The worker runs every minute; no run for this long means it stopped. */
    public const WORKER_STALE_SECONDS=900;
    public const TRANSFER_LABEL='Transfers (Experimental)';
    private const PRICES='Prices refresh from the registrar once a day while the scheduler and queue worker run. Sync prices is an optional retry; it does not start background work.';
    private const PAYMENTS='Starts domain orders when an invoice is paid. If it fails, restart the queue worker.';
    private const EDITOR='Adds the domain fields and warnings to Paymenter’s service page. If it fails, update Paymenter or Domains.';
    private const WORKER='Runs background work every minute: sending orders, retries and domain checks. If it stops, start the scheduler and the queue worker.';
    private const NOT_YET='Not available yet';
    private const REGISTRATIONS='Lets customers buy domains on this account. The site sets this, so it has no switch here. Orders must be on too.';
    private const TRANSFERS='Lets customers move a domain here with an auth code, for TLDs with Transfers on in What you sell. Orders must be on too. Experimental: it has never run against a real test transfer.';
    private const RENEWALS='Can issue renewal invoices, use customer credit, try saved Auto Pay and send paid renewals. Off: no new invoices. Issued invoices stay payable, and paid renewals wait.';
    private const MANAGEMENT='Sends changes to DNS records, nameservers and contact details to the registrar. Off: those changes are not sent.';
    private static function row(string $label,?string $value,?string $tone=null,string|Htmlable|null $cause=null,?string $action=null,?string $help=null): array
    { return compact('label','value','tone','cause','action','help'); }
    private static function date(int $at): string { return gmdate('M j, Y H:i \U\T\C',$at); }
    private static function unavailable(string $label,string $why): array { return self::row($label,self::NOT_YET,null,null,null,$why); }
    /** Staff can't change the site config, so a row says who can. release-runbook.md lists what the site admin sets. */
    private static function siteOff(Account $a): string
    { return ($a->environment==='live'?'Live sales are not set up on this site yet.':'This site does not offer this yet.').' Your site admin can turn it on.'; }
    /** @return list<array> */
    public static function setup(Account $a,array $status,?\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue $offers=null): array
    {
        $check='Checks this site can log in to the registrar with the saved API details.';
        $connection=match($status['label']){AccountStatus::SET['connection_failed'][0]=>self::row('API connection','Connection failed','danger',$status['reason'],'probe',$check),
            AccountStatus::SET['not_connected'][0]=>self::row('API connection','Not connected','gray',$status['reason'],'configure',$check),
            default=>self::row('API connection','OK','success',null,'probe',$check)};
        try { $binding=AccountBindings::binding($a); } catch (\Throwable) { $binding=null; }
        $tlds=self::tlds($a,$binding,$offers);
        $rows=[$connection,$tlds,...self::pricing($a,$offers,$tlds['using_default']),self::prices($a,$binding),self::orders($a)];
        $gates=app(RuntimePorts::class)->acquisitionGates($a);
        $off=fn(?bool $gate)=>match(true){$gate===null=>'Not set up on this site yet. See System checks.',default=>self::siteOff($a)};
        $rows[]=$gates['register']===true?self::row('Registrations','On, follows Orders',null,null,null,self::REGISTRATIONS):self::unavailable('Registrations',$off($gates['register']));
        $rows[]=self::transfers($a,$binding,$gates['transfer'],$off);
        foreach(['renewal'=>['Renewals',EnomTestRenewal::class,self::RENEWALS],'management'=>['DNS, nameservers and contacts',EnomTestManagement::class,self::MANAGEMENT]] as $key=>[$label,$feature,$help]){
            if($key==='management'&&$a->registrar_key==='namesilo'){$help='Sends nameserver and contact changes to NameSilo. DNS records and DNSSEC are not offered for NameSilo.';}
            $rows[]=match(true){
                $binding===null=>[...self::unavailable($label,$help),'cause'=>'Restore the API connection first.'],
                !$feature::enabled($binding)&&!$feature::stored($binding)&&!($key==='renewal'&&RenewalBillingSettings::anyOn((int)$a->server_id))=>self::unavailable($label,self::siteOff($a)),
                default=>self::writeSwitch($a,$label,$key,$key==='renewal'?EnomTestRenewal::on($binding):$feature::stored($binding),$help)};
        }
        foreach($rows as &$row){if($row['label']==='DNS, nameservers and contacts'&&$row['value']==='On'){$row['cause']=$a->registrar_key==='namesilo'?'Staff and customers can change nameservers and contacts. DNS records and DNSSEC are not offered for NameSilo.':'Staff and customers can change these.';}}
        unset($row);
        if ($binding !== null && $a->registrar_key === 'enom') {
            $on=\Paymenter\Extensions\Servers\Domains\Services\EnomLateRenewal::stored($binding);
            $ready=\Paymenter\Extensions\Servers\Domains\Services\EnomLateRenewal::available($binding);
            $cause=$ready?'Late renewal is paused until you approve it. Turn on to allow billing for expired domains.':(EnomTestRenewal::accepted($binding)?'Ask your site admin to record Enom’s late renewal evidence.':'Late renewal is paused. Turn on Renewals first.');
            $rows[]=self::paused($a,self::row('Late renewal',$on?'On':'Off',$on?'success':null,$on?null:$cause,$ready||$on?'late_renewal':null,\Paymenter\Extensions\Servers\Domains\Services\EnomLateRenewal::HELP));
        }
        if($a->registrar_key==='namesilo'){$rows[]=self::row('Late renewal','Not supported',null,'Late renewal is not offered for NameSilo.',null,'Renew expired domains only through a registrar-supported late renewal process. NameSilo late renewal is not available here.');}
        return $rows;
    }
    /** Live stays off until activation; sent transfers keep reconciling after revocation. */
    private static function transfers(Account $a,?object $binding,?bool $gate,\Closure $off): array
    {
        if($a->environment==='live'&&$binding===null){return [...self::unavailable(self::TRANSFER_LABEL,self::TRANSFERS),'cause'=>'Restore the API connection first.'];}
        if($a->environment==='live'){
            if(!EnomTestTransfer::enabled($binding)&&!EnomTestTransfer::stored($binding)){return self::unavailable(self::TRANSFER_LABEL,self::siteOff($a));}
            return self::writeSwitch($a,self::TRANSFER_LABEL,'transfer',EnomTestTransfer::stored($binding),'Lets customers move a domain here with an auth code, for TLDs with Transfers on in What you sell. Orders must be on too.');
        }
        if($binding!==null&&(EnomTestTransfer::enabled($binding)||EnomTestTransfer::stored($binding))){$on=EnomTestTransfer::stored($binding);return self::row(self::TRANSFER_LABEL,$on?'On':'Off',$on?'success':null,self::transferCause($on),'test_transfer',self::TRANSFERS);}
        $help=match(true){$a->registrar_key!=='enom'=>self::siteOff($a),$binding===null=>'Restore the API connection first.',
            config('domains.enom_identity.environment')!=='test'=>'Set domains.enom_identity.environment to test in the site config, then reload this page.',
            config('domains.enom_catalogue.enabled')!==true=>'Set domains.enom_catalogue.enabled to true in the site config, then reload this page.',
            default=>'Set domains.enom_writes.transfer to true in the site config, then turn on Transfers here.'};
        return $gate===true?self::row(self::TRANSFER_LABEL,'On','success',self::transferCause(true),null,self::TRANSFERS):self::unavailable(self::TRANSFER_LABEL,$help);
    }
    private static function transferCause(bool $on): string
    {
        return $on?'Uses the normal transfer checkout and auth-code flow.':'Turned off by staff. Review transfer readiness, then turn on when ready.';
    }
    /** Live turns on when live sales are activated. Renewals also start invoices, so a bad calendar stops them turning on. */
    private static function writeSwitch(Account $a,string $label,string $key,bool $on,string $help): array
    {
        $live=$a->environment==='live';$action=($live?'live_':'test_').$key;
        if($key==='renewal'){$help=self::billingHelp((int)$a->server_id,$help);}
        return self::paused($a,match(true){$on=>self::row($label,'On','success',$key==='transfer'?self::transferCause(true):null,$action,$help),
            $live&&!EnomLiveActivation::current(AccountBindings::binding($a))=>self::row($label,'Off',null,'Paused until setup is approved. Use Activate to see what is missing.',null,$help),
            $key==='renewal'&&RenewalBillingSettings::read((int)$a->server_id)['issue_days']===null=>self::row($label,'Off',null,'Renewal billing is paused. Fix the invoice days first.',null,$help),
            default=>self::row($label,'Off',null,match($key){'transfer'=>self::transferCause(false),'renewal'=>'Turn on to bill and send renewals.','management'=>'Changes are paused until you approve them. Check setup, then turn on DNS, nameservers and contacts.',default=>null},$action,$help)});
    }
    private static function billingHelp(int $server,string $help): string
    {
        $billing=RenewalBillingSettings::read($server);
        return $help.(!$billing['scheduled']&&$billing['manual']?' Invoices are owner-requested only; scheduled billing is off.':'');
    }
    /** Orders stop or start every registrar request on this account. Saved credentials must match before they can start.
     * Off already says they are paused, as the page head does, so the cause gives only the next step. On has no next step,
     * so it skips the setup checks, which can build the order runtime. */
    private static function orders(Account $a): array
    {
        $on=(bool)$a->processing_enabled;
        return self::paused($a,self::row('Orders',$on?'On':'Off',$on?'success':null,$on?null:self::ordersStep($a),'processing','Lets this account send requests to the registrar. Off stops everything: new orders, renewals and changes. They wait and go out when it is back on.'));
    }
    private static function ordersStep(Account $a): string
    {
        $status=app(AccountBindings::class)->configurationStatus((int)$a->server_id);
        return match(true){$status==='site_unbound'&&$a->registrar_key==='namesilo'=>'Ask your site admin to confirm the Live site for this account.',
            $status==='site_unbound'||($status==='matched'&&$a->environment==='live'&&!EnomLiveActivation::current(AccountBindings::binding($a)))=>'Use Activate to check setup and turn on Orders.',
            $status==='matched'=>$a->registrar_key==='namesilo'?(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites::processingBlocker($a)??'Ready. Turn on Orders.'):'Check setup, then turn on Orders.',default=>'Fix the API connection first.'};
    }
    /** An Off feature needs attention when this account already manages domains. The row keeps its one line;
     * pausedChecks() lists the domains in System checks. No settings change here. */
    private static function paused(Account $a,array $row): array
    {
        if($row['value']!=='Off'||in_array($row['label'],[self::TRANSFER_LABEL],true)){return $row;}
        $domains=Domain::where('server_id',$a->server_id)->where('billing_managed',true);
        if($row['label']==='Late renewal'){
            $now=app(Clock::class)->now();
            $ids=$domains->select(['id','provider_expires_at'])->cursor()->filter(fn(Domain $domain)=>DomainBilling::expired($domain,$now))->pluck('id');
            $count=$ids->count();$first=$ids->first();
        }else{$count=$domains->count();$first=$count===1?$domains->value('id'):null;}
        if($count>0){$row['paused']=['count'=>$count,'domain'=>$count===1?(int)$first:null,'server'=>(int)$a->server_id];}
        return $row;
    }
    /** One System checks row for managed domains that wait on Off switches, linked to the domain or the account's list.
     * Each count is a subset of the account's managed domains, so the largest is the total. Late renewal counts only
     * expired domains, so beside other switches it gives its own count. @return list<array> */
    public static function pausedChecks(array $rows): array
    {
        $paused=array_values(array_filter($rows,fn(array $row)=>isset($row['paused'])));
        if(!$paused){return [];}
        $top=array_reduce($paused,fn(?array $carry,array $row)=>$carry===null||$row['paused']['count']>$carry['count']?$row['paused']:$carry);
        ['count'=>$count,'domain'=>$domain,'server'=>$server]=$top;
        $late=array_values(array_filter($paused,fn(array $row)=>$row['label']==='Late renewal'))[0]['paused']['count']??null;
        $labels=array_values(array_diff(array_column($paused,'label'),['Late renewal']));$last=array_pop($labels);
        // Switch names are plural (Orders, Renewals) except Late renewal.
        $off=match(true){$last===null=>'Late renewal is off.',
            default=>($labels?implode(', ',$labels).' and ':'').$last.' are off.'.($late===null?'':' Late renewal is also off for '.$late.' expired '.($late===1?'domain':'domains').'.')};
        try{$url=$domain!==null?DomainResource::getUrl('view',['record'=>$domain]):DomainResource::getUrl('index',['filters'=>['server_id'=>['value'=>(string)$server]]]);}catch(\Throwable){$url=null;}
        return [[...self::row('Managed domains waiting',(string)$count,'warning',$off,null,'Managed domains on this account that wait until these switches are on.'),
            'buttons'=>[Action::make('paused_domains')->label($domain!==null?'Open domain':'View domains')->link()->size('sm')->url($url)->visible($url!==null)]]];
    }
    /** A bad saved calendar can't be saved back, so this switch waits for Edit schedule. It only works while Renewals is on, so otherwise the row says so with no switch. On with a bad calendar keeps its switch: it shows what customers can still do. */
    private static function renewEarly(int $server): array
    {
        try { $a=Account::where('server_id',$server)->firstOrFail();$renewing=EnomTestRenewal::on(AccountBindings::binding($a)); } catch (\Throwable) { $renewing=false; }
        $billing=RenewalBillingSettings::read($server);$on=$renewing&&$billing['manual'];$bad=$billing['issue_days']===null;
        $help='Lets customers pay for a renewal before the invoice goes out.';$blocked=match(true){!$renewing=>'Needs Renewals on.',$bad=>'Fix the invoice days first.',default=>null};
        if($blocked!==null&&!$on){return self::row('Customer can renew early',$blocked,null,null,null,$help);}
        return self::row('Customer can renew early',$on?'On':'Off',$on?'success':null,$blocked,$blocked===null?'renew_early':null,$help);
    }
    private static function tlds(Account $a,?object $binding,?\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue $offers=null): array
    {
        try {
            $offers??=\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue::routed();$endings=[];$chosen=[];$defaults=0;
            if($offers){
                \Paymenter\Extensions\Servers\Domains\Models\Tld::where('server_id',$binding->serverId)->where('account_fingerprint',$binding->fingerprint)
                    ->where('catalogue_converted',true)->orderBy('id')->chunkById(100,function($rows)use($offers,&$endings,&$chosen,&$defaults):void {
                        $page=$offers->page($rows);
                        foreach($rows as $t){$sales=$page[$t->id]['acquisition'];if($page[$t->id]['selected']){$chosen[]=$t->tld;}if($sales['register']||$sales['transfer']){$endings[]=$t->tld;if(!$page[$t->id]['own']){$defaults++;}}}
                    });
            }
        } catch (\Throwable) { $endings=[];$chosen=[];$defaults=0; }
        sort($endings);sort($chosen);
        try { $url=WhatYouSell::getUrl(['account'=>$a->server_id]); } catch (\Throwable) { $url=null; }
        // Orders off pauses sales but keeps the saved choices, so the row shows them as paused, not missing.
        $paused=!$endings&&$chosen&&!$a->processing_enabled;$shown=$paused?$chosen:$endings;$count=count($shown);
        $cause=match(true){$paused=>$count.' '.($count===1?'TLD is':'TLDs are').' chosen. Sales are paused while Orders is off.',(bool)$endings=>$count.' '.($count===1?'TLD':'TLDs').' for sale',default=>'Choose the TLDs you sell.'};
        return [...self::row('What you sell',$shown?self::sample($shown):null,null,$cause,null,'The TLDs customers can buy from this account.'),
            'using_default'=>$defaults,'buttons'=>[Action::make('what_you_sell_'.$a->server_id)->label($shown?'Change':'Choose what you sell')->link()->size('sm')->url($url)->visible($url!==null)]];
    }
    /** Use the installed catalogue's pricing reader and its TLD override decisions. */
    private static function pricing(Account $a,?\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue $offers,int $defaults): array
    {
        $offers??=\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue::routed();
        try{$rule=$offers?->pricing((int)$a->server_id)??(new PricingRules)->defaultRule();$value=ucfirst(PricingRule::describe($rule));}catch(\Throwable){$value=null;}
        try{$fee=CardFee::describe($offers?->fee((int)$a->server_id)??CardFee::read()).' per payment';}catch(\Throwable){$fee=null;}
        return [
            [...self::row('Customer pays',$value,null,$value===null?'Default pricing could not be read. Use Edit to fix it.':'Used by '.$defaults.' '.($defaults===1?'TLD':'TLDs'),null,'The default pricing rule. A TLD can use its own rule instead.'),
                'edit'=>true,'buttons'=>[SettingsActions::defaultPricing()->name('default_pricing_customer_pays')]],
            [...self::row('Card fee',$fee,null,$fee===null?'The card fee could not be read. Use Edit to fix it.':null,null,'The estimated fee per payment used by pricing rules that include the card fee.'),
                'edit'=>true,'buttons'=>[SettingsActions::defaultPricing()->name('default_pricing_card_fee')]],
        ];
    }
    /** The TLDs people know first, then a count. The full list is on What you sell. */
    private static function sample(array $endings): string
    {
        $first=array_values(array_unique([...array_intersect(['com','net','org'],$endings),...$endings]));
        $shown=implode(', ',array_map(fn($e)=>'.'.$e,array_slice($first,0,3)));
        return count($first)>3?$shown.' and '.(count($first)-3).' more':$shown;
    }
    private static function prices(Account $a,?object $binding): array
    {
        if($binding===null){return [...self::row('TLD prices',null,null,'Restore the API connection first.',null,self::PRICES),'buttons'=>[]];}
        if ($offers=\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue::routed()) {
            $status=$offers->progress((int)$a->server_id);
            if(!empty($status['failure'])){return [...self::row('TLD prices','Sync failed','danger',TldFacts::syncCause($status['failure']).(isset($status['failed_at'])?', '.gmdate('M j, Y H:i',(int)$status['failed_at']).' UTC':'').'. Fix this cause first. Sync prices is an optional retry.',null,self::PRICES),'buttons'=>self::priceButtons($a)];}
            return [...self::row('TLD prices',ucfirst($status['state']),null,isset($status['succeeded_at'])?Sheet::moment((int)$status['succeeded_at'],'Updated'):'Not updated yet.',null,self::PRICES),'buttons'=>self::priceButtons($a)];
        }
        $failure=TldFacts::syncFailure((int)$a->server_id);
        if ($failure!==null) {
            $at=$failure['at']===null?'':', '.$failure['at'];
            $row=self::row('TLD prices','Sync failed','danger',$failure['cause'].$at.'. Fix this cause first. Sync prices is an optional retry.',null,self::PRICES);
            return [...$row,'buttons'=>self::priceButtons($a)];
        }
        $synced=RegistrarFacts::lastSync((int)$a->server_id);
        return [...self::row('TLD prices',$synced?'OK':null,$synced?'success':null,$synced?Sheet::moment($synced,'Synced'):'No price refresh has finished yet. Check Site setup and the background worker. Sync prices is an optional retry.',null,self::PRICES),'buttons'=>self::priceButtons($a)];
    }
    /** Sync covers every TLD of the account at once. Staff who can't sync can still open the TLD list. */
    private static function priceButtons(Account $a): array
    {
        try { $url=TldResource::getUrl('index'); } catch (\Throwable) { $url=null; }
        $sync=Sheet::button(SettingsActions::syncAll((int)$a->server_id));
        return [$sync,Action::make('open_tlds_'.$a->server_id)->label('Open TLDs')->link()->size('sm')->url($url)->visible(fn()=>$url!==null&&!$sync->isVisible())];
    }
    /** @return list<array> */
    public static function renewals(int $server): array
    {
        $billing=RenewalBillingSettings::read($server);$rows=[];
        foreach(['issue_days'=>['Invoice issued','How long before expiry the renewal invoice goes out.'],'due_days'=>['Invoice due','How long before expiry the customer must pay.']] as $key=>[$label,$help]){
            $rows[]=$billing[$key]===null?self::row($label,null,null,'Saved invoice days are invalid. New renewal invoices wait.','renewal_billing',$help):self::row($label,$billing[$key].' days before expiry',null,null,'renewal_billing',$help);
        }
        $rows[]=self::row('Unpaid hold',UnpaidHoldSetting::minutes($server).' minutes',null,null,'unpaid_hold',SettingsActions::details('unpaid_hold'));
        $rows[]=self::renewEarly($server);
        try{$a=Account::where('server_id',$server)->sole();$binding=AccountBindings::binding($a);
            // Use the same effective sales projection as What you sell; don't fill the preview with unsold catalogue entries.
            $sold=[];$defaultSell=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($a->catalogue_defaults)['defaultSell']??true;$offers=\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue::routed();
            $query=\Paymenter\Extensions\Servers\Domains\Models\Tld::where('server_id',$server)->where('account_fingerprint',$binding->fingerprint)->orderBy('tld');
            $fallback=(clone $query)->limit(10)->get();
            // An explicit selling stop cannot become an effective offer. Filter it before loading costs.
            (clone $query)->where(function($q)use($defaultSell):void{
                $q->where('catalogue_intent->sell',true);
                if($defaultSell){$q->orWhereNull('catalogue_intent->sell');}
            })->chunk(100,function($tlds)use($offers,&$sold):bool{
                $states=$offers?->page($tlds)??[];
                foreach($tlds as $t){$sales=$states[$t->id]['acquisition']??[];
                    if((($sales['register']??false)||($sales['transfer']??false))&&count($sold)<10){$sold[]=$t;}
                }
                return count($sold)<10;
            });
            foreach(collect($sold)->concat($fallback)->unique('id')->take(10) as $t){
                $terms=self::termReadiness($binding,$t->tld)[0];$unknown=$terms[1]==='Missing';
                $rows[]=[...self::row(($unknown?'Unknown term range ':'').'.'.$t->tld,$terms[1],null,$terms[2],null,'Registration terms confirmed for this TLD. Review the current registry rules in Edit terms.'),'buttons'=>[Sheet::link('terms_'.$t->id,$unknown?'Review TLD rules':'Edit terms',WhatYouSell::getUrl(['account'=>$server,'tld'=>$t->tld]))]];
            }
        }catch(\Throwable){/* Current setup remains visible when term facts cannot be read. */}
        $terms=TldTerms::summary($server);$attention=count($terms['attention']);
        try{$url=TldResource::getUrl('index',['filters'=>['server_id'=>['value'=>(string)$server],'needs_attention'=>['isActive'=>true]]]);}catch(\Throwable){$url=null;}
        // Nothing to do when every selected TLD has its terms: no instruction and no link to an empty list.
        $rows[]=[...self::row('Selected TLD terms',$terms['read'].' of '.$terms['total'].' read',$attention?'danger':null,
            $attention?$attention.' '.($attention===1?'needs':'need').' attention. Review current registrar terms on the TLD list.':null,null,
            'Whether the current registrar renewal terms are saved for each TLD you sell. They refresh on a schedule.'),
            'buttons'=>$attention?[Sheet::link('selected_terms','View TLDs needing attention',$url)]:[]];
        $rows[]=self::row('Scheduled renewals','1 year',null,null,null,'Scheduled renewal invoices remain annual.');
        $rows[]=ReminderFacts::emails($server);
        if(($schedule=ReminderFacts::scheduleRow($server))!==null){$rows[]=$schedule;}
        return $rows;
    }
    /** Only Enom charges for this, and only the registrar account can say so. */
    public static function whois(Account $a): ?array
    {
        if($a->registrar_key!=='enom'){return null;}
        return self::row('WHOIS privacy','Not offered. '.TldFacts::registrar($a->registrar_key).' charges for it and '.\Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels::company().' does not sell it yet.');
    }
    /** Failing checks first. @return array{failing:list<array>,passing:list<array>} */
    public static function system(): array
    {
        $paid=NativePaidHooks::notices();
        $checks=[self::worker(),match(true){
            $paid['Paymenter payment events']!=='Available'=>self::row('Payment events',null,'danger','Service listeners are missing. Restart the queue worker.',null,self::PAYMENTS),
            $paid['Start after payment']!=='Available'=>self::row('Payment events','Delayed','warning',null,null,'Paid orders start on the next scheduled run. Restart the queue worker to fix it.'),
            default=>self::row('Payment events','OK',null,'Paid orders start right away.',null,self::PAYMENTS)}];
        if(!config('settings.credits_enabled',false)){$checks[]=self::row('Customer credits','Off','warning',self::creditsCause(),null,'A refund may return to the original payment method when the gateway supports it; otherwise it is recorded as account credit. Turning Credits off hides the page; it does not mean a refund failed.');}
        $editor=NativeEditorNotice::missing();
        $checks[]=$editor===null?self::row('Domain editor','OK',null,'This Paymenter version works with it. Each registrar account has its own switch.',null,self::EDITOR):self::row('Domain editor',null,'danger','Update Paymenter or Domains.',null,'Paymenter is missing '.$editor.'.');
        if (($missing=PricingEditorNotice::missing())!==null) { $checks[]=self::row('Pricing change warning',null,'danger','Update Paymenter or Domains. Until then, check each TLD is still for sale after a price change.',null,'Paymenter is missing '.$missing.'.'); }
        if (($missing=NativeServerGuard::deletionNoticeMissing())!==null) { $checks[]=self::row('Account delete warning',null,'danger','Update Paymenter or Domains. Deleting a registrar account is still refused.',null,'Paymenter is missing '.$missing.'.'); }
        if (($missing=NativeServiceRefusal::missing())!==null) { $checks[]=self::row('Service change warning',null,'danger','Update Paymenter or Domains. Unsafe service changes are still refused.',null,'Paymenter is missing '.$missing.'.'); }
        // Only the host can supply this: Paymenter has no support contact setting, and mail_from_address may be a no-reply inbox.
        $checks[]=self::supportContact();
        $failing=array_values(array_filter($checks,fn($c)=>in_array($c['tone'],['danger','warning'],true)));
        return ['failing'=>$failing,'passing'=>array_values(array_filter($checks,fn($c)=>!in_array($c,$failing,true)))];
    }
    /** The way to Settings, for staff who can open it. */
    private static function creditsCause(): string|HtmlString
    {
        $hidden='Credit refunds still reach the customer’s balance, but customers can’t see it.';
        try { $url=Settings::canAccess()?Settings::getUrl():null; } catch (\Throwable) { $url=null; }
        return $url?new HtmlString(e($hidden).' <a class="fi-link domains-link" href="'.e($url).'">Turn on Credits in Settings</a>'):$hidden.' Turn on Credits in Settings.';
    }
    private static function supportContact(): array
    {
        if(Missing::supportUrl()!==null){return self::row('Support contact','OK',null,'Customers can reach you.',null,'Where customers go when they need help: the ticket form, or a link your site admin sets.');}
        $cause=config('settings.tickets_disabled',false)?"Tickets are off and no working contact is set, so customers can't reach you. Turn tickets on, or ask your site admin to set a support link."
            :"The ticket form is missing on this site and no working contact is set, so customers can't reach you. Ask your site admin to set a support link.";
        return self::row('Support contact',null,'danger',$cause,null,'It takes an http:, https:, mailto: or tel: link.');
    }
    /** Per-account check: the site must supply the registrar runtime. */
    public static function runtime(Account $a): array
    {
        $help='Checks the registrar runtime is registered on this site. API connection checks the saved credentials separately.';
        if(app(RuntimePorts::class)->registered($a)){return self::row('Site setup','OK',null,'Runtime registered for this registrar.',null,$help);}
        $cause=\Paymenter\Extensions\Servers\Domains\Services\NameSiloSandbox::enrolled($a)
            ?'This account uses the old balance-only TEST connection. Connect an authenticated TEST account.'
            :"The registrar connection isn't set up on this site. Check the registrar's site settings, then connect the account again.";
        return self::row('Site setup',null,'danger',$cause,null,$help);
    }
    /** Domains on this account with an open alert, linked to the filtered list. */
    public static function alerts(Account $a): array
    {
        // The same rule as the list's Open alerts filter it links to, so the count matches the rows.
        $count=Schema::hasTable('domains_obligation_alerts')?DomainQueries::openAlertCount(Domain::where('server_id',$a->server_id)):0;
        $help='Domains on this registrar account with open alerts. The sidebar counts all registrar accounts.';
        if ($count===0) { return self::row('Domain alerts','OK','success',null,null,$help); }
        try { $url=DomainResource::getUrl('index',['filters'=>['server_id'=>['value'=>(string)$a->server_id],'alerts'=>['isActive'=>true]]]); } catch (\Throwable) { $url=null; }
        return [...self::row('Domain alerts',(string)$count,'warning',null,null,$help),
            'buttons'=>[Action::make('domain_alerts')->label('View domains')->link()->size('sm')->url($url)->visible($url!==null)]];
    }
    /** The System checks row for an account with Orders on that sells no TLD: the cause and fix, and a link to What you sell. */
    public static function notSelling(Account $a,string $reason): array
    {
        try { $url=WhatYouSell::getUrl(['account'=>$a->server_id]); } catch (\Throwable) { $url=null; }
        return [...self::row('Sales',AccountStatus::SET['not_selling'][0],'warning',$reason,null,'Whether customers can buy any TLD from this account while Orders is on.'),
            'buttons'=>[Action::make('not_selling_'.$a->server_id)->label('Open What you sell')->link()->size('sm')->url($url)->visible($url!==null)]];
    }
    /** Domain monitoring is on only when the runtime composes it; then its checks need the queue WatchWork::queueReady() accepts. */
    private static function queueTooShort(): bool
    {
        if (!app()->bound(WatchWork::class)) { return false; }
        $work=app(WatchWork::class);
        return $work->active()&&!$work->queueReady();
    }
    private static function worker(): array
    {
        if (self::queueTooShort()) { return self::row('Background worker','Queue timeout too short','danger',"Monitoring can't run. Ask your site admin to set the queue timeout to 180 seconds or more.",null,self::WORKER); }
        $last=Schema::hasTable('domains_runtime_turns')?DB::table('domains_runtime_turns')->max('finished_at'):null;
        if (!$last) { return self::row('Background worker','Not running','danger','No run recorded yet. Start the scheduler and the queue worker.',null,self::WORKER); }
        $stale=app(Clock::class)->now()->getTimestamp()-(int)$last>self::WORKER_STALE_SECONDS;
        return $stale?self::row('Background worker','Not running','danger','Last run '.self::date((int)$last).'. Check the scheduler and the queue worker.',null,self::WORKER):self::row('Background worker','OK',null,Sheet::moment((int)$last,'Last run'),null,self::WORKER);
    }
    public static function termReadiness(?\Paymenter\Extensions\Servers\Domains\Data\AccountBinding $binding,string $ending): array
    {
        try {
            if($binding===null){throw new \DomainException;}
            $caps=\Paymenter\Extensions\Servers\Domains\Catalogue\Capabilities::routed() ?? throw new \DomainException;
            $ready=$caps->termReadiness($binding,$ending);
            $facts=$caps->facts($binding,$ending);
            $register=$facts->terms['register']??[];
            $renew=array_keys(array_filter($ready['renew'],fn($t)=>$t['available']));
            $held=array_values(array_filter($ready['renew'],fn($t)=>!$t['available']));
            return [
                ['Registration',self::termYears($register),$register===[1]?'Only the annual term is confirmed. Longer terms stay off.':null],
                ['Early renewal',self::termYears($renew),$held?self::termReason($held[0]['reason']):null],
                ['Renewal horizon','10 years from today','The time left on the registration reduces the years that can be added.'],
                ['Grace renewal','Off',self::termReason($ready['families']['grace']['reason'],grace:true)],
                ['Redemption','Off','Restoration is separate. Review the restoration options on the domain.'],
            ];
        } catch (\Throwable) {
            $refresh=[];
            try{if($binding&&Schema::hasTable('domains_observation_queues')){
                $queue=DB::table('domains_observation_queues')->where('server_id',$binding->serverId)->where('account_fingerprint',$binding->fingerprint)->first();
                $work=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($queue?->offer_work);
                $refresh=['succeeded_at'=>$queue?->offer_feed_succeeded_at,'due_at'=>$work['facts'][$ending]['due_at']??$work['feed_due']??null];
            }}catch(\Throwable){/* Saved refresh metadata is also missing; never invent a next check. */}
            return [['Supported terms','Missing','Current registrar terms missing. '.TldFacts::refreshText($refresh).' A supported registrar terms read fills this fact. Longer terms stay off.']];
        }
    }
    public static function termYears(array $years): string
    { sort($years);return $years===[]?'Off':(count($years)>1&&$years===range(min($years),max($years))?min($years).' to '.max($years):implode(', ',$years)).' '.($years===[1]?'year':'years'); }
    /** An unreviewed family means grace review only on the Grace row; on Ordinary it means longer terms are unreviewed. */
    public static function termReason(?string $reason,bool $grace=false): string
    {
        return match($reason) {
            'no_exact_quote'=>'Longer renewals need an accepted exact-term quote.',
            'family_unreviewed'=>$grace?'Review registry rules and safe deadlines before enabling grace renewal.':'Longer terms are not reviewed yet.',
            'live_proof_required'=>'Live renewal proof is still required.',
            'not_reviewed','term_unreviewed'=>'Longer terms are not reviewed yet.',
            'annual_unit'=>'An annual estimate cannot prove an exact-term total.',
            'stale_facts','stale_price'=>'The saved terms are out of date. The scheduled check refreshes them.',
            default=>'Current terms are not saved yet. Review the registrar terms. Where the registrar supports it, the scheduled check fills them.',
        };
    }
    /** The saved sales choice is independent of whether pricing follows the default rule. */
    public static function savedTransfers(\Paymenter\Extensions\Servers\Domains\Models\Tld $t,array $rule): bool
    {
        $intent=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($t->catalogue_intent);
        $seller=\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld',$t->tld)->where('server_id',$t->server_id)->where('registrar_key',$t->registrar_key)->where('account_fingerprint',$t->account_fingerprint)->first();
        return $seller?->transfer_enabled===true&&($intent['transfers']??$rule['transfers']??false)&&!in_array('transfer',$intent['stops']??[],true);
    }
    public static function termPrices(\Paymenter\Extensions\Servers\Domains\Models\Tld $t): array
    {
        if(!Staff::can(Staff::COSTS)){return [];}
        try{return array_values(array_filter(\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue::routed()?->preview($t,\Paymenter\Extensions\Servers\Domains\Catalogue\Pricing::rule($t))??[],fn($r)=>$r['action']==='register'));}
        catch(\Throwable){return [];}
    }
}
