<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserSetting extends Model
{
    protected $fillable = [
        'user_id',
        'email_notifications',
        'profile_public',
        'two_factor_auth',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
