<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Price};
use Paymenter\Extensions\Servers\Domains\Presentation\{AccountStatus,Missing};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,EnomTestManagement,EnomTestRenewal,NativePaidHooks,NativeServerGuard,NativeServiceRefusal,
    RenewalBillingSettings,RuntimePorts,WatchWork};
/** Rows for a registrar account's Setup checklist, Renewal invoices and reminders, and System checks.
 * A row is {label, value (null = Missing), tone, cause, help, action}; a cause shows under its value, so set it only when there is a problem.
 * action names a SettingsActions setup action. */
final class AccountChecklist
{
    public const SERVER_CONFIG='Turned off in server config.';
    /** The worker runs every minute; no run for this long means it stopped. */
    public const WORKER_STALE_SECONDS=900;
    private static function row(string $label,?string $value,?string $tone=null,?string $cause=null,?string $action=null,?string $help=null): array
    { return compact('label','value','tone','cause','action','help'); }
    private static function date(int $at): string { return gmdate('M j, Y H:i \U\T\C',$at); }
    /** @return list<array> */
    public static function setup(Account $a,array $status): array
    {
        $connection=match($status['label']){AccountStatus::SET['connection_failed'][0]=>self::row('API connection','Connection failed','danger',$status['reason'],'probe'),
            AccountStatus::SET['not_connected'][0]=>self::row('API connection','Not connected','gray',$status['reason'],'configure'),
            default=>self::row('API connection','Connected','success',null,'probe')};
        try { $binding=CatalogueAcceptances::binding($a); } catch (\Throwable) { $binding=null; }
        $rows=[$connection,self::tlds($binding),self::prices($a)];
        $gates=app(RuntimePorts::class)->acquisitionGates($a);
        foreach(['register'=>'Registrations','transfer'=>'Transfers'] as $key=>$label){
            $rows[]=$gates[$key]===true?self::row($label,'On','success'):self::row($label,'Off',null,$gates[$key]===null?'Registrar runtime not set up. See System checks.':self::SERVER_CONFIG);
        }
        $live=$a->environment==='live'?'live_':'test_';
        foreach(['renewal'=>['Renewals',EnomTestRenewal::class],'management'=>['DNS, nameservers and contacts',EnomTestManagement::class]] as $key=>[$label,$feature]){
            if($a->registrar_key!=='enom'){$rows[]=self::row($label,'Off',null,'These controls apply to Enom accounts.');continue;}
            $enabled=$binding!==null&&$feature::enabled($binding);
            $rows[]=!$enabled?self::row($label,'Off',null,self::SERVER_CONFIG):($feature::accepted($binding)?self::row($label,'On','success',null,$live.$key):self::row($label,'Off',null,null,$live.$key));
        }
        $billing=RenewalBillingSettings::read((int)$a->server_id);
        $rows[]=self::row('Renewal invoices',$billing['scheduled']?'On':'Off',$billing['scheduled']?'success':null,null,$billing['scheduled']?null:'renewal_billing');
        return $rows;
    }
    private static function tlds(?object $binding): array
    {
        try { $endings=array_keys(CatalogueAcceptances::load($binding)->endings); } catch (\Throwable) { $endings=[]; }
        sort($endings);
        return self::row('Supported TLDs',$endings?implode(', ',array_map(fn($e)=>'.'.$e,$endings)):null,null,$endings?null:'No TLDs chosen yet','acceptance');
    }
    private static function prices(Account $a): array
    {
        $failure=TldFacts::refreshFacts((int)$a->server_id);
        $hold=Schema::hasTable('domains_observation_queues')?DB::table('domains_observation_queues')->where('server_id',$a->server_id)->value('catalogue_hold'):null;
        if ($hold!==null) {
            $at=is_int($failure['at']??null)?' '.self::date($failure['at']):'';
            return self::row('TLD prices','Sync failed','danger','Failed'.$at.': '.self::syncCause($failure['code']??$hold).'. Saved prices are kept.','import');
        }
        $synced=Price::where('server_id',$a->server_id)->max('observed_at');
        return self::row('TLD prices',$synced?'Synced '.gmdate('M j, Y H:i \U\T\C',strtotime((string)$synced.' UTC')):null,null,$synced?null:'Not synced yet','import');
    }
    /** Plain cause for a stored catalogue failure code. */
    public static function syncCause(string $code): string
    {
        return match($code){'read_deadline'=>'the registrar took too long to answer','read_identity_changed','binding_mismatch','configuration_unverified'=>'the account credentials changed',
            'account_unavailable'=>'the server is missing in Paymenter','encryption_unavailable'=>"saved credentials can't be read",'storage_unavailable'=>"the database couldn't be read",
            'catalogue_acceptance_unavailable','catalogue_acceptance_changed'=>'supported TLDs changed during the sync','catalogue_reader_unavailable'=>'registrar runtime not set up',
            'catalogue_interrupted'=>'the sync was interrupted',default=>'the registrar did not return prices'};
    }
    /** @return list<array> */
    public static function renewals(int $server): array
    {
        $billing=RenewalBillingSettings::read($server);$rows=[];
        foreach(['issue_days'=>'Invoice issued','due_days'=>'Invoice due'] as $key=>$label){
            $rows[]=$billing[$key]===null?self::row($label,null,null,'Saved invoice days are invalid. New renewal invoices wait.','renewal_billing'):self::row($label,$billing[$key].' days before expiry',null,null,'renewal_billing');
        }
        $rows[]=ReminderFacts::emails($server);
        $rows[]=self::row('Customer can renew early',$billing['manual']?'On':'Off');
        return $rows;
    }
    /** Failing checks first. @return array{failing:list<array>,passing:list<array>} */
    public static function system(): array
    {
        $paid=NativePaidHooks::notices();
        $checks=[self::worker(),match(true){
            $paid['Paymenter payment events']!=='Available'=>self::row('Payment events',null,'danger','Service listeners are missing. Restart the queue worker.'),
            $paid['Start after payment']!=='Available'=>self::row('Payment events','Delayed','warning',null,null,'Paid orders start on the next scheduled run.'),
            default=>self::row('Payment events','Available')}];
        $editor=NativeEditorNotice::missing();
        $checks[]=$editor===null?self::row('Domain editor','Available'):self::row('Domain editor',null,'danger','Paymenter is missing '.$editor.'. Update Paymenter or the Domains extension.');
        if (($missing=NativeServerGuard::deletionNoticeMissing())!==null) { $checks[]=self::row('Server delete warning',null,'danger','Paymenter is missing '.$missing.'. Deleting a Domains server is still refused.'); }
        if (($missing=NativeServiceRefusal::missing())!==null) { $checks[]=self::row('Service change warning',null,'danger','Paymenter is missing '.$missing.'. Unsafe service changes are still refused.'); }
        $failing=array_values(array_filter($checks,fn($c)=>in_array($c['tone'],['danger','warning'],true)));
        return ['failing'=>$failing,'passing'=>array_values(array_filter($checks,fn($c)=>!in_array($c,$failing,true)))];
    }
    /** Per-account check: the deployment must supply the registrar runtime. */
    public static function runtime(Account $a): array
    {
        return app(RuntimePorts::class)->registered($a)?self::row('Registrar runtime','Available')
            :self::row('Registrar runtime',null,'danger','Registrar runtime is not set up in the deployment. Registrations, transfers, renewals and DNS changes stay off.');
    }
    /** Domains on this account with an open alert, linked to the Domains list filtered to them. Each domain page shows its alert next to the row it concerns. */
    public static function alerts(Account $a): array
    {
        $count=Schema::hasTable('domains_obligation_alerts')?DB::table('domains_obligation_alerts as a')->join('domains_domains as d','d.id','=','a.domain_id')
            ->where('d.server_id',$a->server_id)->whereNull('a.resolved_at')->distinct()->count('a.domain_id'):0;
        if ($count===0) { return self::row('Domain alerts','None','success'); }
        try { $url=DomainResource::getUrl('index',['filters'=>['server_id'=>['value'=>(string)$a->server_id],'alerts'=>['isActive'=>true]]]); } catch (\Throwable) { $url=null; }
        return [...self::row('Domain alerts',$count.' domain'.($count===1?'':'s'),'warning','Open each domain to see the cause.'),
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
        if (self::queueTooShort()) { return self::row('Background worker','Queue timeout too short','danger',"Domain monitoring can't run. Use an asynchronous queue with retry_after of 180 seconds or more."); }
        $last=Schema::hasTable('domains_runtime_turns')?DB::table('domains_runtime_turns')->max('finished_at'):null;
        if (!$last) { return self::row('Background worker','Not running','danger','No run recorded yet. Start the scheduler and the queue worker.'); }
        $stale=app(Clock::class)->now()->getTimestamp()-(int)$last>self::WORKER_STALE_SECONDS;
        return $stale?self::row('Background worker','Not running','danger','Last run '.self::date((int)$last).'. Check the scheduler and the queue worker.'):self::row('Background worker','Last run '.self::date((int)$last));
    }
}
