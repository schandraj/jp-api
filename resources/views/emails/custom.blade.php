<!DOCTYPE html>
<html>
<head>
    <title>{{ $details['subject'] }}</title>
</head>
<body>
<h1>{{ $details['subject'] }}</h1>
<p>{{ $details['body'] }}</p>
<p>Regards,<br>{{ config('app.name') }}</p>
</body>
</html>
