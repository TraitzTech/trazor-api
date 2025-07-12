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

    public function specialty()
    {
        return $this->belongsTo(Specialty::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
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
