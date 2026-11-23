<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{Installation,TemplateAssignmentIndex};
$installation=new Installation;$installation->install();
// A recorded migration with a lost index is refused.
Schema::table('domains_watch_product_maps',static fn(Blueprint $t)=>$t->dropIndex(['expected_ns_profile_id']));
$error=null;try{$installation->verify();}catch(RuntimeException $e){$error=$e->getMessage();}
expect($error!==null,'A recorded migration without its index is refused');
$nativeSuccessful=true;echo "DNS template assignment indexes install and upgrade.\n";
