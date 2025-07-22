<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Logbook extends Model
{
    protected $fillable = [
        'intern_id', 'date', 'title', 'content', 'hours_worked', 'tasks_completed',
        'challenges', 'learnings', 'next_day_plans', 'status', 'submitted_at', 'reviewed_at', 'reviewed_by',
    ];

    protected $casts = [
        'tasks_completed' => 'array',
        'date' => 'date',
    ];

    public function intern()
    {
        return $this->belongsTo(Intern::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reviews()
    {
        return $this->hasMany(LogbookReview::class);
    }
}
