<?php
namespace Quiznosis\Models;

class UserNoteBookmark extends BaseModel
{
    protected static $table = 'user_note_bookmarks';
    protected static $fillable = ['user_id','note_id'];
}
