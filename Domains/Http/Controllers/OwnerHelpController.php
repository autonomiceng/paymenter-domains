<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Http\Controllers;
use Paymenter\Extensions\Servers\Domains\Services\OwnerHelp;
final class OwnerHelpController
{
    public function show()
    {
        return view('domains::help',['html'=>OwnerHelp::html(),'anchors'=>OwnerHelp::ANCHORS]);
    }
}
