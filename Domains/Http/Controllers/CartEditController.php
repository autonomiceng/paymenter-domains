<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Http\Controllers;
use App\Classes\Cart as NativeCart;
use App\Models\CartItem;
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{NativeCatalogue,NativePresenter};
final readonly class CartEditController
{
    public function __construct(private NativeCatalogue $catalogue,private NativePresenter $presenter) {}
    private function item(Request $r,int $id): CartItem
    {
        $cart=NativeCart::get();abort_unless($r->user()&&(int)$cart->user_id===(int)$r->user()->id,404);
        $item=$cart->items()->whereKey($id)->firstOrFail();abort_unless($this->presenter->checkout($r->user(),$item),404);return $item;
    }
    public function edit(Request $r,int $item)
    {
        $line=$this->item($r,$item);return view('domains::cart-edit',['item'=>$line,'domain'=>$this->presenter->checkout($r->user(),$line),
            'key'=>FormKeys::issue($r,'cart_edit:'.$item)]);
    }
    public function update(Request $r,int $item)
    {
        $line=$this->item($r,$item);FormKeys::verify($r,'cart_edit:'.$item);FormKeys::consume($r,'cart_edit:'.$item);
        try{
            $name=$r->input('domain');$term=$r->input('term');if(!is_string($name)||strlen($name)>253||!is_string($term)||!preg_match('/^(?:[1-9]|10)$/D',$term)){throw new \DomainException;}
            $plan=$line->product->plans()->where('billing_period',(int)$term)->sole();$this->catalogue->mapping($line->product,$plan);
            NativeCart::add($line->product,$plan,[],['domain'=>$name],1,$line->id);
        }catch(\Throwable $e){\Illuminate\Support\Facades\Log::warning('Domains cart edit refused.',['exception_class'=>$e::class]);return redirect()->route('domains.cart.edit',['item'=>$item])->with('domains_notice','We couldn’t update this domain. Check the name and retry. If the price changed, remove this line from your cart and search again.');}
        return redirect()->route('cart');
    }
}
