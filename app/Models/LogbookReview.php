<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogbookReview extends Model
{
    protected $fillable = [
        'logbook_id',
        'reviewed_by',
        'status',
        'feedback',
    ];

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function logbook()
    {
        return $this->belongsTo(Logbook::class);
    }
}
