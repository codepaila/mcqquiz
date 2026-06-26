<?php
namespace Quiznosis\Models;

class QuizOption extends BaseModel
{
    protected static $table = 'quiz_options';
    protected static $fillable = ['quiz_id','text','is_correct','order'];
}
