<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages;
use Filament\Resources\Pages\ListRecords;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\WatchActions;
class ListConfiguration extends ListRecords
{
    protected static string $resource=RoutingProfileResource::class;
    public function getTitle(): string{return 'DNS templates';}
    public function getSubheading(): ?string{return null;}
    protected function getHeaderActions(): array{return [WatchActions::configuration('profile',null)];}
}
