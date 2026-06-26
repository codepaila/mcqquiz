<?php
namespace Quiznosis\Models;

class Account extends BaseModel
{
    protected static $table = 'accounts';
    protected static $fillable = [
        'user_id','type','provider','provider_account_id',
        'refresh_token','access_token','expires_at','token_type',
        'scope','id_token','session_state',
    ];
}
