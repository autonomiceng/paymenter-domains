<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources;
use Paymenter\Extensions\Servers\Domains\Admin\Support\DomainReadResource;
use Paymenter\Extensions\Servers\Domains\Models\DnsTemplate;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{EditConfiguration,ListConfiguration,ViewConfiguration};
class RoutingProfileResource extends DomainReadResource
{
    protected static ?string $model=DnsTemplate::class;
    protected static ?string $navigationLabel='DNS templates';
    protected static string|\BackedEnum|null $navigationIcon='heroicon-o-document-duplicate';
    protected static bool $shouldRegisterNavigation=false;
    protected static ?string $modelLabel='DNS template';
    protected static ?string $pluralModelLabel='DNS templates';
    protected static ?string $breadcrumb='DNS templates';
    protected static ?string $slug='dns-templates';
    public static function getPages(): array{return ['index'=>ListConfiguration::route('/'),'view'=>ViewConfiguration::route('/{record}'),'edit'=>EditConfiguration::route('/{record}/edit')];}
}
