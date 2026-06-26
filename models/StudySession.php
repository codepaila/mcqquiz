<?php
namespace Quiznosis\Models;

class StudySession extends BaseModel
{
    protected static $table = 'study_sessions';
    protected static $jsonColumns = ['tags'];
    protected static $fillable = [
        'user_id','subject_id','topic_id','course_id','quiz_set_id',
        'title','description','start_time','end_time','duration_min',
        'questions_seen','correct','accuracy','device_type','session_type',
        'tags','mood','focus_level','notes',
    ];
}
