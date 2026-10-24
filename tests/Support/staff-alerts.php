<?php
// Synthetic native inbox assertions. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\Notification as NativeNotification;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\{DB,Queue,Schema};
function staffAlertFixture(): void
{
    $columns=Schema::getColumnListing('notifications');sort($columns);
    $expected=['id','user_id','title','body','url','read_at','show_in_app','show_as_push','created_at','updated_at'];sort($expected);
    same($expected,$columns,'Unmodified core migrations provide the native inbox, not a polymorphic Laravel table');
    foreach(Schema::getColumns('notifications') as $column){
        if(in_array($column['name'],['user_id','title','body'],true)){expect(!$column['nullable'],'Native required alert fields');}
    }
    Queue::fake();
}
function staffAlerts(string $title,string $body,string $url,array $staff): void
{
    $rows=NativeNotification::where('title',$title)->orderBy('user_id')->get();
    $ids=array_map(static fn($user)=>(int)$user->id,$staff);sort($ids);
    same($ids,$rows->pluck('user_id')->map(static fn($id)=>(int)$id)->all(),'One native alert per eligible staff user, with no denied or customer recipients');
    foreach($rows as $row){same($body,$row->body,'Existing alert body retained');same($url,$row->url,'Relevant native admin URL');
        expect((bool)$row->show_in_app && (bool)$row->show_as_push && $row->read_at===null,'Unread native notification defaults');}
    Queue::assertNotPushed(\Illuminate\Notifications\SendQueuedNotifications::class,static fn($job)=>$job->notification instanceof DatabaseNotification);
    same(0,DB::table('jobs')->count(),'No database job queued');
    same(0,DB::table('failed_jobs')->count(),'No failed job recorded');
}
