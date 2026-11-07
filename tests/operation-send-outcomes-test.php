<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-claims.php';
use App\Models\{Invoice, InvoiceTransaction};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{AdmittedRegistrarDriver, OperationPreflight};
use Paymenter\Extensions\Servers\Domains\Data\{AdmittedOperation, Capabilities, Capability, DispatchReadiness, Disposition, DriverResult, Evidence, Money, RequestContext, ResultReason, SendState, Support};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, DomainOperations, InvoiceBinding, OperationClaims};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
$op = $new('preseal.example.com'); $preflight->fail = true; $result = $assertNoSend($op); $preflight->fail = false;
same('absent', $result->attempt['seal'], 'Failure before seal retains absent marker'); expect(!array_key_exists('execution', $result->attempt), 'Preseal execution stays absent');
$op = $new('postseal.example.com'); $writer->fail = true; $result = $engine->execute($op->id); $writer->fail = false;
same('uncertain', $result->state, 'Lost response is uncertain'); expect(array_key_exists('execution', $result->attempt) && $result->attempt['execution'] === null, 'Postseal recorded-null differs from absent');
$assertNoSend($result, 'uncertain');
$op = $new('ack.example.com'); $writer->disposition = Disposition::Accepted; same('uncertain', $engine->execute($op->id)->state, 'Ack is not business completion'); $writer->disposition = Disposition::Unknown;
$nativeSuccessful=true;echo "Native operation claim scenario passed.\n";
