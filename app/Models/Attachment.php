<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $fillable = [
        'task_id',
        'uploaded_by',
        'path',
        'original_name',
        'file_size',
        'mime_type',
        'description',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    // Fixed relationship - matches the column name in migration
    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
