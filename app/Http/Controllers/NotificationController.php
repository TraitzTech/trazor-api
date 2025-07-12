<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\User;
use Illuminate\Http\Request;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class NotificationController extends Controller
{
    public function updateDeviceToken(Request $request)
    {
        $request->validate([
            'device_token' => 'required|string',
        ]);

        $user = AuthHelper::getUserFromBearerToken($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $user->device_token = $request->device_token;
        $user->save();

        return response()->json(['message' => 'Device token updated successfully.']);
    }

    public function sendNotification(Request $request)
    {
        \Log::info('sendNotification called', $request->all());

        $validator = \Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'title' => 'required|string',
            'body' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid input',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::findOrFail($request->user_id);

        if (! $user->device_token) {
            return response()->json(['error' => 'User has no device token.'], 400);
        }

        $serviceAccountPath = storage_path('app/firebase/serviceAccountKey.json');

        $firebase = (new Factory)->withServiceAccount($serviceAccountPath);
        $messaging = $firebase->createMessaging();

        $notification = Notification::create($request->title, $request->body);
        $message = CloudMessage::withTarget('token', $user->device_token)
            ->withNotification($notification);

        try {
            $messaging->send($message);

            return response()->json(['message' => 'Notification sent successfully.']);
        } catch (\Kreait\Firebase\Exception\Messaging\MessagingException $e) {
            return response()->json(['error' => 'Messaging error: '.$e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error: '.$e->getMessage()], 500);
        }
    }
}
