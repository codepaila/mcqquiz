<?php
namespace Quiznosis\Models;

class UserNoteProgress extends BaseModel
{
    protected static $table = 'user_note_progress';
    protected static $fillable = [
        'user_id','note_id','lesson_id','status','time_spent','last_viewed','completed_at',
    ];
}
