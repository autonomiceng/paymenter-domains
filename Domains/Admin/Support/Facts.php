<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
/** Shared builders for staff fact rows. Arguments always come in the order value, sub, url, tone. */
final class Facts
{
    /** One row. An empty value or sub is null: a null value shows Missing with the sub as its cause. */
    public static function row(string|array|null $value, ?string $sub=null, ?string $url=null, ?string $tone=null, ?string $help=null, bool $demo=false): array
    { return ['value'=>$value===''?null:$value,'sub'=>$sub===''?null:$sub,'url'=>$url,'tone'=>$tone,'help'=>$help,'demo'=>$demo]; }
    /** Links to pages the current panel may not register resolve to no link. */
    public static function url(\Closure $make): ?string { try { return $make(); } catch (\Throwable) { return null; } }
    /** The invoice's edit page, when this staff member may edit it. */
    public static function invoiceUrl(int $id): ?string
    {
        $invoice=\App\Models\Invoice::find($id);
        return $invoice&&\App\Admin\Resources\InvoiceResource::canEdit($invoice)?self::url(fn()=>\App\Admin\Resources\InvoiceResource::getUrl('edit',['record'=>$id])):null;
    }
    /** The staff invoice name, as the staff invoice list shows it: its number (INV-26) once it has one, else "Proforma #" and its ID. */
    public static function invoiceName(int $id): string
    {
        $number=\App\Models\Invoice::whereKey($id)->value('number');
        return is_string($number)&&$number!==''?$number:'Proforma #'.$id;
    }
    /** A saved true or false in words. Anything else is null, which shows Missing. */
    public static function flag(mixed $value, string $on='On', string $off='Off'): ?string { return is_bool($value)?($value?$on:$off):null; }
}
