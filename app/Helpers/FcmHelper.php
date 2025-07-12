<?php

namespace App\Helpers;

use Google\Auth\OAuth2;
use Illuminate\Support\Facades\Http;

class FcmHelper
{
    public static function sendNotification($deviceToken, $title, $body, $data = [])
    {
        $credentialsPath = base_path(env('GOOGLE_APPLICATION_CREDENTIALS'));
        $projectId = env('FIREBASE_PROJECT_ID');

        // Get OAuth2 access token
        $scope = 'https://www.googleapis.com/auth/firebase.messaging';
        $jsonKey = json_decode(file_get_contents($credentialsPath), true);

        $oauth = new OAuth2([
            'audience' => 'https://oauth2.googleapis.com/token',
            'issuer' => $jsonKey['client_email'],
            'signingAlgorithm' => 'RS256',
            'signingKey' => $jsonKey['private_key'],
            'tokenCredentialUri' => 'https://oauth2.googleapis.com/token',
            'scope' => $scope,
        ]);
        $token = $oauth->fetchAuthToken();

        // Build HTTP v1 payload
        $payload = [
            'message' => [
                'token' => $deviceToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $data,
            ]
        ];

        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $response = Http::withToken($token['access_token'])
            ->post($url, $payload);

        return $response->json();
    }
}
