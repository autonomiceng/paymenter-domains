<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-discovery.php';
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
use Paymenter\Extensions\Servers\Domains\Services\{NativeUserDeletion,UserDeletionSchema};
$f=discoveryFixture();extract($f);$sweep();same(1,WatchName::count(),'Working discovery prerequisite');NativeUserDeletion::boot();
$fail=true;User::deleting(static function(User $u)use($owner,&$fail):void {if($fail&&$u->id===$owner->id){throw new RuntimeException('Synthetic late deletion failure');}});
try{$owner->fresh()->delete();expect(false,'Later native listener must fail');}catch(RuntimeException $e){same('Synthetic late deletion failure',$e->getMessage(),'Real native event failure');}
expect(User::whereKey($owner->id)->exists(),'Failed native deletion retains owner');
expect(DB::table(UserDeletionSchema::TABLE)->where('user_id',$owner->id)->exists(),'Deletion intent committed before later native failure');
same(0,WatchName::count(),'Requested watch deletion removes personal history');
$sweep();same(0,WatchName::count(),'Discovery cannot recreate a pending deleted customer watch');
$fail=false;$owner->fresh()->delete();expect(!User::whereKey($owner->id)->exists(),'Retry finishes native deletion');
echo "Later native failure retains the deletion fence and discovery cannot restore erased watch data.\n";
$nativeSuccessful=true;
