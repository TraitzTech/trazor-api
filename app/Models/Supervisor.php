<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supervisor extends Model
{
    protected $fillable = [
        'user_id',
        'specialty_id',
    ];

    protected function specialties()
    {
        return $this->hasMany(Specialty::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function interns()
    {
        return $this->hasMany(Intern::class);
    }
}
