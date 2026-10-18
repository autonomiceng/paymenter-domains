<?php
// Enom 444f31c ListTlds layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages;
use Filament\Resources\Pages\ListRecords;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
class ListTlds extends ListRecords
{protected static string $resource=TldResource::class;public function getTitle(): string{return 'TLDs & pricing';}}
