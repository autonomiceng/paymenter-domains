<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native text columns and real encryption reproduce the archive's 64 KB storage boundary.
require __DIR__.'/native-app.php';
use App\Models\Server;
use Illuminate\Support\Facades\{Crypt,DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{Installation,BusinessSettingsSchema,CardFee,StaffSettingsReview};
(new Installation)->install();
$server=Server::create(['name'=>'Synthetic registrar','extension'=>'Domains','type'=>'server','enabled'=>true]);
Schema::drop(BusinessSettingsSchema::TABLE);DB::table('migrations')->where('migration',BusinessSettingsSchema::MIGRATION)->delete();
$value=str_repeat('retained value ',3000);
$source=DB::table('settings')->insertGetId(['key'=>CardFee::KEY,'type'=>'string','value'=>$value,'encrypted'=>false,'created_at'=>'2026-11-01 01:00:00','updated_at'=>'2026-11-01 01:00:00']);
DB::table('settings')->insert(['key'=>CardFee::KEY,'type'=>'array','value'=>json_encode(CardFee::DEFAULT),'encrypted'=>false,'created_at'=>'2026-11-02 01:00:00','updated_at'=>'2026-11-02 01:00:00']);
(new Installation)->install();
same(CardFee::DEFAULT,CardFee::read(),'Large conflicting evidence does not block the newest choice');
$parts=[];
foreach(DB::table('domains_audit_archives')->where('server_id',0)->where('family','staff_settings')->orderBy('id')->get() as $batch){
    expect(strlen($batch->payload)<=64000,'Each encrypted batch fits retained storage');
    expect($batch->event_key!==null,'Conflict fragments use retained event identities');
    $event=json_decode(Crypt::decryptString($batch->payload),true,512,JSON_THROW_ON_ERROR);
    $other=$event['before']['discarded'][0];same($source,$other['id'],'Every fragment keeps source identity');
    $parts[$other['part']]=base64_decode($other['value_base64'],true);
}
ksort($parts);same($value,implode('',$parts),'Every conflicting source byte is retained');
same(count($parts),count(StaffSettingsReview::historyRows($server)),'Staff history includes all recent conflict batches');
$count=DB::table('domains_audit_archives')->count();BusinessSettingsSchema::install();same($count,DB::table('domains_audit_archives')->count(),'Reruns never duplicate conflict evidence');
$nativeSuccessful=true;echo "Large conflicting choices import with bounded encrypted history batches.\n";
