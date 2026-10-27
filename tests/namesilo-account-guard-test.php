<?php
// Synthetic funded dispatch guard; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/namesilo-registration-fixture.php';
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Labels;
$server=Server::findOrFail($binding->serverId);
$setExpected=static fn($email)=>$server->settings()->updateOrCreate(['key'=>'domains_namesilo_expected_default_contact_email'],['value'=>$email,'encrypted'=>true,'type'=>'string']);
$setExpected('expected@example.com');
$guard=(object)['email'=>'wrong@example.com','id'=>'900','default'=>'1','mode'=>'email','reads'=>0];
$paidCount=0;
$original=$exchange;
$exchange=static function($origin,$command,$params,$timeout)use($guard,$original,&$paidCount,$clock):string {
    if($command==='contactAdd'&&($guard->preflightDelay??0)>0){$clock->instant=$clock->now()->modify('+'.$guard->preflightDelay.' seconds');$guard->preflightDelay=0;}
    if($command==='contactList'&&$params===[]){return nameSiloGuardWire($guard);}
    if(in_array($command,['registerDomain','renewDomain','transferDomain'],true)){$paidCount++;}
    return $original($origin,$command,$params,$timeout);
};
$run=static function($case)use(&$registration,$makeEngine,$binding,$exchange,$runRegistration,$nsNew){
    $registration->calls=[];$makeEngine($binding,$exchange);
    return $runRegistration($nsNew(placeholderComFixtureName('guard-'.$case.'-placeholder.com')));
};
$result=$run('email-first');
same('held',$result->state,'Wrong default email holds funded registration');
expect(!in_array('registerDomain',$registration->calls,true),'Email refusal sends no paid request');
same('namesilo_account_guard',$result->safe_code,'Staff sees the account guard reason');
expect(str_contains(Labels::requestStatus($result)['reason'],'Check the API key'),'Staff guidance names the corrective check');
expect(!$server->settings()->where('key','domains_namesilo_default_contact_fingerprint')->exists(),'Failed first check records no pin');
$reads=$guard->reads;
same('held',$engine->execute($result->id)->state,'Held registration stays held without a retry loop');
same($reads,$guard->reads,'Held registration does not retry the account read');
same(0,$paidCount,'Held registration remains unsent');
$guard->email=' EXPECTED@example.com ';
same('pending',$run('match')->state,'Trimmed case-insensitive match permits registration');
assertNameSiloAccountRefusals($binding,$guard,$run,static function()use(&$paidCount){return $paidCount;});
expect(!in_array('registerDomain',$registration->calls,true),'Final refusal has no paid request');
same('pending',$run('match-again')->state,'Same pinned singleton profile permits a later paid request');
$deferred=$nsNew(placeholderComFixtureName('guard-deferred-placeholder.com'));
$deferred->update(['payload'=>$deferred->payload+['nameservers'=>['ns1.provider.example','ns2.provider.example'],'privacy'=>false]]);
$registration->query=Paymenter\Extensions\Servers\Domains\Services\OperationClaims::query($deferred,$deferred->domain);
assertNameSiloDeferredGuard($deferred,$claims,$engine,$clock,$guard,static function()use(&$paidCount){return $paidCount;});
$field=array_values(array_filter((new \Paymenter\Extensions\Servers\Domains\Domains)->getConfig(),fn($f)=>$f['name']==='domains_namesilo_expected_default_contact_email'));
same(1,count($field),'Native admin extension config exposes the expected contact setting');
same('Expected default contact email (NameSilo)',$field[0]['label'],'Staff can identify the setting');
echo "NameSilo account registration refusal matrix passed.\n";
$nativeSuccessful=true;
