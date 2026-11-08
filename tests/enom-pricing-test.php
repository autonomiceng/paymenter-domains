<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/accounts.php';
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, Environment, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Price, Tld};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver;
use Paymenter\Extensions\Servers\Domains\Services\{FoundationSchema, PriceCatalogue, PricingRule, PricingRules};
FoundationSchema::install(); $account = fixtureAccount(1, 'enom', 'offline');
Tld::create([...$account, 'tld' => 'com', 'enabled' => false, 'renew_enabled' => true]);
$clock = new class implements Clock { public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-10-18T12:00:00Z'); } };
$binding = new AccountBinding('enom', 1, Environment::Test, 'offline');
$context = fn () => new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+1 hour'), new RequestBudget(5), $clock);
$wire = new class implements Transport {
    public array $priceParameters = [];
    public string $price = '8.9550'; public bool $enabled = true; public bool $fail = false;
    public function send(Environment $environment, ReadCommand $command, #[\SensitiveParameter] array $parameters,
        #[\SensitiveParameter] string $uid, #[\SensitiveParameter] string $token, int $timeoutMs): string
    {
        if ($this->fail) { throw new RuntimeException('offline timeout'); }
        if ($command === ReadCommand::TldInfo) { return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done><TLDs><TLD><TLD>com</TLD><Registration><Minimum>1</Minimum><Maximum>3</Maximum><Unit>year</Unit><DNSRequired>false</DNSRequired></Registration><ExtAttributes>false</ExtAttributes><Renewal><AutoRenewOnly>false</AutoRenewOnly></Renewal></TLD></TLDs></interface-response>'; }
        if ($command === ReadCommand::Attributes) { return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done><ResponseCount>0</ResponseCount></interface-response>'; }
        $this->priceParameters = $parameters;
        return '<interface-response><Command>'.$command->value.'</Command><ErrCount>0</ErrCount><Done>true</Done><ProductPrice><ProductEnabled>'.($this->enabled ? 'true' : 'false').'</ProductEnabled><Price>'.$this->price.'</Price></ProductPrice></interface-response>';
    }
};
$driver = new Driver(new Client($binding, 'offline', 'synthetic', $wire), ['com']);
$query = new Query('example.com', Action::Register, 3);
$read=static fn()=>$driver->productPrice('com','register',3,$context());
$observed=$read();same('success',$observed->status,'Synthetic Enom price reply accepted');
same(2, $wire->priceParameters['Years'], 'Three-year native quote selects the documented two-to-four-year bracket');
same(['tld'=>'com','action'=>'register','years'=>3,'price'=>'8.9550'],$observed->facts(),'Exact annual precision and requested term preserved');
$before=Price::count();$wire->fail=true;same('unknown',$read()->status,'Timeout stays unknown');same($before,Price::count(),'Pure failed quote never changes persisted prices');
$wire->fail=false;$wire->price='8.95500000';same('unknown',$read()->status,'Unsupported precision stays unknown');
$wire->price='8.9550';$wire->enabled=false;same('no_price',$read()->status,'Explicit disabled is distinct from failed read');
same('8.9550',$observed->facts()['price'],'Prior observed reply remains immutable after later failures and no-price');
// Persisted normalized publication and failed-run retention are covered by offer-feed/work/writer.
$nativeSuccessful=true;echo "Enom exact price read, unknown failure and disabled result passed.\n";
