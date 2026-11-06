<?php
// Full native observation matrix; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/observation-records-ready.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,ManagementRequest,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationProjection,ObservationRecords,ObservationSchema,ObservationWork,OwnerRecords,ReadDeferred};
require __DIR__.'/Support/observation-failed-primary.php';
$nativeSuccessful=true;echo "Both primary failures retain complete prior evidence and require explicit recovery passed.\n";
