<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,RequestFacts};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Data\{Environment,HistoryAcceptance};
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestTransfer as TransferPolicy, EnomTestRegistration, EnomTestSendLimit, EnomTestSendLimitSchema, RuntimePorts, RuntimeWork, TransferInputs, HistoryAcceptances, OwnedDomains, Refunds};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController;
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
$transferGroup='preflight';
require __DIR__.'/enom-transfer-cases-fixture.php';
foreach(['404','locked','young','recent'] as $fault){$reset();$blocked=$newTransfer(placeholderComFixtureName('transfer-rdap-'.$fault.'-placeholder.com'));$code($blocked,$secret);$reset();$rdapMode->mode=$fault;$run($blocked);$rdapMode->mode='ok';same('held',$blocked->fresh()->state,'Fresh RDAP '.$fault.' refusal holds: '.$blocked->fresh()->state.' / '.$blocked->fresh()->safe_code);same(['Check'],array_column($GLOBALS['enomWire']['forms'],'Command'),'RDAP refusal stops before cost/write');}
$reset();$unknown=$newTransfer(placeholderComFixtureName('transfer-rdap-unknown-placeholder.com'));$code($unknown,$secret);$reset();$rdapMode->mode='unknown';$run($unknown);$rdapMode->mode='ok';same('uncertain',$unknown->fresh()->state,'RDAP unknown uses explicit advisory policy');same(true,$unknown->fresh()->attempt['transfer_admission']['rdap_unchecked'],'RDAP uncertainty retained');same(null,$unknown->fresh()->attempt['transfer_admission']['base_expiry'],'Unknown baseline not invented');
$reset();$revoked=$newTransfer(placeholderComFixtureName('transfer-revoked-preflight-placeholder.com'));$code($revoked,$secret);$reset();$revokeBalances=0;$GLOBALS['enomWire']['during']=static function($p)use(&$revokeBalances):void{if($p['Command']==='GetBalance'&&++$revokeBalances===2){config(['domains.enom_writes.transfer'=>false]);}};$run($revoked);config(['domains.enom_writes.transfer'=>true]);expect(!in_array('TP_CreateOrder',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Current transfer policy revoked before seal');same('held',$revoked->fresh()->state,'Cached transfer composition rechecks deployment gate');same(6,count($GLOBALS['enomWire']['forms']),'Current policy rechecked after both complete preflights and before seal');
$reset();$lost=$newTransfer(placeholderComFixtureName('transfer-lost-reply-placeholder.com'));$code($lost,$secret);$reset();$GLOBALS['enomWire']['during']=static function($p):void{if($p['Command']==='TP_CreateOrder'){$GLOBALS['enomWire']['timeout']=true;}};$run($lost);same('uncertain',$lost->fresh()->state,'Lost transfer reply uncertain');same(1,count(array_filter($GLOBALS['enomWire']['forms'],static fn($p)=>$p['Command']==='TP_CreateOrder')),'Lost transfer sent once');$creditCount=DB::table('credits')->count();$reset();$run($lost);same('uncertain',$lost->fresh()->state,'No transfer reference cannot invent completion');same([],$GLOBALS['enomWire']['forms'],'Missing transfer reference keeps automatic status and history closed');expect(!in_array('TP_CreateOrder',array_column($GLOBALS['enomWire']['forms'],'Command'),true),'Lost transfer never resent');same($creditCount,DB::table('credits')->count(),'Lost transfer no automatic credit');

file_put_contents($receipt.'.transfer-fixture',json_encode(['staff'=>$staff->id,'user'=>$user->id,'server'=>$server->id,'tld'=>$tld->id],JSON_THROW_ON_ERROR));
$nativeSuccessful=true;
