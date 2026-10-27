<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real owned list ordering across pages.
declare(strict_types=1);
(function () use ($newTransfer, $controller, $request): void {
    $owner=commerceUser();$foreign=commerceUser();
    $base=$newTransfer(placeholderComFixtureName('sort-base-placeholder.com'),0,false,false)->domain;
    $clone=static function(string $table,int $id,array $changes=[]):int {
        $row=(array)\Illuminate\Support\Facades\DB::table($table)->where('id',$id)->first();unset($row['id']);
        return (int)\Illuminate\Support\Facades\DB::table($table)->insertGetId([...$row,...$changes]);
    };
    $order=$clone('orders',(int)$base->service->order_id,['user_id'=>$owner->id]);
    $name=static fn(int $i)=>sprintf('sort-%02d.example.com',$i);
    // Different insertion, name and expiry orders expose all four comparators.
    foreach ([18,1,27,5,14,22,8,30,3,25,12,32,7,20,10,28,16,4,24,13,31,2,19,9,26,15,29,6,23,11,21,17] as $i) {
        if ($i>28) {
            // Retained paused watches are visible without a hosting source or registry expiry.
            \Illuminate\Support\Facades\DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Models\WatchName::create([
                'user_id'=>$owner->id,'canonical_name'=>$name($i),'psl_revision'=>str_repeat('a',64),'state'=>'paused','notice_preferences'=>[]]));
            continue;
        }
        $service=$clone('services',(int)$base->service_id,['user_id'=>$owner->id,'order_id'=>$order]);
        $expiry=match($i%3){1=>'2035-01-01 12:00:00',2=>'2036-01-01 01:00:00',0=>null};
        $clone('domains_domains',(int)$base->id,['user_id'=>$owner->id,'service_id'=>$service,'domain'=>$name($i),
            'reservation_name'=>$name($i),'sld'=>sprintf('sort-%02d',$i),'tld'=>'example.com','provider_expires_at'=>$expiry]);
    }
    foreach (['ledger','service','order'] as $boundary) {
        $badOrder=$clone('orders',$order,['user_id'=>$boundary==='order'?$foreign->id:$owner->id]);
        $service=$clone('services',(int)$base->service_id,['user_id'=>$boundary==='service'?$foreign->id:$owner->id,'order_id'=>$badOrder]);
        $bad='foreign-'.$boundary.'.example.com';
        $clone('domains_domains',(int)$base->id,['user_id'=>$boundary==='ledger'?$foreign->id:$owner->id,'service_id'=>$service,
            'domain'=>$bad,'reservation_name'=>$bad,'sld'=>'foreign-'.$boundary,'tld'=>'example.com']);
    }
    // Literal fixture positions are independent of the controller's comparison and slicing code.
    $expected=[
        'expiry'=>[1,4,7,10,13,16,19,22,25,28,2,5,8,11,14,17,20,23,26,3,6,9,12,15,18,21,24,27,29,30,31,32],
        '-expiry'=>[2,5,8,11,14,17,20,23,26,1,4,7,10,13,16,19,22,25,28,3,6,9,12,15,18,21,24,27,29,30,31,32],
        'name'=>[1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32],
        '-name'=>[32,31,30,29,28,27,26,25,24,23,22,21,20,19,18,17,16,15,14,13,12,11,10,9,8,7,6,5,4,3,2,1],
    ];
    $read=static function(array $query)use($controller,$request,$owner):array {
        $r=$request($owner);$r->query->add($query);$view=$controller->index($r);$data=$view->getData();$page=$data['domains'];
        $names=array_map(fn($row)=>$row->name,$page->items());$dom=storefrontDom($view->render());$rendered=[];
        foreach ($dom->query('//tbody/tr/td[1]/a') as $link) { $rendered[]=trim($link->textContent); }
        same($names,$rendered,'Real Blade preserves controller row order');
        same(32,$page->total(),'All and only owned managed and watch rows are counted');
        same(25,$page->perPage(),'Owned page size stays 25');same(32,$data['filterCounts'][''],'Owner filter counts exclude foreign ledger/service/order rows');
        return [$names,$data['sort'],$data['filter'],$page->currentPage()];
    };
    foreach ($expected as $sort=>$positions) {
        $all=[];
        foreach (array_chunk(array_map($name,$positions),25) as $i=>$names) {
            [$actual,$selected,,$page]=$read(['sort'=>$sort,'page'=>$i+1]);
            same($names,$actual,'Exact owned '.$sort.' page '.($i+1));same($sort,$selected,'Requested sort is retained');
            same($i+1,$page,'Requested page is retained');$all=[...$all,...$actual];
        }
        same(array_map($name,$positions),$all,'Both pages have no omissions or duplicates: '.$sort);
        same(32,count(array_unique($all)),'Each mixed owned row appears once: '.$sort);
    }
    foreach ([1,2] as $page) {
        $default=$read(['page'=>$page]);
        same('expiry',$default[1],'Default sort is expiry');
        same(array_chunk(array_map($name,$expected['expiry']),25)[$page-1],$default[0],'Default expiry has the exact page order');
        same($default,$read(['sort'=>'bogus','page'=>$page]),'Bogus sort falls back to default expiry on page '.$page);
        same($read(['sort'=>'-name','page'=>$page]),$read(['sort'=>'-name','status'=>'bogus','page'=>$page]),'Bogus status is unfiltered on page '.$page);
    }
})();
