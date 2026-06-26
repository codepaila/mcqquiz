<?php
namespace Quiznosis\Models;

class Announcement extends BaseModel
{
    protected static $table = 'announcements';
    protected static $fillable = [
        'title', 'body', 'audience', 'course_id', 'status',
        'pinned', 'created_by_id', 'published_at',
    ];
}
