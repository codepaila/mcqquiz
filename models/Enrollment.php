<?php
namespace Quiznosis\Models;

class Enrollment extends BaseModel
{
    protected static $table = 'enrollments';
    protected static $jsonColumns = ['metadata'];
    protected static $fillable = [
        'user_id','course_id','status','request_note','admin_note','requested_at',
        'approved_at','rejected_at','starts_at','expires_at','requires_approval',
        'access_type','approval_message','purchase_id','course_subscription_plan_id',
        'is_upgrade_request','is_renewal_request','request_type','progress',
        'last_accessed','metadata',
    ];
}
