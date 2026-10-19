<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
/** Mirror the kernel binding: Livewire's legitimate skip callback reads the container request. */
function nativeNormalizeInput(\Illuminate\Http\Request $request): \Illuminate\Http\Request
{
    $previous=app('request');app()->instance('request',$request);
    try {
        (new \Illuminate\Foundation\Http\Middleware\TrimStrings)->handle($request,fn($v)=>$v);
        (new \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull)->handle($request,fn($v)=>$v);
        return $request;
    } finally { app()->instance('request',$previous); }
}
