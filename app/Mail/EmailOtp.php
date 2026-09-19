<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailOtp extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Kode Aktivasi UltrAI');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<div style="font-family:sans-serif;max-width:480px;margin:0 auto;padding:24px">'
            .'<h2 style="margin:0 0 12px">Aktivasi akun UltrAI</h2>'
            .'<p>Masukkan kode berikut di halaman profil untuk mengaktifkan email Anda:</p>'
            .'<p style="font-size:32px;font-weight:800;letter-spacing:8px;margin:16px 0">'.e($this->code).'</p>'
            .'<p style="color:#64748b;font-size:13px">Kode berlaku 10 menit. Abaikan email ini jika Anda tidak mendaftar di UltrAI.</p>'
            .'</div>');
    }
}
