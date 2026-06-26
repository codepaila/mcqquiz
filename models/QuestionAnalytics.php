<?php
namespace Quiznosis\Models;

class QuestionAnalytics extends BaseModel
{
    protected static $table = 'question_analytics';
    protected static $jsonColumns = ['option_stats'];
    protected static $fillable = [
        'quiz_id','total_attempts','correct_attempts','average_time_sec',
        'p_value','discrimination','option_stats','first_used','last_used','last_updated',
    ];
}
