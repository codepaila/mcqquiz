<?php
namespace Quiznosis\Models;

class CourseMaterial extends BaseModel
{
    protected static $table = 'course_materials';
    protected static $fillable = ['course_id','type','slug','quiz_set_id','note_id','order'];
}
