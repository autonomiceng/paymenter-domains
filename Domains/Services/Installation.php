<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\{Cache,DB,Schema};
/** No destructive rollback or automatic adoption of retained history. */
final class Installation
{
    public const SCHEMAS=[FoundationSchema::class,SalesPolicySchema::class,ContactProfileSchema::class,ObservationSchema::class,ReminderSchema::class,RuntimeSchema::class,HoldingProvenanceSchema::class,PslSchema::class,PslReviewSchema::class,WatchSchema::class,AdoptionSchema::class,HistoryLinkSchema::class,WatchDnsSchema::class,NativeIntentSchema::class,HoldingAdoptionSchema::class,BalanceSchema::class,ManagedPublicWorkSchema::class];
    public static function legacy(): void
    {
        foreach(Server::where('extension','Enom')->get() as $server){
            // Server extensions boot even when their native enabled switch is off.
            $value=$server->settings()->where('key','operations_enabled')->first()?->value;
            if(filter_var($value,FILTER_VALIDATE_BOOLEAN)){throw new \DomainException('Pause processing on every legacy Enom server before installing or enabling Domains.');}
        }
    }
    public function verify(): void { foreach(self::SCHEMAS as $schema){$schema::verify();} }
    public function install(): void
    {
        OperationClaims::outside(); self::legacy();
        Cache::lock('domains-installation',60)->block(5,function():void {
            $present=Schema::hasTable('domains_accounts');
            foreach(['domains_domains','domains_operations','domains_tlds','domains_prices','domains_contact_profiles','domains_sales_policies','domains_read_budgets','domains_observation_queues','domains_observation_work','domains_reminder_schedules','domains_reminder_deliveries','domains_obligation_alerts','domains_runtime_turns','domains_runtime_operations','domains_psl_snapshots','domains_psl_status','domains_psl_candidates','domains_watch_audit','domains_watch_product_maps','domains_watch_names','domains_watch_sources'] as $table){
                if(!$present&&Schema::hasTable($table)){throw new \DomainException('Partial retained Domains schema. Restore its installation history before reinstalling.');}
            }
            foreach(['domains_adoptions','domains_history_links','domains_watch_profiles','domains_watch_observations','domains_watch_work','domains_native_intents','domains_registrar_balances','domains_managed_public_work'] as $table){
                if(!$present&&Schema::hasTable($table)){throw new \DomainException('Partial retained Domains schema. Restore its installation history before reinstalling.');}
            }
            $ran=app(Migrator::class)->getRepository()->getRan();$recorded=in_array('2026_10_18_000001_create_domains_foundation',$ran,true);
            if($present&&!$recorded){throw new \DomainException('Retained Domains data has no installation record. Restore and verify its migration history before reinstalling.');}
            // A complete preflight prevents a partial retained schema from being silently filled in.
            if($present){
                $files=glob(dirname(__DIR__).'/database/migrations/*.php');sort($files);
                foreach(array_slice($files,0,5) as $file){if(!in_array(basename($file,'.php'),$ran,true)){throw new \DomainException('Retained Domains migration history is incomplete. Restore its original history.');}}
                foreach(array_slice(self::SCHEMAS,0,5) as $schema){$schema::verify();}
                if(Schema::hasTable('domains_runtime_turns')||Schema::hasTable('domains_runtime_operations')){
                    if(!in_array('2026_10_18_000006_create_runtime_turns',$ran,true)){throw new \DomainException('Retained runtime history has no installation record.');}RuntimeSchema::verify();
                }
            }
            if (in_array('2026_10_18_000007_add_holding_provenance',$ran,true)) { HoldingProvenanceSchema::verify(); }
            if(Schema::hasTable('domains_psl_snapshots')||Schema::hasTable('domains_psl_status')){
                if(!in_array('2026_10_19_000007_create_psl_snapshots',$ran,true)){throw new \DomainException('Retained public suffix history has no installation record.');}PslSchema::verify();
            }
            if(Schema::hasTable('domains_psl_candidates')||Schema::hasTable('domains_watch_audit')){
                if(!in_array('2026_10_19_000008_create_psl_reviews',$ran,true)){throw new \DomainException('Retained suffix review history has no installation record.');}PslReviewSchema::verify();
            }
            if(Schema::hasTable('domains_watch_product_maps')||Schema::hasTable('domains_watch_names')||Schema::hasTable('domains_watch_sources')){
                if(!in_array('2026_10_19_000009_create_watch_storage',$ran,true)){throw new \DomainException('Retained watch storage history has no installation record.');}WatchSchema::verify();
            }
            foreach([
                ['domains_adoptions','2026_10_19_000010_create_domains_adoptions',AdoptionSchema::class],
                ['domains_history_links','2026_10_19_000011_create_domains_history_links',HistoryLinkSchema::class],
                ['domains_native_intents','2026_10_19_000013_create_native_intents',NativeIntentSchema::class],
                ['domains_managed_public_work','2026_10_19_000018_create_managed_public_work',ManagedPublicWorkSchema::class],
                ['domains_registrar_balances','2026_10_19_000015_create_registrar_balances',BalanceSchema::class],
            ] as [$table,$migration,$schema]){
                $recorded=in_array($migration,$ran,true);
                if(Schema::hasTable($table)&&!$recorded){throw new \DomainException('Retained holding storage has no installation record.');}
                if($recorded){$schema::verify();} // Refuse lost/drifted tables before any migration can fill gaps.
            }
            $adoptionRecorded = in_array('2026_10_19_000014_add_holding_adoption', $ran, true);
            if (Schema::hasColumn('domains_domains', 'adoption_id') && !$adoptionRecorded) { throw new \DomainException('Retained adoption pointer has no installation record.'); }
            if ($adoptionRecorded) { HoldingAdoptionSchema::verify(); }
            $dnsPresent = array_map(Schema::hasTable(...), WatchDnsSchema::tables());
            $dnsRecorded = in_array('2026_10_19_000012_create_watch_dns', $ran, true);
            if (in_array(true, $dnsPresent, true) && !$dnsRecorded) { throw new \DomainException('Retained DNS history has no installation record.'); }
            if ($dnsRecorded) { WatchDnsSchema::verify(); }
            app(Migrator::class)->run([dirname(__DIR__).'/database/migrations'],['force'=>true]);
            $this->verify(); // Required even when every migration was skipped.
            ReminderTemplates::ensure();
        });
    }
}
