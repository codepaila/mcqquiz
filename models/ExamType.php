<?php
namespace Quiznosis\Models;

class ExamType extends BaseModel
{
    protected static $table = 'exam_types';
    protected static $fillable = ['name', 'slug', 'description', 'profession_id', 'order'];
}
