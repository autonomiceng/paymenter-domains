<?php
// Enom 444f31c Api/RegistrarClient + f7175ff DomainOperations; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
use Paymenter\Extensions\Servers\Domains\Data\{AttemptEvidence, TransferStatus};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ProtocolResult;
final class TransferState
{
    public static function read(AttemptEvidence $attempt, ProtocolResult $reply): TransferStatus
    {
        $h = $reply->value('transferorder'); $d = $reply->value('transferorder.transferorderdetail');
        if ($reply->status !== 'success' || !$attempt->transferReference || !is_array($h) || array_is_list($h) || !is_array($d) || array_is_list($d)
            || ($h['transferorderid'] ?? null) !== $attempt->transferReference || !is_string($d['sld'] ?? null) || !is_string($d['tld'] ?? null)
            || strtolower($d['sld'].'.'.$d['tld']) !== $attempt->query->name) { return TransferStatus::Unknown; }
        $header = $h['statusid'] ?? null; $detail = $d['statusid'] ?? null;
        if ($header === '3' && $detail === '5') { return TransferStatus::Completed; }
        if ($header === '2' && in_array($detail, ['2','4','6','7','8','15','16','17','18','19','20','21','22','23','24','25','26','27','30','31','32','33','34','36','37','45','101'], true)) { return TransferStatus::Rejected; }
        if (in_array($header, ['0','1','4','5'], true) && in_array($detail, ['0','1','3','9','10','11','12','13','14','28','29','35','100'], true)) { return TransferStatus::Pending; }
        return TransferStatus::Unknown;
    }
}
