<?php

namespace App\Helpers;

use App\Models\UserActivity;
use Illuminate\Support\Facades\Log;

class ActivityLogger
{
    public static function log($userId, string $action): void
    {
        try {
            UserActivity::create([
                'user_id' => $userId,
                'action' => $action,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Activity logging failed: '.$e->getMessage());
        }
    }
}
