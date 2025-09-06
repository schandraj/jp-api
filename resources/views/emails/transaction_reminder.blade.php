<!DOCTYPE html>
<html>
<head>
    <title>Transaksi Belum Selesai</title>
</head>
<body>
<h2>Hi {{ $details['name'] }},</h2>
<p>Kami melihat kamu sedang dalam proses pembelian konten di Jadipraktisi, tapi transaksimu belum selesai.</p>
<h3>📘 Rincian Pembelian</h3>
<p>Nama Konten: {{ $details['course_title'] }}</p>
<p>Harga: Rp {{ number_format($details['total'], 0, ',', '.') }}</p>
<p>Untuk melanjutkan pembayaran, kamu bisa login terlebih dahulu menggunakan email akun terdaftar, lalu selesaikan transaksi dari dashboard.</p>
<p><a href="{{$details['url']}}">👉 Lanjutkan Pembayaran</a></p>
<p>Kalau ada kendala, kami siap bantu di <a href="mailto:cs@jadipraktisi.com">cs@jadipraktisi.com</a>.</p>
<p>Semangat terus untuk upgrade skill-mu!</p>
<p>Salam hangat,<br>Tim Jadipraktisi</p>
</body>
</html>
