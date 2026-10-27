<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{Adoption,HistoryLink};
use Paymenter\Extensions\Servers\Domains\Services\{AdoptionSchema,HistoryLinkSchema,Installation};

function adoptionRefused(Closure $action, string $label, string $class = DomainException::class, bool $transaction = true): void
{
    $refused=false;try{if($transaction){DB::transaction($action);}else{$action();}}catch(Throwable $e){if(!$e instanceof $class){throw $e;}$refused=true;}
    expect($refused,$label);
}
$entry=new Paymenter\Extensions\Servers\Domains\Domains([]);$entry->installed();
foreach([AdoptionSchema::class,HistoryLinkSchema::class] as $schema){
    expect(in_array($schema,Installation::SCHEMAS,true),'Combined installation verifies new storage');$schema::install();$schema::verify();
}
$evidence=['version'=>1,'canonical_name'=>'example.com','native_identity'=>['user_id'=>2,'service_id'=>3,'order_id'=>4,'product_id'=>5,'annual_plan_id'=>6,'domain_property'=>'example.com'],
    'registrar_key'=>'enom','environment'=>'test','account_proof_digest'=>str_repeat('a',64),'provider_domain_id'=>'123',
    'expiry'=>['value'=>'2027-10-19','precision'=>'date','timezone'=>null,'source'=>'synthetic'],
    'auto_renew'=>false,'ordinary'=>true,'hold'=>false,'redemption'=>false,'read_started_at'=>'2026-10-19T12:00:00Z','read_ended_at'=>'2026-10-19T12:00:30Z',
    'command_set_version'=>1,'credential_revision'=>1,'retail'=>['price_id'=>7,'revision'=>1,'currency'=>'USD','tax_basis'=>'exclusive'],
    'prior_generation'=>1,'prior_revision'=>2,'resulting_generation'=>2,'resulting_revision'=>3,'source_conversion_row_id'=>8,'history_link_ids'=>[]];
$data=['request_key'=>'synthetic-preview','request_digest'=>str_repeat('b',64),'domain_id'=>10,'service_id'=>3,'user_id'=>2,'server_id'=>1,
    'registrar_key'=>'enom','environment'=>'test','account_fingerprint'=>str_repeat('c',64),'state'=>'preview','origin'=>'staff','evidence'=>$evidence,
    'actor_id'=>9,'reason'=>'Synthetic adoption evidence','do_not_renew'=>false,'observed_at'=>'2026-10-19 12:00:00','valid_until'=>'2026-10-19 12:02:00'];
$preview=DB::transaction(fn()=>Adoption::create($data));
$second=DB::transaction(fn()=>Adoption::create([...$data,'request_key'=>'second-preview']));
same(null,$preview->authority_generation,'Previews do not reserve a committed generation');
$encoded=DB::table('domains_adoptions')->where('id',$preview->id)->value('evidence');
expect(!str_contains($encoded,'example.com'),'Adoption evidence encrypted at rest');same($evidence,$preview->fresh()->evidence,'Adoption evidence decrypts exactly');
foreach(['evidence','request_key','request_digest','account_fingerprint','reason'] as $key){expect(!array_key_exists($key,$preview->toArray()),'Sensitive adoption data hidden');}
adoptionRefused(fn()=>Adoption::create($data),'Request key globally unique',QueryException::class);
foreach([['state'=>'unknown'],['environment'=>'staging'],['origin'=>'purchase'],['request_digest'=>'bad'],['account_fingerprint'=>null],['server_id'=>null],
    ['request_key'=>'MixedCase'],['request_key'=>'leading '],['request_key'=>' trailing'],['request_key'=>''],['request_key'=>str_repeat('a',129)],
    ['service_id'=>'10.5'],['user_id'=>'1e3'],['actor_id'=>' 3'],['server_id'=>2.5],['domain_id'=>-1],['cancellation_id'=>99],
    ['evidence'=>[...$evidence,'registrar_key'=>'namesilo']],['evidence'=>[...$evidence,'environment'=>'live']],
    ['valid_until'=>'2026-10-19 12:00:00'],['evidence'=>['version'=>2]],['authority_generation'=>2],['committed_at'=>'2026-10-19 12:01:00'],
    ['state'=>'committed'],['state'=>'committed','authority_generation'=>3,'committed_at'=>'2026-10-19 12:01:00']] as $bad){
    adoptionRefused(fn()=>Adoption::create([...$data,'request_key'=>'invalid',...$bad]),'Malformed adoption record refused');
}
foreach(['request_key'=>'changed','request_digest'=>str_repeat('d',64),'service_id'=>4,'domain_id'=>11,'user_id'=>3,'server_id'=>2,
    'account_fingerprint'=>str_repeat('d',64),'registrar_key'=>'namesilo','environment'=>'live','evidence'=>[...$evidence,'canonical_name'=>'other-placeholder.com'],
    'actor_id'=>10,'reason'=>'Changed intent','do_not_renew'=>true,'cancellation_id'=>99,'observed_at'=>'2026-10-19 12:00:01','valid_until'=>'2026-10-19 12:03:00'] as $key=>$value){
    adoptionRefused(fn()=>$preview->fill([$key=>$value,'state'=>'refused'])->save(),'Requested identities and intent are frozen');$preview->refresh();
}
$stale=$preview->fresh();
DB::transaction(fn()=>$preview->fill(['state'=>'committed','authority_generation'=>2,'committed_at'=>'2026-10-19 12:01:00'])->save());
adoptionRefused(fn()=>$stale->fill(['state'=>'refused'])->save(),'Stale preview cannot overwrite terminal evidence');
adoptionRefused(fn()=>$preview->fill(['state'=>'superseded','authority_generation'=>null,'committed_at'=>null])->save(),'Committed adoption immutable');$preview->refresh();
adoptionRefused(fn()=>$second->fill(['state'=>'committed','authority_generation'=>2,'committed_at'=>'2026-10-19 12:01:00'])->save(),'Committed domain/generation unique',QueryException::class);$second->refresh();
DB::transaction(fn()=>$second->fill(['state'=>'superseded'])->save());
adoptionRefused(fn()=>$second->fill(['state'=>'preview'])->save(),'Superseded evidence immutable');$second->refresh();
$committed=[...$data,'state'=>'committed','authority_generation'=>2,'committed_at'=>'2026-10-19 12:01:00'];
adoptionRefused(fn()=>Adoption::create([...$committed,'request_key'=>'direct-commit']),'Committed creation cannot skip locked preview transition');
$commit=static function(array $record):Adoption{return DB::transaction(static function()use($record):Adoption{
    $row=Adoption::create([...$record,'state'=>'preview','authority_generation'=>null,'committed_at'=>null,'cancellation_id'=>null]);
    $row->fill(['state'=>'committed','authority_generation'=>$record['authority_generation'],'committed_at'=>$record['committed_at'],
        'cancellation_id'=>$record['cancellation_id']??null])->save();return $row;
});};
$commit([...$committed,'request_key'=>'different-domain','domain_id'=>11]);
$commit([...$committed,'request_key'=>'next-generation','authority_generation'=>3,'evidence'=>[...$evidence,'resulting_generation'=>3]]);
$unknown=DB::transaction(fn()=>Adoption::create([...$data,'request_key'=>'new-name','domain_id'=>null]));
DB::transaction(fn()=>$unknown->fill(['state'=>'committed','domain_id'=>12,'authority_generation'=>2,'committed_at'=>'2026-10-19 12:01:00'])->save());
foreach([false,true] as $intent){
    $fresh=DB::transaction(fn()=>Adoption::create([...$data,'request_key'=>'unrefreshed-'.(int)$intent,'domain_id'=>13+(int)$intent,'do_not_renew'=>$intent]));
    DB::transaction(fn()=>$fresh->fill(['state'=>'committed','authority_generation'=>2,'committed_at'=>'2026-10-19 12:01:00','cancellation_id'=>$intent?99:null])->save());
    same('committed',$fresh->fresh()->state,'Unrefreshed preview transitions with either boolean scalar representation');
}
$noCancel=DB::transaction(fn()=>Adoption::create([...$data,'request_key'=>'missing-cancellation','domain_id'=>15,'do_not_renew'=>true]));
adoptionRefused(fn()=>$noCancel->fill(['state'=>'committed','authority_generation'=>2,'committed_at'=>'2026-10-19 12:01:00'])->save(),'Do-not-renew commit requires cancellation');
foreach([['cancellation_id'=>99],['authority_generation'=>'2.5'],['authority_generation'=>'1e3'],['authority_generation'=>' 2']] as $bad){
    $row=DB::transaction(fn()=>Adoption::create([...$data,'request_key'=>'bad-commit-'.Adoption::count()]));
    adoptionRefused(fn()=>$row->fill(['state'=>'committed','authority_generation'=>2,'committed_at'=>'2026-10-19 12:01:00',...$bad])->save(),'Incoherent cancellation and generation refused');
}
$unbound=DB::transaction(fn()=>Adoption::create([...$data,'request_key'=>'unbound','domain_id'=>null]));
adoptionRefused(fn()=>$unbound->fill(['state'=>'superseded','domain_id'=>99])->save(),'Noncommitted transition cannot bind domain');$unbound->refresh();
foreach(['do_not_renew'=>1,'reason'=>'Changed in storage','evidence'=>'changed-ciphertext'] as $key=>$value){
    adoptionRefused(static function()use($unbound,$key,$value){DB::table('domains_adoptions')->where('id',$unbound->id)->update([$key=>$value]);
        $unbound->fill(['state'=>'refused'])->save();},'Changed persisted preview cannot be overwritten');$unbound->refresh();
}
$refused=DB::transaction(fn()=>Adoption::create([...$data,'request_key'=>'refused','state'=>'refused']));
adoptionRefused(fn()=>$refused->fill(['reason'=>'Rewrite'])->save(),'Refused evidence immutable');

$history=['domain_id'=>10,'service_id'=>3,'user_id'=>2,'invoice_id'=>20,'invoice_item_id'=>null,'source'=>'staff_verified_import',
    'linked_by'=>9,'reason'=>'Synthetic imported association','linked_at'=>'2026-10-19 12:01:00','evidence'=>['version'=>1,'mapping_digest'=>str_repeat('e',64)]];
$link=HistoryLink::create($history);$item=HistoryLink::create([...$history,'invoice_item_id'=>21,'source'=>'native_service']);
same(HistoryLink::keyFor(10,20,null),$link->link_key,'Null item has a stable nonnull link identity');
foreach([null,21] as $id){adoptionRefused(fn()=>HistoryLink::create([...$history,'invoice_item_id'=>$id]),'Null and nonnull item duplicates refused',QueryException::class);}
adoptionRefused(fn()=>HistoryLink::create([...$history,'link_key'=>str_repeat('f',64)]),'Caller cannot replace deterministic identity');
HistoryLink::create([...$history,'domain_id'=>11]);HistoryLink::create([...$history,'invoice_id'=>22]);
foreach([['source'=>'funding'],['invoice_id'=>null],['invoice_item_id'=>0],['linked_by'=>0],['reason'=>' '],['evidence'=>null],['evidence'=>['version'=>2]],
    ['invoice_id'=>'10.5'],['domain_id'=>1.5],['user_id'=>'1e3'],['service_id'=>' 3'],['invoice_item_id'=>'2.5']] as $bad){
    adoptionRefused(fn()=>HistoryLink::create([...$history,'invoice_id'=>99,...$bad]),'Malformed history record refused');
}
expect(!str_contains(DB::table('domains_history_links')->where('id',$link->id)->value('evidence'),'mapping_digest'),'History evidence encrypted');
same($history['evidence'],$link->fresh()->evidence,'History evidence decrypts exactly');
foreach(['link_key','evidence','reason'] as $key){expect(!array_key_exists($key,$link->toArray()),'Sensitive history data hidden');}
foreach(['domain_id'=>11,'service_id'=>4,'user_id'=>3,'invoice_id'=>22,'invoice_item_id'=>23,'reason'=>'Changed','evidence'=>['version'=>2]] as $key=>$value){
    adoptionRefused(fn()=>$link->fill([$key=>$value])->save(),'History links append-only');$link->refresh();
}
foreach([$link,$item,$preview,$second] as $row){
    adoptionRefused(fn()=>$row->delete(),'Retained rows cannot delete');
    adoptionRefused(fn()=>$row->increment('user_id',1,['reason'=>'Rewrite']),'Numeric updates cannot bypass immutability');
}
// Direct SQL remains a privileged maintenance bypass; actual indexes still arbitrate duplicate inserts.
foreach([['domains_adoptions','request_key',$preview->request_key],['domains_history_links','link_key',$link->link_key]] as [$table,$key,$value]){
    $raw=(array)DB::table($table)->where($key,$value)->first();unset($raw['id']);
    adoptionRefused(fn()=>DB::table($table)->insert($raw),'Database unique constraint enforced',QueryException::class);
}
$counts=[Adoption::count(),HistoryLink::count()];$ciphertexts=[DB::table('domains_adoptions')->pluck('evidence','id')->all(),DB::table('domains_history_links')->pluck('evidence','id')->all()];
$entry->upgraded('0.1.0');$entry->uninstalled();$entry->installed();
foreach(['2026_10_19_000010_create_domains_adoptions','2026_10_19_000011_create_domains_history_links'] as $migration){
    $m=require dirname(__DIR__).'/Domains/database/migrations/'.$migration.'.php';$m->down();
    $record=(array)DB::table('migrations')->where('migration',$migration)->first();DB::table('migrations')->where('migration',$migration)->delete();
    adoptionRefused(fn()=>$entry->installed(),'Missing retained migration history refuses reinstall',DomainException::class,false);DB::table('migrations')->insert($record);
}
AdoptionSchema::verify();HistoryLinkSchema::verify();
same($counts,[Adoption::count(),HistoryLink::count()],'Uninstall, reinstall and down retain every row');
same($ciphertexts,[DB::table('domains_adoptions')->pluck('evidence','id')->all(),DB::table('domains_history_links')->pluck('evidence','id')->all()],'Retention preserves encrypted bytes');
// Standalone and skipped-migration verification reject both shape and uniqueness drift without repair.
foreach([['domains_adoptions',AdoptionSchema::class,'domains_adoptions_domain_id_authority_generation_unique'],
    ['domains_history_links',HistoryLinkSchema::class,'domains_history_links_link_key_unique']] as [$table,$schema,$index]){
    Schema::table($table,fn($t)=>$t->dropUnique($index));
    adoptionRefused(fn()=>$schema::verify(),'Weakened unique index refuses schema verification',RuntimeException::class);
    adoptionRefused(fn()=>$entry->installed(),'Skipped migration still verifies constraints',RuntimeException::class,false);
    Schema::table($table,fn($t)=>$t->unique($table==='domains_adoptions'?['domain_id','authority_generation']:['link_key'],$index));$schema::verify();
    Schema::table($table,fn($t)=>$t->string('synthetic_drift')->nullable());
    adoptionRefused(fn()=>$schema::install(),'Retained column drift is not repaired',RuntimeException::class);
    Schema::table($table,fn($t)=>$t->dropColumn('synthetic_drift'));$schema::verify();
    Schema::rename($table,$table.'_retained');
    adoptionRefused(fn()=>$entry->installed(),'Recorded missing table refuses reinstall',RuntimeException::class,false);
    expect(!Schema::hasTable($table),'Missing recorded table never recreated');Schema::rename($table.'_retained',$table);$schema::verify();
}
same(0,DB::table('domains_domains')->count(),'Storage creates no holdings');same(0,DB::table('domains_operations')->count(),'Storage creates no operations');
same(0,DB::table('invoices')->count(),'Storage creates no invoices');
echo "Adoption/history storage uniqueness, encryption, immutable evidence, schema drift and retention passed.\n";$nativeSuccessful=true;
