<?php
namespace Quiznosis\Models;

class Profession extends BaseModel
{
    protected static $table = 'professions';
    protected static $fillable = ['name','slug','description','order','image'];
}
