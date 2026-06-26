<?php
namespace Quiznosis\Models;

class QuestionReport extends BaseModel
{
    protected static $table = 'question_reports';
    protected static $jsonColumns = ['metadata'];
    protected static $fillable = [
        'user_id','quiz_id','reason','status','description','selected_option_id',
        'suggested_text','reference_text','reviewed_by_id','review_notes',
        'resolution_note','ip_address','user_agent','metadata',
        'reported_at','reviewed_at','resolved_at',
    ];
}
