<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Invoice,Order,Service,User};
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};

/** A short transaction records intent before native deletion can cascade a later checkout. */
final class NativeUserDeletion
{
    public const RETAINED='This customer cannot be deleted because Domains retains a domain, reservation or billing obligation for them.';
    public const PENDING='Domain checkout is unavailable because deletion was requested for this customer. Contact support to finish the deletion.';
    public static function boot(): void
    {
        if(!HookRegistration::claim(User::getEventDispatcher(),self::class)){return;}
        User::deleting(self::deleting(...));
    }
    /** Caller already holds native users, before any holding or reservation locks. */
    public static function requireOwner(int $id): void
    {
        if(!Schema::hasTable(UserDeletionSchema::TABLE)){throw new \DomainException('Domain checkout is unavailable until the Domains update is finished.');}
        if(DB::table(UserDeletionSchema::TABLE)->where('user_id',$id)->lockForUpdate()->exists()) {logger()->warning('Domains customer acquisition refused after deletion was requested.',['user_id'=>$id]);throw new \DomainException(self::PENDING);}
    }
    private static function deleting(User $user): void
    {
        if(DB::transactionLevel()>1){self::refuse($user,'Customer deletion requires the outer database transaction.');}
        $write=static function()use($user):void {
            $ids=array_values(array_unique(array_filter([$user->getRawOriginal('id'),$user->id])));sort($ids,SORT_NUMERIC);
            User::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if(!Schema::hasTable(UserDeletionSchema::TABLE)){self::refuse($user,'Customer deletion is unavailable until the Domains update is finished.');}
            // Current reads also protect a caller with an older repeatable-read snapshot.
            $invoices=Invoice::whereIn('user_id',$ids)->orderBy('id')->lockForUpdate()->get(['id']);
            $orders=Order::whereIn('user_id',$ids)->orderBy('id')->lockForUpdate()->get(['id']);
            $services=Service::where(fn($q)=>$q->whereIn('user_id',$ids)->orWhereIn('order_id',$orders->pluck('id')))->orderBy('id')->lockForUpdate()->get();
            $domains=Domain::where(fn($q)=>$q->whereIn('user_id',$ids)->orWhereIn('service_id',$services->pluck('id')))->orderBy('id')->lockForUpdate()->get(['id']);
            $operations=Operation::where(fn($q)=>$q->whereIn('domain_id',$domains->pluck('id'))->orWhereIn('service_id',$services->pluck('id'))->orWhereIn('invoice_id',$invoices->pluck('id')))->orderBy('id')->lockForUpdate()->get(['id']);
            if($domains->isNotEmpty()||$operations->isNotEmpty()||$services->contains(fn($s)=>NativeIdentity::routed($s))){self::refuse($user,self::RETAINED);}
            foreach($ids as$id){
                if(!DB::table(UserDeletionSchema::TABLE)->where('user_id',$id)->lockForUpdate()->exists()){
                    DB::table(UserDeletionSchema::TABLE)->insert(['user_id'=>$id,'created_at'=>now()]);
                }
            }
        };
        if(DB::transactionLevel()===0){DB::transaction($write);}else{$write();}
    }
    private static function refuse(User $record,string $message): never
    {
        logger()->warning('Domains customer deletion refused.',['user_id'=>$record->getRawOriginal('id')]);
        if(class_exists(\Livewire\Livewire::class)&&($page=\Livewire\Livewire::current()) instanceof \App\Admin\Resources\UserResource\Pages\EditUser
            &&$page->getRecord()->is($record)&&$page->getMountedAction()?->getName()==='delete'){
            \Filament\Notifications\Notification::make()->danger()->title('Customer could not be deleted')->body($message)->send();
            throw (new \Filament\Support\Exceptions\Cancel)->rollBackDatabaseTransaction();
        }
        throw new \DomainException($message);
    }
}
