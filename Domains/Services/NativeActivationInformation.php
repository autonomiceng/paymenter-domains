<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Product;
/** Publication holds the product lock. Initialize once, then preserve staff customization. */
final class NativeActivationInformation
{
    public const TEXT= <<<'BLADE'
Your domain registration or transfer is in progress. Registrations usually finish within minutes.

Transfers can take several days. We may ask you for the transfer (auth) code from your current registrar.

See the domain status and manage your domain on the [service page]({{ route('services.show', $service) }}).
BLADE;
    public static function initialize(Product $product): void
    {
        $key='domains_activation_information_initialized';
        if($product->settings()->where('key',$key)->exists()){return;}
        if($product->email_template===null){$product->email_template=self::TEXT;}
        $product->settings()->create(['key'=>$key,'value'=>'1','type'=>'string','encrypted'=>false]);
    }
}
