<?php
namespace Quiznosis\Models;

class Book extends BaseModel
{
    protected static $table = 'books';
    protected static $jsonColumns = ['authors'];
    protected static $fillable = [
        'title','authors','isbn','publisher','published_at','language',
        'edition','cover_url','description','subject_id',
    ];
}
