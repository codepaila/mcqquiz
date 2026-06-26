<?php
namespace Quiznosis\Models;

class Leaderboard extends BaseModel
{
    protected static $table = 'leaderboards';
    protected static $fillable = [
        'name','slug','type','scope','subject_id','course_id',
        'start_date','end_date','is_active','score_formula','min_questions','min_accuracy',
    ];
}
