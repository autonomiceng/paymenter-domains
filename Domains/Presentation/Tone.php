<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** The five status tones. A page never picks one: a State gives it for its reader, and the mark comes from the tone
 * (a skin draws it from data-beco-tone). Adding a tone or mark needs Ilya. */
enum Tone: string
{
    /** Solid green, check: done or healthy. */
    case Ok = 'ok';
    /** Hollow grey, minus: stopped on purpose, or nothing to do. */
    case Off = 'off';
    /** Hollow cyan, node: we or the registrar are working on it. */
    case Progress = 'prog';
    /** Solid amber, "!": this reader must do something. */
    case Attention = 'warn';
    /** Solid red, x: it broke. */
    case Failed = 'err';
}
