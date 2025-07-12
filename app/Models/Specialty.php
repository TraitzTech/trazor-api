<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Specialty extends Model
{
    protected $fillable = [
        'name',
        'category',
        'status',
        'description',
        'requirements',
        'skills',
        'partner_companies',
    ];

    public function interns()
    {
        return $this->hasMany(Intern::class);
    }

    public function supervisors()
    {
        return $this->hasMany(Supervisor::class);
    }

    protected $casts = [
        'skills' => 'array',
        'partner_companies' => 'array',
    ];
}
