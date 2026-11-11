<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/default-rule.php';
require_once __DIR__.'/Support/render-artifacts.php';
require_once __DIR__.'/Support/catalogue-columns.php';

spl_autoload_register(static function (string $name): void {
    $prefix = 'Paymenter\\Extensions\\Servers\\Domains\\';
    if (str_starts_with($name, $prefix)) {
        require dirname(__DIR__).'/Domains/'.str_replace('\\', '/', substr($name, strlen($prefix))).'.php';
    }
});

function expect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function same(mixed $expected, mixed $actual, string $message): void
{
    expect($expected === $actual, $message);
}

/** Synthetic fixtures explicitly accept processing without installing a provider runtime. */
function syntheticActivation(): Closure
{
    return static fn () => null;
}

/** Arrange the same synthetic intent after removal of the old TLD flags. */
function tldColumns(array $values,?\Paymenter\Extensions\Servers\Domains\Models\Tld $row=null): array
{
    if(\Illuminate\Support\Facades\Schema::hasColumn('domains_tlds','enabled')){return $values;}
    $keys=['enabled','register_enabled','renew_enabled','transfer_enabled','min_years','max_years'];
    if(!array_intersect(array_keys($values),$keys)){return $values;}
    $intent=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($row?->catalogue_intent);
    $stops=$intent['stops']??[];
    if(isset($values['enabled'])){$stops=$values['enabled']?[]:['register','renew','transfer'];}
    foreach(['register','renew','transfer'] as $action){
        if(array_key_exists($action.'_enabled',$values)){
            $stops=array_values(array_diff($stops,[$action]));if(!$values[$action.'_enabled']){$stops[]=$action;}
        }
    }
    $intent['stops']=$stops;
    if(isset($values['max_years'])){$intent['longest']=(int)$values['max_years'];}
    return [...array_diff_key($values,array_flip($keys)),'catalogue_intent'=>$values['catalogue_intent']??json_encode($intent,JSON_THROW_ON_ERROR)];
}
