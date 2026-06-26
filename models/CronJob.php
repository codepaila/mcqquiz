<?php
namespace Quiznosis\Models;

class CronJob extends BaseModel
{
    protected static $table = 'cron_jobs';
    protected static $jsonColumns = ['metadata'];
    protected static $fillable = [
        'job_name','status','started_at','completed_at','duration_ms',
        'metadata','error','triggered_by_id','scheduled_at','next_run_at',
    ];
}
