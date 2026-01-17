<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Kreait\Firebase\Exception\Messaging\MessagingException;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

/**
 * @tags Notifications
 */
class NotificationController extends Controller
{
    private $messaging;

    public function __construct()
    {
        try {
            $serviceAccountPath = base_path(env('GOOGLE_APPLICATION_CREDENTIALS', 'firebase-adminsdk.json'));
            $firebase = (new Factory)->withServiceAccount($serviceAccountPath);
            $this->messaging = $firebase->createMessaging();
        } catch (\Exception $e) {
            Log::error('Firebase initialization failed: '.$e->getMessage());
        }
    }

    /**
     * Update Device Token
     *
     * Register or update the Firebase Cloud Messaging (FCM) device token
     * for the authenticated user. Required for receiving push notifications.
     *
     * @bodyParam device_token string required The FCM device token from the mobile app. Example: fMRPnQlPQq-abc123...
     *
     */
    public function updateDeviceToken(Request $request)
    {
        $request->validate([
            'device_token' => 'required|string',
        ]);

        $user = AuthHelper::getUserFromBearerToken($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Only update if token is different
        if ($user->device_token !== $request->device_token) {
            $user->device_token = $request->device_token;
            $user->save();
            Log::info("Device token updated for user {$user->id}");
        }

        return response()->json(['message' => 'Device token updated successfully.']);
    }

    /**
     * Send Push Notification
     *
     * Send a push notification to a specific user via Firebase Cloud Messaging.
     * Includes duplicate prevention (same notification won't be sent twice within 2 minutes).
     *
     * @bodyParam user_id integer required The target user's ID. Example: 5
     * @bodyParam title string required Notification title. Example: New Task Assigned
     * @bodyParam body string required Notification body content. Example: You have a new task to complete
     * @bodyParam notification_type string Type of notification for categorization. Example: task_assigned
     * @bodyParam reference_id string Reference ID for deep linking. Example: 123
     *
     */
    public function sendNotification(Request $request)
    {
        Log::info('sendNotification called', $request->all());

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'title' => 'required|string',
            'body' => 'required|string',
            'notification_type' => 'nullable|string',
            'reference_id' => 'nullable|string|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid input',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::findOrFail($request->user_id);

        if (! $user->device_token) {
            Log::warning("User {$user->id} has no device token");

            return response()->json(['error' => 'User has no device token.'], 400);
        }

        // Create unique cache key for duplicate prevention
        $notificationType = $request->notification_type ?? 'general';
        $referenceId = $request->reference_id ?? 'none';
        $cacheKey = "notification_{$user->id}_{$notificationType}_{$referenceId}_".md5($request->title.$request->body);

        // Check if same notification was sent in last 2 minutes (using cache)
        if (Cache::has($cacheKey)) {
            Log::info("Duplicate notification prevented for user {$user->id}");

            return response()->json(['message' => 'Notification already sent to this user recently.']);
        }

        try {
            $result = $this->sendFirebaseNotification($user, $request->all());

            if ($result['success']) {
                // Store in cache for 2 minutes to prevent duplicates
                Cache::put($cacheKey, true, now()->addMinutes(2));

                Log::info("Notification sent successfully to user {$user->id}");

                return response()->json(['message' => 'Notification sent successfully.']);
            } else {
                Log::error("Failed to send notification to user {$user->id}: ".$result['error']);

                return response()->json(['error' => $result['error']], 500);
            }
        } catch (\Exception $e) {
            Log::error('Notification sending failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to send notification: '.$e->getMessage()], 500);
        }
    }

    /**
     * Send Bulk Notifications
     *
     * Send push notifications to multiple users at once.
     * More efficient than sending individual notifications.
     * Includes duplicate prevention and tracks delivery statistics.
     *
     * @bodyParam user_ids array required Array of user IDs to notify. Example: [1, 2, 3]
     * @bodyParam title string required Notification title. Example: System Announcement
     * @bodyParam body string required Notification body. Example: Important update for all users
     * @bodyParam notification_type string Type for categorization. Example: announcement
     * @bodyParam reference_id string Reference ID for deep linking. Example: 456
     *
     */
    public function sendBulkNotifications(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
            'title' => 'required|string',
            'body' => 'required|string',
            'notification_type' => 'nullable|string',
            'reference_id' => 'nullable|string|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid input',
                'errors' => $validator->errors(),
            ], 422);
        }

        return $this->sendBulkNotificationsInternal(
            $request->user_ids,
            $request->title,
            $request->body,
            $request->notification_type,
            $request->reference_id
        );
    }

    /**
     * Internal method for sending bulk notifications (used by other controllers)
     */
    public function sendBulkNotificationsInternal(array $userIds, string $title, string $body, ?string $notificationType = null, $referenceId = null): array
    {
        $users = User::whereIn('id', $userIds)->whereNotNull('device_token')->get();

        $notificationType = $notificationType ?? 'general';
        $referenceId = $referenceId ?? 'none';
        $contentHash = md5($title.$body);

        $results = [
            'sent' => 0,
            'failed' => 0,
            'duplicates' => 0,
            'no_token' => count($userIds) - $users->count(),
        ];

        foreach ($users as $user) {
            // Check for duplicates using cache
            $cacheKey = "notification_{$user->id}_{$notificationType}_{$referenceId}_{$contentHash}";

            if (Cache::has($cacheKey)) {
                $results['duplicates']++;

                continue;
            }

            try {
                $data = [
                    'title' => $title,
                    'body' => $body,
                    'notification_type' => $notificationType,
                    'reference_id' => $referenceId,
                ];

                $result = $this->sendFirebaseNotification($user, $data);

                if ($result['success']) {
                    // Cache to prevent duplicates
                    Cache::put($cacheKey, true, now()->addMinutes(2));
                    $results['sent']++;
                } else {
                    $results['failed']++;
                    Log::error("Failed to send to user {$user->id}: ".$result['error']);
                }
            } catch (\Exception $e) {
                $results['failed']++;
                Log::error("Exception sending to user {$user->id}: ".$e->getMessage());
            }
        }

        Log::info('Bulk notification results', $results);

        return $results;
    }

    /**
     * Send Firebase notification to a single user
     */
    private function sendFirebaseNotification(User $user, array $data)
    {
        if (! $this->messaging) {
            return ['success' => false, 'error' => 'Firebase messaging not initialized'];
        }

        try {
            $notification = Notification::create()
                ->withTitle($data['title'])
                ->withBody($data['body']);

            // Create message with proper data payload
            $messageData = [
                'notification_type' => $data['notification_type'] ?? 'general',
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK', // This helps with proper handling in Flutter
                'sound' => 'default',
            ];

            if (! empty($data['reference_id'])) {
                $messageData['reference_id'] = (string) $data['reference_id'];
            }

            $message = CloudMessage::withTarget('token', $user->device_token)
                ->withNotification($notification)
                ->withData($messageData);

            $this->messaging->send($message);

            return ['success' => true];

        } catch (MessagingException $e) {
            $errorMessage = $e->getMessage();

            // Handle invalid tokens
            if (strpos($errorMessage, 'registration-token-not-registered') !== false ||
                strpos($errorMessage, 'invalid-registration-token') !== false) {
                Log::warning("Invalid token for user {$user->id}, removing it");
                $user->device_token = null;
                $user->save();
            }

            return ['success' => false, 'error' => "Firebase error: {$errorMessage}"];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'General error: '.$e->getMessage()];
        }
    }
}
