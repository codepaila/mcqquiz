<?php
namespace Quiznosis\Models;

class TextLesson extends BaseModel
{
    protected static $table = 'text_lessons';
    protected static $jsonColumns = ['tags'];
    protected static $fillable = [
        'title','slug','content','subject_id','topic_id','tags','status',
    ];
}
