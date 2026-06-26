<?php
namespace Quiznosis\Models;

class QuizBookmark extends BaseModel
{
    protected static $table    = 'quiz_bookmarks';
    protected static $fillable = ['user_id', 'quiz_id'];
}
