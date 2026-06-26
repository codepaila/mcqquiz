<?php
namespace Quiznosis\Models;

class Subject extends BaseModel
{
    protected static $table = 'subjects';
    protected static $fillable = ['name','slug','order','profession_id'];
}
