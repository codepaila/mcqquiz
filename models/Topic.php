<?php
namespace Quiznosis\Models;

class Topic extends BaseModel
{
    protected static $table = 'topics';
    protected static $fillable = ['subject_id','name','slug','order'];
}
