<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PromptTemplate;

class PromptTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            // Coding
            ['category' => 'Coding', 'title' => 'Debug Error Coding', 'prompt_text' => 'Saya mendapat error berikut di kode saya. Tolong analisis penyebabnya dan berikan solusi:\n\n[Paste error message di sini]\n\nKode yang bermasalah:\n```\n[Paste kode di sini]\n```', 'mode' => 'coding_assistant', 'sort_order' => 1],
            ['category' => 'Coding', 'title' => 'Buat API Endpoint', 'prompt_text' => 'Buatkan API endpoint dengan spesifikasi berikut:\n- Method: [GET/POST/PUT/DELETE]\n- Path: /api/[path]\n- Fungsi: [jelaskan fungsi]\n- Request body: [jelaskan parameter]\n- Response: [jelaskan format response]\n\nGunakan bahasa: [PHP/Node.js/Python]', 'mode' => 'coding_assistant', 'sort_order' => 2],
            ['category' => 'Coding', 'title' => 'Optimasi Query Database', 'prompt_text' => 'Tolong optimasi query database berikut agar lebih cepat:\n\n```sql\n[Paste query di sini]\n```\n\nTabel memiliki [jumlah] baris data. Jelaskan juga index yang perlu ditambahkan.', 'mode' => 'coding_assistant', 'sort_order' => 3],

            // UMKM
            ['category' => 'UMKM', 'title' => 'Buat Caption Jualan', 'prompt_text' => 'Buatkan 5 variasi caption jualan untuk produk berikut:\n- Nama produk: [nama]\n- Harga: Rp [harga]\n- Keunggulan: [list keunggulan]\n- Target market: [target]\n- Platform: [Instagram/TikTok/WhatsApp]\n\nGunakan bahasa santai, persuasif, dan ada CTA yang kuat.', 'mode' => 'umkm_assistant', 'sort_order' => 1],
            ['category' => 'UMKM', 'title' => 'Buat Balasan Customer', 'prompt_text' => 'Buatkan template balasan untuk customer yang:\n- Komplain tentang: [masalah]\n- Nada customer: [marah/kecewa/bingung]\n- Solusi yang bisa ditawarkan: [solusi]\n\nBuat balasan yang sopan, empati, dan profesional. Berikan 3 variasi.', 'mode' => 'umkm_assistant', 'sort_order' => 2],
            ['category' => 'UMKM', 'title' => 'Buat Promo Menarik', 'prompt_text' => 'Buatkan konsep promo untuk bisnis saya:\n- Jenis bisnis: [jenis]\n- Budget promo: Rp [budget]\n- Target: [target customer]\n- Durasi: [berapa hari]\n\nSertakan: nama promo, mekanisme, copywriting, dan tips eksekusi.', 'mode' => 'umkm_assistant', 'sort_order' => 3],

            // Konten
            ['category' => 'Konten', 'title' => 'Buat 30 Ide Konten', 'prompt_text' => 'Buatkan 30 ide konten untuk 1 bulan ke depan:\n- Niche: [niche]\n- Platform: [Instagram/TikTok/YouTube]\n- Target audience: [target]\n- Tone: [edukatif/hiburan/inspiratif]\n\nFormat: tabel dengan kolom (No, Ide Konten, Format, Hook, CTA)', 'mode' => 'content_creator', 'sort_order' => 1],
            ['category' => 'Konten', 'title' => 'Buat Script TikTok', 'prompt_text' => 'Buatkan script TikTok/Reels 30-60 detik:\n- Topik: [topik]\n- Style: [talking head/voiceover/tutorial]\n- Hook (3 detik pertama): harus bikin stop scroll\n- Target: [target audience]\n\nFormat: timestamp + visual + narasi', 'mode' => 'content_creator', 'sort_order' => 2],
            ['category' => 'Konten', 'title' => 'Buat Hook Viral', 'prompt_text' => 'Buatkan 10 hook pembuka yang viral untuk konten tentang:\n- Topik: [topik]\n- Platform: [platform]\n- Target: [audience]\n\nKriteria hook: bikin penasaran, relatable, atau kontroversial (tapi sopan).', 'mode' => 'content_creator', 'sort_order' => 3],

            // Marketplace
            ['category' => 'Marketplace', 'title' => 'Buat Deskripsi Produk', 'prompt_text' => 'Buatkan deskripsi produk marketplace yang SEO-friendly:\n- Nama produk: [nama]\n- Kategori: [kategori]\n- Spesifikasi: [list spesifikasi]\n- Keunggulan: [keunggulan vs kompetitor]\n- Platform: [Shopee/Tokopedia/Lazada]\n\nSertakan: judul optimasi, bullet points, dan deskripsi lengkap.', 'mode' => 'marketplace_helper', 'sort_order' => 1],
            ['category' => 'Marketplace', 'title' => 'Optimasi Judul Produk', 'prompt_text' => 'Optimasi judul produk marketplace saya:\n- Judul saat ini: [judul]\n- Kategori: [kategori]\n- Keyword target: [keyword]\n- Platform: [Shopee/Tokopedia]\n\nBerikan 5 variasi judul yang mengandung keyword utama dan long-tail keyword.', 'mode' => 'marketplace_helper', 'sort_order' => 2],
            ['category' => 'Marketplace', 'title' => 'Balas Review Negatif', 'prompt_text' => 'Buatkan balasan untuk review negatif di marketplace:\n- Review customer: "[isi review]"\n- Rating: [1-3] bintang\n- Masalah sebenarnya: [penjelasan]\n- Solusi yang ditawarkan: [solusi]\n\nBuat balasan profesional yang bisa mengubah persepsi calon buyer lain.', 'mode' => 'marketplace_helper', 'sort_order' => 3],

            // Excel
            ['category' => 'Excel', 'title' => 'Buat Rumus Excel', 'prompt_text' => 'Buatkan rumus Excel untuk kebutuhan berikut:\n- Tujuan: [apa yang mau dihitung/dicari]\n- Data ada di: [kolom/range mana]\n- Kondisi: [syarat/filter jika ada]\n- Contoh data: [berikan contoh]\n\nJelaskan cara kerja rumusnya step by step.', 'mode' => 'excel_office_helper', 'sort_order' => 1],
            ['category' => 'Excel', 'title' => 'Buat Template Laporan', 'prompt_text' => 'Buatkan struktur template laporan di Excel:\n- Jenis laporan: [keuangan/penjualan/stok/absensi]\n- Periode: [harian/mingguan/bulanan]\n- Data yang perlu dicatat: [list data]\n- Output yang diinginkan: [grafik/summary/pivot]\n\nSertakan nama kolom, rumus yang diperlukan, dan tips formatting.', 'mode' => 'excel_office_helper', 'sort_order' => 2],
            ['category' => 'Excel', 'title' => 'Buat Surat Resmi', 'prompt_text' => 'Buatkan surat resmi dengan detail:\n- Jenis surat: [undangan/permohonan/pemberitahuan/penawaran]\n- Dari: [nama instansi/perusahaan]\n- Kepada: [tujuan]\n- Perihal: [perihal]\n- Isi pokok: [poin-poin utama]\n\nGunakan format surat resmi Indonesia yang benar.', 'mode' => 'excel_office_helper', 'sort_order' => 3],

            // Desain
            ['category' => 'Desain', 'title' => 'Buat Brief Desain', 'prompt_text' => 'Buatkan creative brief untuk desain:\n- Jenis: [logo/poster/banner/feed IG]\n- Brand: [nama brand]\n- Warna brand: [warna]\n- Mood: [modern/playful/elegant/bold]\n- Pesan utama: [pesan]\n- Referensi: [jika ada]\n\nSertakan: konsep visual, layout suggestion, dan copy text.', 'mode' => 'prompt_visual_generator', 'sort_order' => 1],
            ['category' => 'Desain', 'title' => 'Buat Color Palette', 'prompt_text' => 'Buatkan rekomendasi color palette untuk:\n- Jenis bisnis: [jenis]\n- Mood yang diinginkan: [mood]\n- Target audience: [target]\n- Referensi brand yang disukai: [referensi]\n\nBerikan 3 opsi palette (masing-masing 5 warna) dengan hex code dan penjelasan psikologi warnanya.', 'mode' => 'prompt_visual_generator', 'sort_order' => 2],

            // Prompt Gambar
            ['category' => 'Prompt Gambar', 'title' => 'Buat Prompt Poster', 'prompt_text' => 'Buatkan prompt AI image generator untuk poster:\n- Tema: [tema]\n- Style: [realistic/illustration/3D/flat design]\n- Warna dominan: [warna]\n- Elemen yang harus ada: [list elemen]\n- Ukuran: [portrait/landscape/square]\n- Mood: [mood]\n\nBuat 3 variasi prompt dalam bahasa Inggris yang detail.', 'mode' => 'prompt_visual_generator', 'sort_order' => 1],
            ['category' => 'Prompt Gambar', 'title' => 'Buat Prompt Product Photo', 'prompt_text' => 'Buatkan prompt AI untuk foto produk:\n- Produk: [nama produk]\n- Background: [studio putih/lifestyle/outdoor]\n- Angle: [flat lay/45 derajat/close up]\n- Props: [props pendukung]\n- Lighting: [soft/dramatic/natural]\n\nBuat 5 variasi prompt dalam bahasa Inggris.', 'mode' => 'prompt_visual_generator', 'sort_order' => 2],

            // Prompt Video
            ['category' => 'Prompt Video', 'title' => 'Buat Prompt Video Reels', 'prompt_text' => 'Buatkan prompt untuk AI video generator:\n- Konsep: [konsep video]\n- Durasi: [5-15 detik]\n- Style: [cinematic/motion graphic/slideshow]\n- Mood: [energetic/calm/dramatic]\n- Aspect ratio: [9:16 untuk Reels/16:9 untuk YouTube]\n\nBuat 3 variasi prompt detail dalam bahasa Inggris.', 'mode' => 'prompt_visual_generator', 'sort_order' => 1],
            ['category' => 'Prompt Video', 'title' => 'Buat Storyboard Video', 'prompt_text' => 'Buatkan storyboard untuk video:\n- Tujuan: [promosi/edukasi/hiburan]\n- Durasi total: [durasi]\n- Platform: [TikTok/YouTube/IG Reels]\n- Narasi/voiceover: [ada/tidak]\n\nFormat: tabel (Scene, Durasi, Visual, Audio/Narasi, Text Overlay)', 'mode' => 'prompt_visual_generator', 'sort_order' => 2],

            // Bisnis
            ['category' => 'Bisnis', 'title' => 'Buat Copywriting Landing Page', 'prompt_text' => 'Buatkan copywriting untuk landing page:\n- Produk/jasa: [nama]\n- Target market: [target]\n- Pain point customer: [masalah mereka]\n- Solusi yang ditawarkan: [solusi]\n- Social proof: [testimoni/angka]\n- CTA: [apa yang harus dilakukan]\n\nStruktur: Hero → Problem → Solution → Benefits → Testimonial → CTA', 'mode' => 'project_builder', 'sort_order' => 1],
            ['category' => 'Bisnis', 'title' => 'Buat Business Plan Ringkas', 'prompt_text' => 'Buatkan business plan ringkas (1 halaman):\n- Nama bisnis: [nama]\n- Jenis: [produk/jasa]\n- Target market: [target]\n- Modal awal: Rp [modal]\n- Revenue model: [bagaimana menghasilkan uang]\n\nSertakan: value proposition, competitive advantage, milestone 3 bulan, dan proyeksi keuangan sederhana.', 'mode' => 'project_builder', 'sort_order' => 2],
            ['category' => 'Bisnis', 'title' => 'Analisis Kompetitor', 'prompt_text' => 'Bantu saya analisis kompetitor:\n- Bisnis saya: [deskripsi singkat]\n- Kompetitor utama: [list 3-5 kompetitor]\n- Aspek yang mau dianalisis: [harga/fitur/marketing/positioning]\n\nBuat tabel perbandingan dan berikan insight: apa yang bisa saya lakukan lebih baik?', 'mode' => 'project_builder', 'sort_order' => 3],

            // Belajar
            ['category' => 'Belajar', 'title' => 'Jelaskan Konsep Sederhana', 'prompt_text' => 'Jelaskan konsep berikut dengan bahasa yang mudah dipahami:\n- Topik: [topik]\n- Level pemahaman saya: [pemula/menengah/lanjut]\n- Konteks: [untuk apa saya perlu memahami ini]\n\nGunakan analogi sehari-hari dan berikan contoh konkret.', 'mode' => null, 'sort_order' => 1],
            ['category' => 'Belajar', 'title' => 'Buat Ringkasan Materi', 'prompt_text' => 'Buatkan ringkasan materi berikut:\n- Subjek: [subjek]\n- Topik: [topik spesifik]\n- Tujuan: [ujian/presentasi/pemahaman umum]\n\nFormat: poin-poin utama, mind map sederhana, dan 5 pertanyaan latihan beserta jawabannya.', 'mode' => null, 'sort_order' => 2],
            ['category' => 'Belajar', 'title' => 'Buat Rencana Belajar', 'prompt_text' => 'Buatkan rencana belajar untuk:\n- Skill yang mau dipelajari: [skill]\n- Waktu tersedia: [jam per hari/minggu]\n- Deadline: [kapan harus bisa]\n- Level saat ini: [pemula/ada dasar]\n\nBuat jadwal mingguan dengan milestone yang terukur dan resource yang direkomendasikan.', 'mode' => null, 'sort_order' => 3],
        ];

        foreach ($templates as $template) {
            PromptTemplate::create(array_merge($template, ['is_active' => true]));
        }
    }
}
