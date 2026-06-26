<?php
namespace Quiznosis\Models;

class CourseSubscriptionPlan extends BaseModel
{
    protected static $table = 'course_subscription_plans';
    protected static $jsonColumns = ['features'];
    protected static $fillable = [
        'course_id','name','description','duration_days','price','currency',
        'original_price','is_popular','is_active','order','features',
    ];
}
