<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting,CatalogueActivation};

/** Rows that catalogue reads and failed activations may alter. */
function retainedOfferObligations(): string
{
    $rows=[];
    foreach(['domains_domains','domains_operations','services','orders','invoices','invoice_items','invoice_transactions',
        'credits','products','plans','prices','users','roles'] as $table){
        $rows[$table]=DB::table($table)->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all();
    }
    $rows['accounts']=DB::table('domains_accounts')->orderBy('id')->get(['id','server_id','registrar_key','account_fingerprint','stable_identity'])->all();
    $rows['offers']=DB::table('domains_tlds')->orderBy('id')->get(['id','server_id','account_fingerprint','tld','catalogue_intent','catalogue_retained_annual_retail'])->all();
    $rows['sellers']=DB::table('domains_sales_policies')->orderBy('id')->get()->all();
    return serialize($rows);
}

/** Test-only exact database snapshot, independent of retired upgrade comparison policy. */
function retainedOfferSnapshot(bool $includeVerification=true): string
{
    $tables=Illuminate\Support\Facades\Schema::getTableListing(null,false);sort($tables);$rows=[];
    foreach($tables as $table){
        $query=DB::table($table);
        // Only successful upgrades may replace this derived record; refusals still compare every byte.
        if(!$includeVerification && $table==='settings'){$query->whereNot(static fn($q)=>$q->whereNull('settingable_type')->whereNull('settingable_id')->where('key',Paymenter\Extensions\Servers\Domains\Services\Installation::VERIFIED_KEY));}
        $values=$query->get()->map(static fn($row)=>(array)$row)->all();
        usort($values,static fn($a,$b)=>json_encode($a)<=>json_encode($b));$rows[$table]=$values;
    }
    return serialize($rows);
}
