<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Product;
/** Publication holds the product lock. Initialize once, then preserve staff customization. */
final class NativeActivationInformation
{
    private const PREVIOUS= <<<'BLADE'
Your domain registration or transfer is in progress. Registrations usually finish within minutes.

Transfers can take several days. We may ask for the auth code from your current registrar.

See the domain status and manage your domain on the [service page]({{ route('services.show', $service) }}).
BLADE;
    public const TEXT= <<<'BLADE'
@if ($service->product->settings()->where('key', 'domains_action')->value('value') === 'transfer')
Your domain transfer is in progress. Transfers can take several days. We may ask for the auth code from your current registrar.
@else
Your domain registration is in progress. Registrations usually finish within minutes.
@endif

See the domain status and manage your domain on the [service page]({{ route('services.show', $service) }}).
BLADE;
    public static function initialize(Product $product): void
    {
        $key='domains_activation_information_initialized';
        if($product->email_template===self::PREVIOUS){$product->email_template=self::TEXT;}
        if($product->settings()->where('key',$key)->exists()){return;}
        if($product->email_template===null){$product->email_template=self::TEXT;}
        $product->settings()->create(['key'=>$key,'value'=>'1','type'=>'string','encrypted'=>false]);
    }
}
