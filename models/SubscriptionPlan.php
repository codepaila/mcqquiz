<?php
namespace Quiznosis\Models;

class SubscriptionPlan extends BaseModel
{
    protected static $table = 'subscription_plans';
    protected static $fillable = ['name','slug','tier','duration_days','price','is_active'];
}
