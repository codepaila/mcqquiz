<?php
namespace Quiznosis\Models;

class NoteLesson extends BaseModel
{
    protected static $table = 'note_lessons';
    protected static $fillable = ['note_id','lesson_id','order'];
}
