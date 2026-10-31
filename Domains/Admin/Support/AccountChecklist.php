<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource,TldResource};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Price};
use Paymenter\Extensions\Servers\Domains\Presentation\{AccountStatus,Missing};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CatalogueAcceptances,EnomTestManagement,EnomTestRenewal,EnomTestTransfer,NativePaidHooks,NativeServerGuard,NativeServiceRefusal,UnpaidHoldSetting,
    RenewalBillingSettings,RuntimePorts,WatchWork};
/** Rows for a registrar account's Setup checklist, Renewal invoices and reminders, and System checks.
 * A row is {label, value (null = Missing), tone, cause, help, action}; a cause shows under its value, so set it only when there is a problem.
 * action names a SettingsActions setup action. A value of On or Off shows as a switch. Every row says what it is in help. */
final class AccountChecklist
{
    /** The worker runs every minute; no run for this long means it stopped. */
    public const WORKER_STALE_SECONDS=900;
    private const PRICES='When this account last read its costs from the registrar. Use Sync prices to read them again.';
    private const PAYMENTS='Starts domain orders when an invoice is paid. If it fails, restart the queue worker.';
    private const EDITOR='Adds the domain fields and warnings to Paymenter’s service page. If it fails, update Paymenter or Domains.';
    private const WORKER='Runs background work every minute: sending orders, retries and domain checks. If it stops, start the scheduler and the queue worker.';
    private const NOT_YET='Not available yet';
    private const NAMESILO='Not offered for NameSilo.';
    private const REGISTRATIONS='Lets customers buy domains on this account. The site sets this, so it has no switch here. Orders must be on too.';
    private const TRANSFERS='Lets customers move a domain here with an auth code, for TLDs with Transfers on in What you sell. Orders must be on too. Experimental: it has never run against a real test transfer.';
    private const RENEWALS='Sends paid renewals to the registrar, creates renewal invoices and sends reminder emails. Off: no new invoices. Issued invoices stay payable, and paid renewals wait until you turn it back on.';
    private const BILLING='Creates renewal invoices and sends reminder emails. Off: no new invoices. Issued invoices stay payable, and paid renewals still go to the registrar.';
    private const MANAGEMENT='Sends changes to DNS records, nameservers and contact details to the registrar. Off: those changes are not sent.';
    private static function row(string $label,?string $value,?string $tone=null,?string $cause=null,?string $action=null,?string $help=null): array
    { return compact('label','value','tone','cause','action','help'); }
    private static function date(int $at): string { return gmdate('M j, Y H:i \U\T\C',$at); }
    private static function unavailable(string $label,string $why): array { return self::row($label,self::NOT_YET,null,null,null,$why); }
    /** Staff can't change the site config, so a row says who can. release-runbook.md lists what the site admin sets. */
    private static function siteOff(Account $a): string
    { return ($a->environment==='live'?'Live sales are not set up on this site yet.':'This site does not offer this yet.').' Your site admin can turn it on.'; }
    /** @return list<array> */
    public static function setup(Account $a,array $status): array
    {
        $check='Checks this site can log in to the registrar with the saved API details.';
        $connection=match($status['label']){AccountStatus::SET['connection_failed'][0]=>self::row('API connection','Connection failed','danger',$status['reason'],'probe',$check),
            AccountStatus::SET['not_connected'][0]=>self::row('API connection','Not connected','gray',$status['reason'],'configure',$check),
            default=>self::row('API connection','Connected','success',null,'probe',$check)};
        try { $binding=CatalogueAcceptances::binding($a); } catch (\Throwable) { $binding=null; }
        $rows=[$connection,self::tlds($a,$binding),self::prices($a),self::orders($a)];
        $gates=app(RuntimePorts::class)->acquisitionGates($a);
        $off=fn(?bool $gate)=>match(true){$gate===null=>'Not set up on this site yet. See System checks.',$a->registrar_key!=='enom'=>self::NAMESILO,default=>self::siteOff($a)};
        $rows[]=$gates['register']===true?self::row('Registrations','On','success',null,null,self::REGISTRATIONS):self::unavailable('Registrations',$off($gates['register']));
        $rows[]=self::transfers($a,$binding,$gates['transfer'],$off);
        foreach(['renewal'=>['Renewals',EnomTestRenewal::class,self::RENEWALS],'management'=>['DNS, nameservers and contacts',EnomTestManagement::class,self::MANAGEMENT]] as $key=>[$label,$feature,$help]){
            $rows[]=match(true){$a->registrar_key!=='enom'=>$key==='renewal'?self::billingSwitch((int)$a->server_id):self::unavailable($label,self::NAMESILO),
                $binding===null=>[...self::unavailable($label,$help),'cause'=>'Restore the saved account identity first.'],
                !$feature::enabled($binding)=>self::unavailable($label,self::siteOff($a)),
                default=>self::writeSwitch($a,$label,$key,$key==='renewal'?EnomTestRenewal::on($binding):$feature::accepted($binding),$help)};
        }
        return $rows;
    }
    /** Live stays off until activation; sent transfers keep reconciling after revocation. */
    private static function transfers(Account $a,?object $binding,?bool $gate,\Closure $off): array
    {
        if($a->environment==='live'&&$a->registrar_key==='enom'){return self::writeSwitch($a,'Transfers','transfer',$binding!==null&&EnomTestTransfer::accepted($binding),'Lets customers move a domain here with an auth code, for TLDs with Transfers on in What you sell. Orders must be on too.');}
        if($binding!==null&&EnomTestTransfer::enabled($binding)){$on=EnomTestTransfer::accepted($binding);return self::row('Transfers (experimental)',$on?'On':'Off',$on?'success':null,null,'test_transfer',self::TRANSFERS);}
        return $gate===true?self::row('Transfers','On','success',null,null,self::REGISTRATIONS):self::unavailable('Transfers',$off($gate));
    }
    /** Live turns on when live sales are activated. Renewals also start invoices, so a bad calendar stops them turning on. */
    private static function writeSwitch(Account $a,string $label,string $key,bool $on,string $help): array
    {
        $live=$a->environment==='live';$action=($live?'live_':'test_').$key;
        return match(true){$on=>self::row($label,'On','success',null,$action,$help),
            $live=>self::row($label,'Off',null,'Turns on when you activate live sales.',null,$help),
            $key==='renewal'&&RenewalBillingSettings::read((int)$a->server_id)['issue_days']===null=>self::row($label,'Off',null,'Fix the invoice days first.',null,$help),
            default=>self::row($label,'Off',null,null,$action,$help)};
    }
    /** NameSilo has no send permission here, so Renewals is its two billing flags. A bad saved calendar can't be saved back, so it waits for Edit schedule. */
    private static function billingSwitch(int $server): array
    {
        $on=RenewalBillingSettings::anyOn($server);$bad=RenewalBillingSettings::read($server)['issue_days']===null;
        return self::row('Renewals',$on?'On':'Off',$on?'success':null,$bad?'Fix the invoice days first.':null,$bad?null:'renewals',self::BILLING);
    }
    /** Orders stop or start every registrar request on this account. Saved credentials must match before they can start. */
    private static function orders(Account $a): array
    {
        $on=(bool)$a->processing_enabled;
        return self::row('Orders',$on?'On':'Off',$on?'success':null,$on||app(AccountBindings::class)->configurationStatus((int)$a->server_id)==='matched'?null:'Fix the API connection first.','processing','Lets this account send requests to the registrar. Off stops everything: new orders, renewals and changes. They wait and go out when it is back on.');
    }
    /** A bad saved calendar can't be saved back, so this switch waits for Edit schedule. It only works while Renewals is on. */
    private static function renewEarly(int $server): array
    {
        try { $a=Account::where('server_id',$server)->firstOrFail();$renewing=$a->registrar_key==='enom'?EnomTestRenewal::on(CatalogueAcceptances::binding($a)):RenewalBillingSettings::anyOn($server); } catch (\Throwable) { $renewing=false; }
        $billing=RenewalBillingSettings::read($server);$on=$renewing&&$billing['manual'];$bad=$billing['issue_days']===null;
        return self::row('Customer can renew early',$on?'On':'Off',$on?'success':null,match(true){!$renewing=>'Needs Renewals on.',$bad=>'Fix the invoice days first.',default=>null},$renewing&&!$bad?'renew_early':null,'Lets customers pay for a renewal before the invoice goes out.');
    }
    private static function tlds(Account $a,?object $binding): array
    {
        try { $endings=array_keys(CatalogueAcceptances::load($binding)->endings); } catch (\Throwable) { $endings=[]; }
        sort($endings);
        try { $url=WhatYouSell::getUrl(['account'=>$a->server_id]); } catch (\Throwable) { $url=null; }
        return [...self::row('What you sell',$endings?implode(', ',array_map(fn($e)=>'.'.$e,$endings)):null,null,$endings?null:'Choose the TLDs you sell.',null,'The TLDs customers can buy from this account.'),
            'buttons'=>[Action::make('what_you_sell_'.$a->server_id)->label($endings?'Change':'Choose what you sell')->link()->size('sm')->url($url)->visible($url!==null)]];
    }
    private static function prices(Account $a): array
    {
        $failure=TldFacts::refreshFacts((int)$a->server_id);
        $hold=Schema::hasTable('domains_observation_queues')?DB::table('domains_observation_queues')->where('server_id',$a->server_id)->value('catalogue_hold'):null;
        if ($hold!==null) {
            $at=is_int($failure['at']??null)?' '.self::date($failure['at']):'';
            $row=self::row('TLD prices','Sync failed','danger','Failed'.$at.': '.lcfirst(TldFacts::syncCause($failure['code']??$hold)).'. Saved prices are kept.',null,self::PRICES);
            return [...$row,'buttons'=>self::priceButtons($a)];
        }
        $synced=Price::where('server_id',$a->server_id)->max('observed_at');
        return [...self::row('TLD prices',$synced?'Synced '.gmdate('M j, Y H:i \U\T\C',strtotime((string)$synced.' UTC')):null,null,$synced?null:'Not synced yet.',null,self::PRICES),'buttons'=>self::priceButtons($a)];
    }
    /** Sync covers every TLD of the account at once. Staff who can't sync can still open the TLD list. */
    private static function priceButtons(Account $a): array
    {
        try { $url=TldResource::getUrl('index'); } catch (\Throwable) { $url=null; }
        $sync=SettingsActions::syncAll((int)$a->server_id)->link()->size('sm');
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
        $rows[]=ReminderFacts::emails($server);
        $rows[]=self::renewEarly($server);
        return $rows;
    }
    /** Failing checks first. @return array{failing:list<array>,passing:list<array>} */
    public static function system(): array
    {
        $paid=NativePaidHooks::notices();
        $checks=[self::worker(),match(true){
            $paid['Paymenter payment events']!=='Available'=>self::row('Payment events',null,'danger','Service listeners are missing. Restart the queue worker.',null,self::PAYMENTS),
            $paid['Start after payment']!=='Available'=>self::row('Payment events','Delayed','warning',null,null,'Paid orders start on the next scheduled run. Restart the queue worker to fix it.'),
            default=>self::row('Payment events','Working',null,null,null,self::PAYMENTS)}];
        $editor=NativeEditorNotice::missing();
        $checks[]=$editor===null?self::row('Domain editor','Working',null,null,null,self::EDITOR):self::row('Domain editor',null,'danger','Update Paymenter or Domains.',null,'Paymenter is missing '.$editor.'.');
        if (($missing=PricingEditorNotice::missing())!==null) { $checks[]=self::row('Pricing change warning',null,'danger','Update Paymenter or Domains. Until then, check each TLD is still for sale after a price change.',null,'Paymenter is missing '.$missing.'.'); }
        if (($missing=NativeServerGuard::deletionNoticeMissing())!==null) { $checks[]=self::row('Account delete warning',null,'danger','Update Paymenter or Domains. Deleting a registrar account is still refused.',null,'Paymenter is missing '.$missing.'.'); }
        if (($missing=NativeServiceRefusal::missing())!==null) { $checks[]=self::row('Service change warning',null,'danger','Update Paymenter or Domains. Unsafe service changes are still refused.',null,'Paymenter is missing '.$missing.'.'); }
        // Only the host can supply this: Paymenter has no support contact setting, and mail_from_address may be a no-reply inbox.
        $checks[]=self::supportContact();
        $failing=array_values(array_filter($checks,fn($c)=>in_array($c['tone'],['danger','warning'],true)));
        return ['failing'=>$failing,'passing'=>array_values(array_filter($checks,fn($c)=>!in_array($c,$failing,true)))];
    }
    private static function supportContact(): array
    {
        if(Missing::supportUrl()!==null){return self::row('Support contact','Set',null,null,null,'Where customers go when they need help: the ticket form, or a link your site admin sets.');}
        $cause=config('settings.tickets_disabled',false)?"Tickets are off and no working contact is set, so customers can't reach you. Turn tickets on, or ask your site admin to set a support link."
            :"The ticket form is missing on this site and no working contact is set, so customers can't reach you. Ask your site admin to set a support link.";
        return self::row('Support contact',null,'danger',$cause,null,'It takes an http:, https:, mailto: or tel: link.');
    }
    /** Per-account check: the site must supply the registrar runtime. */
    public static function runtime(Account $a): array
    {
        $help='Checks this site has what it needs to work with this registrar account. If it fails, ask your site admin.';
        return app(RuntimePorts::class)->registered($a)?self::row('Site setup','Working',null,null,null,$help)
            :self::row('Site setup',null,'danger','Not set up on this site. Registrations, transfers, renewals and DNS changes stay off. Ask your site admin.',null,$help);
    }
    /** Domains on this account with an open alert, linked to the filtered list. */
    public static function alerts(Account $a): array
    {
        $count=Schema::hasTable('domains_obligation_alerts')?DB::table('domains_obligation_alerts as a')->join('domains_domains as d','d.id','=','a.domain_id')
            ->where('d.server_id',$a->server_id)->whereNull('a.resolved_at')->distinct()->count('a.domain_id'):0;
        $help='Domains on this account with a problem that needs a person.';
        if ($count===0) { return self::row('Domain alerts','None','success',null,null,$help); }
        try { $url=DomainResource::getUrl('index',['filters'=>['server_id'=>['value'=>(string)$a->server_id],'alerts'=>['isActive'=>true]]]); } catch (\Throwable) { $url=null; }
        return [...self::row('Domain alerts',$count.' domain'.($count===1?'':'s'),'warning',null,null,$help),
            'buttons'=>[Action::make('domain_alerts')->label('View domains')->link()->size('sm')->url($url)->visible($url!==null)]];
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
        return $stale?self::row('Background worker','Not running','danger','Last run '.self::date((int)$last).'. Check the scheduler and the queue worker.',null,self::WORKER):self::row('Background worker','Last run '.self::date((int)$last),null,null,null,self::WORKER);
    }
}
