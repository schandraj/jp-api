<!DOCTYPE html>
<html>
<head>
    <title>Reset Password</title>
</head>
<body>
<p>Hi {{$details['fullname']}},</p>
<p>Kami telah menerima permintaan untuk mereset kata sandi yang terkait dengan akun <b>Jadipraktisi</b> Anda.</p>
<p>Untuk melanjutkan proses reset, silakan klik tautan di bawah ini:</p>
<a href="{{ url('api/reset-password/' . $details['token']) . '/' . $details['to'] }}">[Link Reset Password]</a>
<p><i>Tautan ini hanya berlaku selama {{ config('auth.passwords.users.expire') }} menit demi menjaga keamanan akun Anda.</i></p>

<p>Jika Anda tidak merasa melakukan permintaan ini, Anda bisa abaikan email ini. Akun Anda akan tetap aman.</p>

<p>Jika Anda mengalami kendala atau membutuhkan bantuan lebih lanjut, jangan ragu untuk menghubungi tim dukungan kami di cs@jadipraktisi.com.</p>

<p>Terima kasih dan tetap semangat belajar!</p>
{{--<p>This link will expire in {{ config('auth.passwords.users.expire') }} minutes.</p>--}}
{{--<p>If you didn’t request this, please ignore this email.</p>--}}
<p>Salam Hangat,<br>Tim Jadi Praktisi</p>
</body>
</html>
