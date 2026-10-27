<?php
// Real MariaDB source scenarios, all registrar evidence synthetic. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{DB,Schema};
use DomainsConversion\{Inputs,Planner,Converter,SourceSchema,AppHost};
$phase=getenv('CONVERSION_PHASE');
if($phase==='seed'){
    define('CONVERSION_NATIVE_BOOTSTRAP',__DIR__.'/mariadb-bootstrap.php');
    define('CONVERSION_SOURCE_MIGRATED',true);define('CONVERSION_DEFER_STAGE',true);
    define('CONVERSION_SOURCE_REVISION',getenv('CONVERSION_REVISION'));
    require __DIR__.'/conversion.php';
    file_put_contents(getenv('CONVERSION_SCRATCH').'/evidence.json',json_encode($evidence,JSON_THROW_ON_ERROR));
    file_put_contents(getenv('CONVERSION_SCRATCH').'/before.json',json_encode(['source_digest'=>Planner::sourceDigest(),
        'services'=>array_map(fn($s)=>$s->id,$services),'server_id'=>$server->id,'template_id'=>$template->id,'old'=>$old,'key_sha256'=>hash('sha256',config('app.key'))],JSON_THROW_ON_ERROR));
    // Persist precondition fixtures for a later real CLI app-profile refusal.
    echo "Source/native migrations and purchased/pending/reminder scenarios seeded.\n";
}else{
    require __DIR__.'/mariadb-bootstrap.php';
    foreach(['Inputs','SourceSchema','Planner','Mapper','Journal','Host','AppInventory','QuietWindow','AppHost','Converter']as$class){require dirname(__DIR__,2).'/scripts/conversion/'.$class.'.php';}
    Inputs::revision(getenv('CONVERSION_REVISION'));
    $before=json_decode(file_get_contents(getenv('CONVERSION_SCRATCH').'/before.json'),true,512,JSON_THROW_ON_ERROR);
    $evidence=Inputs::json(getenv('CONVERSION_SCRATCH').'/evidence.json');
    $converter=new Converter($evidence,static fn()=>Inputs::registrarsOff(),'app');
    if($phase==='refusals'){
        function refuses(Closure $call,string $reason):void{try{$call();}catch(DomainException $e){expect(str_contains($e->getMessage(),$reason),'Specific refusal');return;}throw new LogicException('Accepted refusal: '.$reason);}
        $invoice=DB::table('invoices')->orderBy('id')->first();DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'pending']);
        expect(count($converter->run()['pending_payable_invoices'])>0,'Payable native debt inventoried');
        refuses(fn()=>$converter->run(true),'Legacy holdings have payable invoices');DB::table('invoices')->where('id',$invoice->id)->update(['status'=>$invoice->status]);
        $row=(array)DB::table('enom_operations')->where('id',121)->first();$row=array_replace($row,['id'=>301,'operation_key'=>'unsupported:fixture','action'=>'nameservers','invoice_id'=>null,'invoice_item_id'=>null,'confirmed_expires_at'=>null,'policy'=>Inputs::encrypt([])]);
        DB::table('enom_operations')->insert($row);expect(count($converter->run()['unsupported_history'])===1,'Unsupported terminal history inventoried');
        refuses(fn()=>$converter->run(true),'Unsupported terminal history');DB::table('enom_operations')->where('id',301)->delete();
        $class='Paymenter\\Extensions\\Servers\\Enom\\Jobs\\ObserveDomain';$serialized='O:'.strlen($class).':"'.$class.'":1:{s:8:"domainId";i:21;}';
        $payload=json_encode(['data'=>['commandName'=>$class,'command'=>$serialized]]);
        $id=DB::table('jobs')->insertGetId(['queue'=>'default','payload'=>$payload,'attempts'=>0,'reserved_at'=>null,'available_at'=>0,'created_at'=>0]);
        same(1,$converter->run()['queue_removals'],'Known legacy queue audit maps ID on MariaDB');
        DB::table('jobs')->where('id',$id)->delete();same($before['source_digest'],Planner::sourceDigest(),'Refusal scenarios preserve source/money');
        echo "Payable/terminal refusals and mapped queue audit passed on MariaDB.\n";
    }elseif($phase==='audit'){
        // Fixture-only mapping/audit proof; actual app-profile CLI separately refuses this queue shape.
        $class='Paymenter\\Extensions\\Servers\\Enom\\Jobs\\ObserveDomain';$serialized='O:'.strlen($class).':"'.$class.'":1:{s:8:"domainId";i:21;}';
        $payload=json_encode(['data'=>['commandName'=>$class,'command'=>$serialized]]);
        $id=DB::table('jobs')->insertGetId(['queue'=>'default','payload'=>$payload,'attempts'=>0,'reserved_at'=>null,'available_at'=>0,'created_at'=>0]);
        $result=$converter->run(true,getenv('CONVERSION_SCRATCH').'/backup.json');expect($result['complete'],'Fixture audit apply completed');
        same(false,DB::table('jobs')->where('id',$id)->exists(),'Mapped fixture job removed');
        $audit=Inputs::decrypt(DB::table('domains_conversion_runs')->value('audit'));
        same($payload,$audit['changes']['jobs'][$id]['original']['payload'],'Exact original queue payload encrypted in MariaDB audit');
        echo "Fixture-only mapped queue apply and encrypted audit verified.\n";
    }elseif($phase==='queue'){
        $class='Paymenter\\Extensions\\Servers\\Domains\\Jobs\\RunRuntime';
        DB::table('jobs')->insert(['queue'=>'default','payload'=>json_encode(['data'=>['commandName'=>$class]]),'attempts'=>0,'reserved_at'=>null,'available_at'=>0,'created_at'=>0]);
    }elseif($phase==='drain'){
        // Fixture-only removal, after proving the app CLI refuses; not an operator queue-clearing recipe.
        DB::table('jobs')->delete();
    }elseif($phase==='backup'){
        file_put_contents(getenv('CONVERSION_SCRATCH').'/backup-tables.json',json_encode(DomainsConversion\QuietWindow::snapshot()['tables'],JSON_THROW_ON_ERROR));
    }elseif($phase==='readback'){
        $d=Paymenter\Extensions\Servers\Domains\Models\Domain::findOrFail(21);
        same('converted_purchase',$d->provenance,'Positive purchased holding');same('2028-10-18',$d->provider_expires_at->format('Y-m-d'),'Confirmed expiry');
        same(4,DB::table('domains_domains')->count(),'Holding count');same(3,DB::table('domains_domains')->where('provenance','adoption_pending')->count(),'Pending count');
        same(5,DB::table('domains_operations')->count(),'All original operations retained');
        foreach([21,22,23,24]as$id){
            $source=DB::table('enom_domains')->where('id',$id)->first();$target=DB::table('domains_domains')->where('id',$id)->first();
            same($source->domain,$target->reservation_name,'Reservations preserved');
            same(DB::table('enom_operations')->where('id',100+$id)->value('payload'),DB::table('domains_operations')->where('id',100+$id)->value('payload'),'Encrypted history bytes retained');
            same('retained-secret',Inputs::decrypt($source->snapshot)['epp'],'Retained key decrypts source history');
            if($id!==21){same(null,$target->provider_expires_at,'Pending has no authority expiry');same(0,(int)$target->billing_managed,'Pending billing disabled');}
        }
        same('uncertain',DB::table('domains_operations')->where('id',123)->value('state'),'Unknown outcome remains unknown');
        same(0,DB::table('domains_accounts')->where('processing_enabled',true)->count(),'Processing remains off');
        same(0,DB::table('domains_sales_policies')->where('register_enabled',true)->count(),'New sales remain off');
        same('Customized retained reminder',DB::table('notification_templates')->where('id',$before['template_id'])->value('subject'),'Customized reminder retained');
        $user=App\Models\User::findOrFail($d->user_id);
        same(null,Paymenter\Extensions\Servers\Domains\Services\ReminderDelivery::reserve(21,'renew:1:2028-10-18:30','domains_domain_expiring',[],$user,time()),'Legacy reminder claim suppresses duplicate');
        foreach($before['services']as$id=>$service){same((int)$service,(int)DB::table('domains_domains')->where('id',$id)->value('service_id'),'Preserved native service IDs');}
        same($before['source_digest'],Planner::sourceDigest(),'Money/source/expiry invariants');
        same('4.25',(string)DB::table('credits')->value('amount'),'Exact native decimal credit');
        same(0,DB::table('services')->whereNotNull('expires_at')->count(),'Null native expiry');
        same('retained payload',DB::table('failed_jobs')->value('payload'),'Failed job audit retained');
        same('domains_domain_expiring',DB::table('notification_templates')->where('id',$before['template_id'])->value('key'),'Template identity retained');
        same(0,(int)DB::table('notification_preferences')->value('mail_enabled'),'Reminder opt-out preserved');
        $account=Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$before['server_id'])->sole();
        $op=Paymenter\Extensions\Servers\Domains\Models\Operation::where('domain_id',21)->where('action','renew')->sole();
        $binding=Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($account);
        expect(Paymenter\Extensions\Servers\Domains\Services\RenewalAuthority::completed($op,$d,$binding),'Converted proof usable for retained renewal');
        same('9.0000',$op->attempt['authorized_cost']['amount'],'Exact cost cap');
        foreach(DB::select('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')as$table){same('InnoDB',$table->engine,'All actual tables use InnoDB');}
        same(1,(int)DB::selectOne("SELECT JSON_VALID(eligibility) AS valid FROM domains_prices WHERE action='renew' LIMIT 1")->valid,'Native JSON is valid');
        same(['converted'=>true],json_decode(DB::table('domains_prices')->where('action','renew')->value('eligibility'),true,512,JSON_THROW_ON_ERROR),'JSON content retained');
        same('9.00000000',(string)DB::table('domains_prices')->where('action','renew')->where('tld','com')->value('wholesale'),'Eight-place decimal stored exactly');
        same('complete',DB::table('domains_conversion_runs')->value('state'),'Completion readback');
        $version=DB::selectOne('SELECT VERSION() AS version')->version;expect(str_starts_with($version,'11.4.5-MariaDB'),'Exact MariaDB server version');
        echo json_encode(['server_version'=>$version,'complete'=>true,'source_revision'=>Inputs::selectedRevision(),'holdings'=>4,'pending'=>3,'proof'=>true,'innodb'=>true,'json'=>true,'decimal'=>true])."\n";
    }elseif($phase==='restore'){
        same(json_decode(file_get_contents(getenv('CONVERSION_SCRATCH').'/backup-tables.json'),true,512,JSON_THROW_ON_ERROR),DomainsConversion\QuietWindow::snapshot()['tables'],'Every application table round-trips through SQL dump');
        Inputs::source(getenv('CONVERSION_SOURCE'),Inputs::selectedRevision());
        same($before['key_sha256'],hash('sha256',config('app.key')),'Restore encryption key retained');
        same('retained-secret',Inputs::decrypt(DB::table('enom_domains')->where('id',21)->value('snapshot'))['epp'],'Restored history decrypts');
        same(false,Schema::hasTable('domains_conversion_runs'),'Dump restore removes cutover journal');
        same(0,DB::table('domains_domains')->count(),'Pre-apply target restored empty');
        same('Enom',DB::table('extensions')->where('id',$before['server_id'])->value('extension'),'Native extension restored');
        same($before['source_digest'],Planner::sourceDigest(),'Restored source/native finance digest');
        echo "Pre-apply dump restore and retained financial digest verified.\n";
    }else{throw new LogicException('Unknown fixture phase');}
}
$nativeSuccessful=true;
