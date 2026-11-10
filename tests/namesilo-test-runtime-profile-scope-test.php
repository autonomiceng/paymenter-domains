<?php
// The installed TEST composition must let a paid order create its contact while the purchase holds its send scope.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
require __DIR__.'/namesilo-test-activation-test.php';
$nativeSuccessful=false; // The activation fixture marks its own success; this file must earn its own.
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionPort,AcquisitionPorts,DomainOperations,ProfileContactOperations,RegistrationProfiles};
$field=static fn(object $object,string $class,string $name)=>(new \ReflectionProperty($class,$name))->getValue($object);
$ports=TestRuntime::factory($binding)($binding);
foreach(['registration'=>'register','transfer'=>'transfer'] as $route=>$action){
    $port=$field($ports['acquisition'],AcquisitionPorts::class,$route);
    $writer=$field($field($port,AcquisitionPort::class,'engine'),DomainOperations::class,'writer');
    $operations=$field($field($port,AcquisitionPort::class,'profiles'),RegistrationProfiles::class,'operations');
    $profileGrant=$field($operations,ProfileContactOperations::class,'reservation');
    $op=new Operation;$op->forceFill(['id'=>4100,'server_id'=>$binding->serverId,'account_fingerprint'=>$binding->fingerprint,'action'=>$action]);
    // DomainOperations::execute enters the purchase scope, and the preflight inside it creates the order's contact.
    $entered=$writer->withinReservation($op,static function()use($profileGrant,$writer,$op):string {
        return $profileGrant->run('management',17,static function()use($profileGrant,$writer,$op):string {
            $profileGrant->reserve('contactAdd',['fn'=>'Synthetic','ln'=>'Owner','cp'=>'','ad'=>'1 Placeholder Way','ad2'=>'','cy'=>'Placeholder',
                'st'=>'CA','zp'=>'90001','ct'=>'US','em'=>'owner@example.com','ph'=>'2135550100']);
            expect($profileGrant->unconsumed(17),'Contact creation arms its own grant');
            expect(!$writer->unconsumed((int)$op->id),'Contact creation never arms the purchase grant');
            return 'entered';
        });
    });
    same('entered',$entered,'A '.$action.' creates its contact inside the purchase scope');
}
// Staff can't fix this hold by editing contact details, so the words name the real problem.
same("Not sent: couldn't create the order's contact at NameSilo. The customer's details passed our checks.",
    \Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::reason('contact_profile_proof_required'),'Staff read what stopped the order');
echo "Installed NameSilo TEST orders create their contact with a separate send grant.\n";
$nativeSuccessful=true;
