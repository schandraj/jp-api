<!DOCTYPE html>
<html>
<head>
    <title>Pembelian Berhasil!</title>
</head>
<body>
<h2>Hi {{ $details['name'] }},</h2>
<p>Terima kasih telah melakukan pembelian di Jadipraktisi. Pembayaran telah kami terima, dan konten yang kamu beli sekarang sudah siap untuk diakses.</p>
<h3>📘 Detail Pembelian</h3>
<p>Nama Konten: {{ $details['course_title'] }}</p>
<p>Harga: Rp {{ number_format($details['total'], 0, ',', '.') }}</p>
<p>Metode Pembayaran: {{ $details['payment_method'] }}</p>
<p>Kamu bisa langsung login menggunakan email akun yang telah terdaftar untuk mengakses konten:</p>
<p><a href="{{$details['url']}}">👉 Akses Sekarang</a></p>
<p>Jika kamu mengalami kendala saat login atau mengakses konten, tim kami siap membantu melalui <a href="mailto:cs@jadipraktisi.com">cs@jadipraktisi.com</a>.</p>
<p>Selamat belajar! 🚀</p>
<p>Salam hangat,<br>Tim Jadipraktisi</p>
</body>
</html>
