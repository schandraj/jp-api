<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PurchaseConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public $details;

    public function __construct($details)
    {
        $this->details = $details;
    }

    public function build()
    {
        return $this->subject('Pembelian Berhasil! Saatnya Akses Kontenmu 🎉')
            ->view('emails.purchase_confirmation')
            ->with('details', $this->details);
    }
}
