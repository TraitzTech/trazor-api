<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Admin extends Model
{
    protected $fillable = [
        'user_id',
        'permissions',
    ];

    public function user() {
        $this->belongsTo(User::class);
    }

}
