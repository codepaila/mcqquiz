<?php
namespace Quiznosis\Models;

class QuizSetStats extends BaseModel
{
    protected static $table = 'quiz_set_stats';
    protected static $jsonColumns = ['section_breakdown'];
    protected static $fillable = [
        'quiz_set_id','total_attempts','unique_users','average_score',
        'average_percent','median_percent','avg_time_sec','section_breakdown','last_updated',
    ];
}
