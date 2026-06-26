<?php
namespace Quiznosis\Models;

class QuizSetItem extends BaseModel
{
    protected static $table = 'quiz_set_items';
    protected static $fillable = ['quiz_set_id','quiz_id','order'];
}
