<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic identities and readers only.
declare(strict_types=1);
$nativeServerSettingsCase='editor';$nativeServerSettingsRegistrar='namesilo';
require __DIR__.'/Support/native-server-settings.php';
// Laravel can render an exception with exit zero; arm completion for this final witness too.
$nativeSuccessful=false;
same(1,App\Models\Server::where('extension','Domains')->where('id','<',$server->id)->count(),
    'Successful native submission creates a second Domains server after an existing one');
$nativeSuccessful=true;
