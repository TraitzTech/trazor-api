<?php

// app/Helpers/AuthHelper.php

namespace App\Helpers;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class AuthHelper
{
    public static function getUserFromBearerToken(Request $request)
    {
        $token = $request->bearerToken();

        if (! $token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken || ! $accessToken->tokenable) {
            return null;
        }

        return $accessToken->tokenable;
    }
}
