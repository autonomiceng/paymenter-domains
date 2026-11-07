<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real completed holdings and engine, synthetic transport.
declare(strict_types=1);
require __DIR__.'/owner-management-ready.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnerActionController, OwnedDomainController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{OwnedDomains,OwnerPorts,OwnerSetup,TransferInputs,Refunds,DomainManagement};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
require __DIR__.'/owner-input.php';
// Risky actions are refused until the confirm step sends its field; without script that step renders inline.
foreach([['lock',['locked'=>'0','confirmed'=>'0']],['lock',['locked'=>'0']],['nameservers',['nameservers'=>"ns1.example.com\nns2.example.com"]],['auth_code_email',[]]]as[$action,$data]){
    $before=Operation::count();$r=$post($action,$data);if(!array_key_exists('confirmed',$data)){$r->request->remove('confirmed');}
    $response=$controller->manage($r,$d->id,$action);same($before,Operation::count(),'Missing confirmation creates no request: '.$action);
    same(422,$response->getStatusCode(),'Missing confirmation re-renders the page: '.$action);$confirmDom=storefrontDom($response->getContent());
    same(1,$confirmDom->query('//li[@id="'.$action.'"]//form[@data-confirm]//dialog[@data-confirm-dialog][@open][not(ancestor::dialog[not(@open)])]//button[@name="confirmed"][@value="1"]')->length,'Inline confirm step without script: '.$action);
}
expect(str_contains($response->getContent(),'Email the auth code?'),'Confirm step names the concrete action');
$nativeSuccessful=true;echo "Owner management retained input, escaping and inline confirmation passed.\n";
