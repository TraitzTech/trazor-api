<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Intern extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'specialty_id',
        'institution',
        'matric_number',
        'hort_number',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];

    public function specialty()
    {
        return $this->belongsTo(Specialty::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tasks()
    {
        return $this->belongsToMany(Task::class, 'task_intern')->withTimestamps();
    }

    // NEW relationship - with individual status tracking
    public function tasksWithStatus()
    {
        return $this->belongsToMany(Task::class, 'task_intern')
            ->withPivot(['status', 'started_at', 'completed_at', 'intern_notes'])
            ->withTimestamps();
    }

    // NEW method - Get intern's task statistics
    public function getTaskStatistics()
    {
        $tasks = $this->tasksWithStatus;
        $total = $tasks->count();

        if ($total === 0) {
            return [
                'total_tasks' => 0,
                'pending' => 0,
                'in_progress' => 0,
                'completed' => 0,
                'completion_rate' => 0,
            ];
        }

        $pending = $tasks->where('pivot.status', 'pending')->count();
        $inProgress = $tasks->where('pivot.status', 'in_progress')->count();
        $completed = $tasks->where('pivot.status', 'done')->count();

        return [
            'total_tasks' => $total,
            'pending' => $pending,
            'in_progress' => $inProgress,
            'completed' => $completed,
            'completion_rate' => round(($completed / $total) * 100, 2),
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($intern) {
            $year = Carbon::now()->format('y');

            if (! $intern->hort_number || ! $intern->specialty_id) {
                throw new \Exception('Hort number and specialty are required to generate matric number.');
            }

            $specialty = \App\Models\Specialty::find($intern->specialty_id);
            if (! $specialty) {
                throw new \Exception('Invalid specialty ID.');
            }

            $specialtyCode = strtoupper(substr($specialty->name, 0, 2)); // e.g., SO for Software

            $prefix = 'TT'.$year.'H'.$intern->hort_number.$specialtyCode;

            // Count existing interns in the same year + hort + specialty to generate next number
            $count = self::where('hort_number', $intern->hort_number)
                ->where('specialty_id', $intern->specialty_id)
                ->whereYear('created_at', now()->year)
                ->count();

            $serial = str_pad($count + 1, 3, '0', STR_PAD_LEFT); // e.g., 001

            $intern->matric_number = $prefix.$serial;
        });
    }
}
