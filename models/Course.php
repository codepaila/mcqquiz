<?php
namespace Quiznosis\Models;

class Course extends BaseModel
{
    protected static $table = 'courses';
    protected static $fillable = [
        'title','slug','description','syllabus','cover_url','is_public','profession_id','exam_type_id',
        'has_subscription','access_type','requires_approval',
    ];
}
