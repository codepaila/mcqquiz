<?php
namespace Quiznosis\Models;

class Note extends BaseModel
{
    protected static $table = 'notes';
    protected static $jsonColumns = ['tags'];
    protected static $fillable = ['title','slug','subject_id','content','topic_id','tags'];
}
