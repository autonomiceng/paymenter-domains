<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Interrupted nontransactional DDL can retain the imported row without recording its migration.
require __DIR__.'/native-app.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{BusinessSettingsSchema,CardFee,Installation};
$install=new Installation;$install->install();
$saved=DB::table(BusinessSettingsSchema::TABLE)->sole();
expect((bool)$saved->imported,'The retained table contains a completed import');
DB::table('migrations')->where('migration',BusinessSettingsSchema::MIGRATION)->delete();
$native=DB::table('settings')->insertGetId(['key'=>CardFee::KEY,'type'=>'array','value'=>json_encode(CardFee::DEFAULT),'encrypted'=>false]);
$migrations=DB::table('migrations')->orderBy('id')->get()->toJson();$archives=DB::table('domains_audit_archives')->count();
$error=null;try{$install->install();}catch(DomainException $e){$error=$e->getMessage();}
same('Retained global domain choices have no installation record.',$error,'Unrecorded populated choices refuse before migrations run');
same($migrations,DB::table('migrations')->orderBy('id')->get()->toJson(),'Refusal never recreates migration history');
same((array)$saved,(array)DB::table(BusinessSettingsSchema::TABLE)->sole(),'Refusal preserves the retained choice');
expect(DB::table('settings')->where('id',$native)->exists(),'Refusal never retires native source rows');
same($archives,DB::table('domains_audit_archives')->count(),'Refusal adds no import history');
$nativeSuccessful=true;echo "Unrecorded retained business choices refuse before migrations.\n";
