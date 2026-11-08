<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\User;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Route};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts,HookRegistration};
/** Public render hook on the native customer editor; all writes stay extension-owned. */
final class ComplimentaryAccountSetting
{
    public static function boot():void
    {
        if(!HookRegistration::claim(FilamentView::getFacadeRoot(),self::class)){return;}
        Route::middleware(['web','auth'])->post('/domains/staff/customers/{user}/complimentary',[self::class,'save'])->whereNumber('user')->name('domains.staff.complimentary-account');
        $scope=\App\Admin\Resources\UserResource\Pages\EditUser::class;
        FilamentView::registerRenderHook(PanelsRenderHook::PAGE_START,static function()use($scope):string {
            $page=\Livewire\Livewire::current();
            return $page instanceof $scope?self::render($page->getRecord()):'';
        },scopes:[$scope]);
    }
    public static function allowed():bool {return Auth::user() instanceof User&&(bool)User::with('role')->find(Auth::id())?->hasPermission('admin.domains.fund');}
    public static function render(User $user):string
    {
        if(!self::allowed()){return '';}
        return view('domains::admin.complimentary-account',['customer'=>$user,'setting'=>ComplimentaryAccounts::current((int)$user->id)])->render();
    }
    public function save(Request $request,int $user)
    {
        abort_unless(self::allowed(),403);
        $data=$request->validate(['enabled'=>'nullable|in:1','reason'=>'required|string|max:500']);
        User::findOrFail($user);
        ComplimentaryAccounts::set($user,$request->user(),isset($data['enabled']),$data['reason'],app(Clock::class));
        return redirect(\App\Admin\Resources\UserResource::getUrl('edit',['record'=>$user]));
    }
}
