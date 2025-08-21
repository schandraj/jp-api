<!DOCTYPE html>
<html>
<head>
    <title>Reset Password</title>
</head>
<body>
<h1>Reset Your Password</h1>
<p>Click the link below to reset your password:</p>
<a href="{{ url('api/reset-password/' . $details['token']) }}">Reset Password</a>
<p>This link will expire in {{ config('auth.passwords.users.expire') }} minutes.</p>
<p>If you didn’t request this, please ignore this email.</p>
<p>Regards,<br>{{ config('app.name') }}</p>
</body>
</html>
