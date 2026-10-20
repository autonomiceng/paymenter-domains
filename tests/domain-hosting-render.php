<?php
// Native hosting view with the scoped composer. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/domain-portal-test.php';
$out=$argv[1];DB::table('domains_watch_names')->where('id',$id)->update(['state'=>'active']);Auth::setUser($owner);
require_once $fixture.'/app/Classes/helpers.php';app('view')->getFinder()->setPaths([$fixture.'/themes/default/views',$fixture.'/resources/views']);app('view')->getFinder()->flush();\Qirolab\Theme\Theme::set('default','default');
$html=view('services.show',['service'=>$service,'fields'=>[],'buttons'=>[],'views'=>[],'showCancel'=>false,'editLabel'=>false,'currentView'=>null,'extensionView'=>''])->render();expect(str_contains($html,url('/domains/checks/watch/'.$id)),'Actual native hosting page includes owned domain link');
file_put_contents($out.'/hosting.html','<html><head></head><body>'.$html.'</body></html>');$strings=json_decode(file_get_contents($out.'/strings.json'),true);$strings['hosting']=trim(preg_replace('/\s+/u',' ',strip_tags($html)));file_put_contents($out.'/strings.json',json_encode($strings,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));echo "Native hosting view rendered.\n";
