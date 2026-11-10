<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// While one registrar change runs, the other rows keep their values and say when they open.
require __DIR__.'/Support/management.php';
[$d,$driver]=$holding(placeholderComFixtureName('customer-waiting-placeholder.com'));
require __DIR__.'/Support/owner-pages.php';
app()->forgetInstance(Paymenter\Extensions\Servers\Domains\Services\Storefront::class);
$before=$owners->present($d->fresh(),true)->management;
expect(count($before)>1,'The fixture shows more than one setting');
$op=$submit($d,$driver,'nameservers',['nameservers'=>['ns1.example.net','ns2.example.net']]);
// The registrar accepts the change but the readback does not show it yet, so the change stays unresolved and blocks every other change.
$state->apply=false; $sent=$management->run($op->id,$driver);
expect(!Paymenter\Extensions\Servers\Domains\Services\ManagementEvidence::resolved($sent,$d->fresh()),'The nameserver change stays unresolved: '.$sent->state);
$during=$owners->present($d->fresh(),true)->management;
expect($during['nameservers']['progress']['pending']??false,'The nameserver change is running');
same([],array_values(array_diff(array_keys($before),array_keys($during))),'No row disappears while the nameserver change runs');
$waiting=array_filter($during,fn($section,$key)=>$key!=='nameservers'&&!$section['actions'],ARRAY_FILTER_USE_BOTH);
expect($waiting!==[],'Other rows wait for the change');
foreach ($waiting as $key=>$section) { same('Opens when the nameservers change finishes.',$section['waiting']??null,'Row says when it opens: '.$key); }
$request=Illuminate\Http\Request::create('/domains/'.$d->id); $request->setUserResolver(fn()=>$user); $request->setLaravelSession(app('request')->session());
$html=(new Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController($owners))->show($request,$d->id)->render();
$dom=storefrontDom($html);
foreach (array_keys($waiting) as $key) {
    // Auto-renew lives in the renewal card, and the page hides privacy in every state.
    if ($key==='auto_renew_off' || $key==='privacy' && !Paymenter\Extensions\Servers\Domains\Presentation\PrivacyVisibility::SHOWN) { continue; }
    $row=$dom->query('//*[@id="'.$key.'"]')->item(0);
    expect($row!==null&&str_contains($row->textContent,'Opens when the nameservers change finishes.'),'Rendered row stays with its reason: '.$key);
}
$lower=new ReflectionMethod(Paymenter\Extensions\Servers\Domains\Services\OwnedDomains::class,'lowerKeepingAcronyms');
foreach (['DNS records'=>'DNS records','WHOIS privacy'=>'WHOIS privacy','DNSSEC'=>'DNSSEC','Transfer lock'=>'transfer lock','Nameservers'=>'nameservers'] as $label=>$want) { same($want,$lower->invoke(null,$label),'Waiting label keeps acronyms: '.$label); }
// Unsupported settings are filtered before pending work makes supported rows wait.
$limitedPorts=new Paymenter\Extensions\Servers\Domains\Services\OwnerPorts(static fn($binding)=>new class($binding,static fn()=>throw new LogicException('No provider I/O')) extends Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol {
    public function capabilities(): Paymenter\Extensions\Servers\Domains\Data\Capabilities {
        return new Paymenter\Extensions\Servers\Domains\Data\Capabilities($this->binding,
            array_fill_keys(['nameservers','contacts'],new Paymenter\Extensions\Servers\Domains\Data\Capability(Paymenter\Extensions\Servers\Domains\Data\Support::Supported))
            +['lock'=>new Paymenter\Extensions\Servers\Domains\Data\Capability(Paymenter\Extensions\Servers\Domains\Data\Support::Unsupported)]);
    }
    public function validate(string $action,array $payload,string $name):array {return $payload;}
    public function prepare(Paymenter\Extensions\Servers\Domains\Data\ManagementRequest $r,Paymenter\Extensions\Servers\Domains\Data\RequestContext $c):array {throw new LogicException('No I/O');}
    public function observe(Paymenter\Extensions\Servers\Domains\Data\ManagementRequest $r,Paymenter\Extensions\Servers\Domains\Data\RequestContext $c):Paymenter\Extensions\Servers\Domains\Data\ManagementResult {throw new LogicException('No I/O');}
    protected function apply(Paymenter\Extensions\Servers\Domains\Data\ManagementRequest $r,Paymenter\Extensions\Servers\Domains\Data\RequestContext $c):Paymenter\Extensions\Servers\Domains\Data\ManagementResult {throw new LogicException('No I/O');}
    protected function readCommands():array {return [];}
    protected function validateRead(string $command,array $parameters):void {throw new LogicException('No I/O');}
    protected function writeCommand(string $action):string {throw new LogicException('No I/O');}
    protected function origin(Paymenter\Extensions\Servers\Domains\Data\RequestContext $c):string {throw new LogicException('No I/O');}
});
$limited=new Paymenter\Extensions\Servers\Domains\Services\OwnedDomains($clock,$limitedPorts,$management,$ownerBilling);
$dto=$limited->present($d->fresh(),true);
expect(!isset($dto->management['lock']),'An unsupported lock has no DTO row while nameservers are pending');
same('Opens when the nameservers change finishes.',$dto->management['contacts']['waiting']??null,'A supported contacts row still waits');
$html=(new Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController($limited))->show($request,$d->id)->render();$dom=storefrontDom($html);
same(0,$dom->query('//*[@id="lock"]')->length,'The unsupported lock is absent from rendered rows');
expect(str_contains($dom->query('//*[@id="contacts"]')->item(0)?->textContent??'','Opens when the nameservers change finishes.'),'The supported contacts row renders its wait');
$nativeSuccessful=true; echo "Customer rows during a pending change passed.\n";
