<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $fillable = [
        'title', 'description', 'due_date', 'status', 'assigned_by', 'specialty_id',
    ];

    public function interns()
    {
        return $this->belongsToMany(Intern::class, 'task_intern')->withTimestamps();
    }

    // NEW relationship - with individual tracking data
    public function internsWithStatus()
    {
        return $this->belongsToMany(Intern::class, 'task_intern')
            ->withPivot(['status', 'started_at', 'completed_at', 'intern_notes'])
            ->withTimestamps();
    }

    // NEW method - Get task progress summary
    public function getProgressSummary()
    {
        $interns = $this->internsWithStatus;
        $total = $interns->count();

        if ($total === 0) {
            return [
                'total' => 0,
                'pending' => 0,
                'in_progress' => 0,
                'done' => 0,
                'completion_percentage' => 0,
            ];
        }

        $pending = $interns->where('pivot.status', 'pending')->count();
        $inProgress = $interns->where('pivot.status', 'in_progress')->count();
        $done = $interns->where('pivot.status', 'done')->count();

        return [
            'total' => $total,
            'pending' => $pending,
            'in_progress' => $inProgress,
            'done' => $done,
            'completion_percentage' => round(($done / $total) * 100, 2),
        ];
    }

    public function specialty()
    {
        return $this->belongsTo(Specialty::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }
}
