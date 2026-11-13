<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Edit,Query};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CatalogueMirrorRetirement,Installation};
extract(commerceFixture());
foreach(['enabled','register_enabled','renew_enabled','transfer_enabled','min_years','max_years'] as $column){expect(!Schema::hasColumn('domains_tlds',$column),'Legacy TLD mirror removed: '.$column);}
expect(!Schema::hasColumn('domains_prices','catalogue_observation'),'Duplicate current cost JSON removed');
// Translated fixture flags retain global disablement before action-specific choices.
$t=$tlds[0];$binding=AccountBindings::binding(Account::where('server_id',$t->server_id)->sole());
$renew=new Query($binding,['com'],'renew');$savedIntent=$t->catalogue_intent;
DB::table('domains_tlds')->where('id',$t->id)->update(tldColumns(['enabled'=>false,'renew_enabled'=>true],$t));
same('staff_stopped',$ports->readOffers($renew,Context::Staff)[0]->reason,'Global disablement still refuses renewal when its individual flag is on');
DB::table('domains_tlds')->where('id',$t->id)->update(tldColumns(['enabled'=>true,'renew_enabled'=>true],$t));
expect($ports->readOffers($renew,Context::Staff)[0]->available,'A globally enabled fixture can enable renewal');
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>$savedIntent]);
// Restore the exact previous column order around populated current rows, then run the real drop.
// SQLite's ADD COLUMN appends fields, unlike a retained table, so use a disposable fixture rebuild.
function priorCatalogueTable(string $table,array $insertions): void
{
    $sql=DB::table('sqlite_master')->where('type','table')->where('name',$table)->sole()->sql;
    $indexes=DB::table('sqlite_master')->where('type','index')->where('tbl_name',$table)->whereNotNull('sql')->pluck('sql');
    $rows=DB::table($table)->get()->map(fn($r)=>(array)$r)->all();
    $copy=$table.'_prior_fixture';$sql=str_replace('"'.$table.'"','"'.$copy.'"',$sql);
    foreach($insertions as $before=>$columns){$sql=str_replace('"'.$before.'"',$columns.', "'.$before.'"',$sql);}
    DB::statement($sql);if($rows){DB::table($copy)->insert($rows);}
    Schema::drop($table);Schema::rename($copy,$table);
    foreach($indexes as $index){DB::statement($index);}
}
DB::table('migrations')->whereIn('migration',[CatalogueMirrorRetirement::BEGIN,CatalogueMirrorRetirement::FINISH])->delete();
priorCatalogueTable('domains_tlds',['use_default_rule'=>"`enabled` tinyint(1) not null default '0', `register_enabled` tinyint(1) not null default '0', `renew_enabled` tinyint(1) not null default '0', `transfer_enabled` tinyint(1) not null default '0'",
    'registry_fields'=>"`min_years` integer not null default '1', `max_years` integer not null default '10'"]);
priorCatalogueTable('domains_prices',['catalogue_created'=>'"catalogue_observation" text']);
$intent=DB::table('domains_tlds')->orderBy('id')->pluck('catalogue_intent','id')->all();
CatalogueMirrorRetirement::begin();DB::table('migrations')->insert(['migration'=>CatalogueMirrorRetirement::BEGIN,'batch'=>99]);
CatalogueMirrorRetirement::install();DB::table('migrations')->insert(['migration'=>CatalogueMirrorRetirement::FINISH,'batch'=>99]);
same($intent,DB::table('domains_tlds')->orderBy('id')->pluck('catalogue_intent','id')->all(),'Old mirror values never replace the canonical staff choices');
$t=$tlds[0];$binding=AccountBindings::binding(Account::where('server_id',$t->server_id)->sole());$q=new Query($binding,['com'],'register');
$offers=$ports->readOffers($q,Context::Staff);$before=$offers[0];
$ids=DB::table('domains_prices')->orderBy('id')->pluck('id')->all();$retained=$t->catalogue_retained_annual_retail;
$ports->apply(Edit::republish($binding,'com'),$offers[0]->revision);
same($before->retail,$ports->readOffers($q,Context::Staff)[0]->retail,'Republishing retains the same retail without the old mirrors');
same($retained,$t->fresh()->catalogue_retained_annual_retail,'Retained annual retail stays separate');
same($ids,DB::table('domains_prices')->orderBy('id')->pluck('id')->all(),'Current price identities retained');
$rows=DB::table('domains_prices')->orderBy('id')->get()->toJson();
CatalogueMirrorRetirement::install();(new Installation)->install();CatalogueMirrorRetirement::verify();
same($rows,DB::table('domains_prices')->orderBy('id')->get()->toJson(),'Reruns preserve current rows and do not recreate dropped columns');
// Rehearse interruption after DDL, before the final history record.
DB::table('migrations')->where('migration',CatalogueMirrorRetirement::FINISH)->delete();
CatalogueMirrorRetirement::install();expect(!Schema::hasColumn('domains_tlds','enabled'),'Interrupted retirement resumes');
echo "Catalogue mirror removal, partial rerun, retained identities and republication passed.\n";$nativeSuccessful=true;
