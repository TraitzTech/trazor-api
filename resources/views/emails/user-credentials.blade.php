<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Welcome to {{ config('app.name') }}</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f4f7fa;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 30px auto;
            background: #ffffff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.06);
        }
        .header {
            background: #0d6efd;
            color: #ffffff;
            padding: 20px 30px;
            text-align: center;
        }
        .content {
            padding: 30px;
            color: #333;
        }
        .footer {
            background: #f0f0f0;
            padding: 20px 30px;
            font-size: 14px;
            text-align: center;
            color: #777;
        }
        .credentials {
            background: #f9f9f9;
            border-left: 4px solid #0d6efd;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        .btn {
            display: inline-block;
            padding: 10px 25px;
            background: #0d6efd;
            color: #fff;
            text-decoration: none;
            border-radius: 30px;
            margin-top: 20px;
            font-weight: bold;
        }
        .highlight {
            font-weight: bold;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Welcome, {{ $user->name }} 👋</h1>
        <p>You’ve been successfully onboarded to {{ config('app.name') }}!</p>
    </div>

    <div class="content">
        <p>We're excited to have you join as a(an) <span class="highlight">{{ $role }}</span>. Your account has been created, and here are your login credentials:</p>

        <div class="credentials">
            <p><strong>Email:</strong> {{ $user->email }}</p>
            <p><strong>Password:</strong> {{ $password }}</p>
        </div>

        @if(!empty($additionalData))
            <p>Additional Info:</p>
            <ul>
                @foreach ($additionalData as $key => $value)
                    <li><strong>{{ ucfirst($key) }}:</strong> {{ $value }}</li>
                @endforeach
            </ul>
        @endif

        <p>Please make sure to <strong>change your password</strong> after your first login into the app for security purposes.</p>

        {{-- <a href="{{ config('app.url') }}" class="btn">Open {{ config('app.name') }}</a> --}}

        <p>If you haven't yet, kindly download or access the <strong>{{ config('app.name') }}</strong> app and log in using the credentials above.</p>
    </div>

    <div class="footer">
        &copy; {{ now()->year }} {{ config('app.name') }}. All rights reserved.<br>
        Need help? Reach out to our support team anytime.
    </div>
</div>
</body>
</html>
