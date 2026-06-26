<?php
namespace Quiznosis\Models;

class ResourceReference extends BaseModel
{
    protected static $table = 'resource_references';
    protected static $fillable = [
        'resource_type','resource_id','book_id','external_id',
        'chapter','section','page','exact_excerpt','notes','created_by',
    ];
}
