<?php
namespace Quiznosis\Models;

class ExternalSource extends BaseModel
{
    protected static $table = 'external_sources';
    protected static $jsonColumns = ['authors'];
    protected static $fillable = [
        'title','url','site_name','authors','published_at','description',
    ];
}
