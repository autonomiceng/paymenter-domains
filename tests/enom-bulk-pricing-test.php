<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Environment, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ReadCommand, ResponseParser, Transport};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{BulkPricing, CataloguePreview, Driver, EnomTestReconciliationReader};

// Two rows from https://api.enom.com/docs/pe_getretailpricing Text example, metadata omitted.
$text = <<<'TEXT'
;URL Interface
tld1: com
tldid1: 0
registerprice1: 12.00
resellerpricereg1: 8.95
registerenabled1: true
renewprice1: 18.00
resellerpricerenew1: 8.95
renewenabled1: true
transferprice1: 22.00
resellerpricetran1: 8.95
transferenabled1: true
IDNCategory1:
tld2: net
tldid2: 1
registerprice2: 50.00
resellerpricereg2: 9.00
registerenabled2: true
renewprice2: 10.21
resellerpricerenew2: 9.00
renewenabled2: true
transferprice2: 10.21
resellerpricetran2: 9.00
transferenabled2: true
IDNCategory2:
Command: PE_GETRETAILPRICING
ErrCount: 0
MaxPeriod: 10
Done: true
TEXT;
$parser = new ResponseParser;
$reply = $parser->parse(ReadCommand::RetailPricing, $text);
$rows = BulkPricing::rows($reply);
same(['com', 'net'], array_keys($rows), 'Documented indexed Text shape');
same(['12.00', '8.95', '18.00', '8.95', '22.00', '8.95'], array_map(fn ($k) => $rows['com'][$k],
    ['registerprice', 'resellerpricereg', 'renewprice', 'resellerpricerenew', 'transferprice', 'resellerpricetran']), 'Retail and reseller amounts stay distinct');
same(strlen($text), $reply->responseBytes, 'Measured bytes without retaining a raw response');
$disabled = BulkPricing::rows($parser->parse(ReadCommand::RetailPricing, str_replace('registerenabled1: true', 'registerenabled1: false', $text)));
same(false, $disabled['com']['registerenabled'], 'Disabled row retained with its price');
same(null, $rows['com']['resellerpricergp'], 'Missing fields stay unknown');
foreach ([$text."\ntld1: org", str_replace('tld2: net', 'tld2: com', $text), $text."\ncount: 3", str_replace('8.95', 'private-value', $text), str_replace('Done: true', 'Done: false', $text)] as $bad) {
    same(null, BulkPricing::rows($parser->parse(ReadCommand::RetailPricing, $bad)), 'Contradictory or malformed prices are not a partial catalogue');
}
// Product nesting from https://api.enom.com/docs/pe_getdomainpricing XML example; one row excerpt.
$xml = '<interface-response><pricestructure><product><tld>com</tld><registerprice>8.95</registerprice><resellerpricereg>8.95</resellerpricereg><registerenabled>True</registerenabled><renewprice>8.95</renewprice><resellerpricerenew>8.95</resellerpricerenew><renewenabled>True</renewenabled><transferprice>8.95</transferprice><resellerpricetran>8.95</resellerpricetran><transferenabled>True</transferenabled></product><count>1</count></pricestructure><Command>PE_GETDOMAINPRICING</Command><ErrCount>0</ErrCount><Done>true</Done></interface-response>';
same('8.95', BulkPricing::rows($parser->parse(ReadCommand::DomainPricing, $xml))['com']['resellerpricereg'], 'Documented XML singleton product');

$wire = new class($text) implements Transport {
    public int $calls = 0;
    public function __construct(private string $text) {}
    public function send(Environment $e, ReadCommand $c, array $p, string $uid, string $token, int $timeoutMs): string { $this->calls++; return $this->text; }
};
$binding = new AccountBinding('enom', 1, Environment::Test, 'synthetic-uid');
$driver = new Driver(new Client($binding, 'synthetic-uid', 'synthetic-token', $wire), []);
$preview = new CataloguePreview($driver);
$reader = new EnomTestReconciliationReader($preview, $driver);
$context = fn (int $budget) => new RequestContext($binding, RequestOrigin::Background, new DateTimeImmutable('+30 seconds'), new RequestBudget($budget));
same($rows, $reader->bulkPricing(false, 1, $context(1))->facts()['rows'], 'Bound app reader reaches the normal client');
same('unknown', $reader->bulkPricing(false, 1, $context(0))->status, 'Exhausted budget stays unknown');
same(1, $wire->calls, 'Exhausted request budget cannot send');
foreach ([[ReadCommand::RetailPricing, ['TLDOnly'=>0, 'Years'=>1]], [ReadCommand::DomainPricing, ['UseQtyEngine'=>1, 'Years'=>0]], [ReadCommand::RetailPricing, ['TLDOnly'=>1, 'Years'=>1, 'PW'=>'override']]] as [$command, $parameters]) {
    try { $command->validate($parameters); throw new LogicException('Expected named parameter refusal'); } catch (InvalidArgumentException) {}
}
same('Text', ReadCommand::RetailPricing->responseType(), 'Bulk uses documented cost-bearing Text format');
echo "enom bulk pricing: ok\n";
