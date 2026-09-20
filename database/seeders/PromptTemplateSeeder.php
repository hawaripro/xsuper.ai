<?php

namespace Database\Seeders;

use App\Models\PromptTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds exactly 500 curated Indonesian prompt templates: 50 for each of the 10
 * catalogue categories. Every template is composed from a hand-written task
 * pattern crossed with a concrete subject, so each row reads as a usable
 * prompt rather than generated filler. Idempotent through `template_key`.
 */
class PromptTemplateSeeder extends Seeder
{
    private const PER_CATEGORY = 50;

    public function run(): void
    {
        $order = 0;
        $count = 0;
        foreach ($this->blueprints() as $category => [$patterns, $subjects]) {
            $mode = PromptTemplate::CATEGORY_MODES[$category] ?? null;
            $made = 0;
            foreach ($patterns as $patternIndex => [$title, $prompt]) {
                foreach ($subjects as $subjectIndex => $subject) {
                    if ($made >= self::PER_CATEGORY) {
                        break 2;
                    }
                    $fullTitle = str_replace('{s}', $subject, $title);
                    PromptTemplate::updateOrCreate(
                        ['template_key' => Str::slug($category.' '.$patternIndex.' '.$subjectIndex.' '.$fullTitle)],
                        [
                            'category' => $category,
                            'title' => Str::limit($fullTitle, 150, ''),
                            'prompt_text' => str_replace('{s}', $subject, $prompt),
                            'mode' => $mode,
                            'is_active' => true,
                            'sort_order' => $order++,
                        ],
                    );
                    $made++;
                    $count++;
                }
            }
        }

        $this->command?->info("Prompt templates seeded: {$count}");
    }

    /** @return array<string, array{0: array<int, array{0: string, 1: string}>, 1: array<int, string>} > */
    private function blueprints(): array
    {
        return [
            'Coding' => [[
                ['Review kode {s}', "Bertindaklah sebagai senior engineer. Review kode {s} berikut: jelaskan bug potensial, masalah keamanan, dan perbaikan performa. Beri contoh kode perbaikannya, urutkan dari yang paling berdampak.\n\n[tempel kode di sini]"],
                ['Debug error {s}', "Saya menemukan error saat menjalankan kode {s}. Analisis pesan error berikut, jelaskan akar masalahnya dengan bahasa sederhana, lalu berikan langkah perbaikan minimal yang aman.\n\nError: [tempel pesan error]\nKode: [tempel kode]"],
                ['Refactor fungsi {s}', "Refactor kode {s} berikut supaya lebih mudah dibaca dan diuji tanpa mengubah perilakunya. Jelaskan setiap perubahan dan alasannya, lalu tulis unit test untuk jalur utama.\n\n[tempel kode]"],
                ['Jelaskan konsep {s}', "Jelaskan konsep {s} untuk developer pemula: analogi sederhana, contoh kode pendek yang bisa dijalankan, kesalahan umum, dan satu latihan kecil untuk memastikan paham."],
                ['Tulis fungsi {s}', "Tulis fungsi {s} yang bersih dan teruji. Sertakan: tanda tangan fungsi dengan tipe, validasi input, penanganan error, contoh pemakaian, dan unit test singkat."],
                ['Optimasi query {s}', "Query {s} saya lambat. Analisis query berikut, jelaskan kenapa lambat (index, N+1, full scan), lalu tulis versi optimalnya beserta index yang disarankan.\n\n[tempel query dan skema tabel]"],
                ['Buat API {s}', "Rancang endpoint API untuk {s}: method, path, request/response JSON, kode status, validasi, dan contoh error. Sertakan pertimbangan keamanan singkat."],
                ['Tulis dokumentasi {s}', "Tulis dokumentasi teknis untuk {s} berikut: ringkasan, cara pakai, parameter, contoh request/response, dan bagian troubleshooting.\n\n[tempel kode atau spesifikasi]"],
                ['Konversi kode ke {s}', "Konversikan kode berikut ke {s} secara idiomatis (bukan terjemahan baris per baris). Jelaskan perbedaan pendekatan yang penting.\n\n[tempel kode]"],
                ['Setup proyek {s}', "Pandu saya menyiapkan proyek {s} dari nol: struktur folder, dependensi minimum, konfigurasi penting, skrip dev/build, dan checklist sebelum deploy."],
            ], ['JavaScript', 'PHP Laravel', 'Python', 'React', 'SQL']],

            'UMKM' => [[
                ['Rencana promo {s}', "Saya pemilik {s}. Buat rencana promosi 30 hari dengan budget kecil: ide konten per minggu, jadwal posting, dan cara mengukur hasilnya. Fokus pada pelanggan sekitar dan pelanggan lama."],
                ['Balasan chat pembeli {s}', "Tulis 5 template balasan chat untuk {s}: menyapa pembeli baru, menjawab tanya harga, menangani komplain, follow up keranjang, dan ucapan terima kasih. Nada ramah dan tidak kaku."],
                ['Hitung HPP {s}', "Bantu saya menghitung HPP untuk {s}. Tanyakan bahan/biaya yang diperlukan satu per satu, lalu susun tabel HPP, saran harga jual dengan margin sehat, dan titik impas sederhana."],
                ['Deskripsi produk {s}', "Tulis 3 versi deskripsi produk untuk {s}: versi singkat untuk marketplace, versi bercerita untuk Instagram, dan versi informatif untuk katalog WhatsApp. Sertakan kata kunci pencarian."],
                ['Ide konten sebulan {s}', "Buat kalender konten 30 hari untuk {s}: campuran edukasi, testimoni, behind the scene, dan promosi. Tulis hook 1 kalimat untuk tiap ide."],
                ['Program pelanggan setia {s}', "Rancang program pelanggan setia sederhana untuk {s} tanpa aplikasi tambahan: mekanisme poin/stempel, hadiah realistis, cara komunikasi, dan contoh pengumumannya."],
                ['Analisis pesaing {s}', "Susun kerangka analisis pesaing untuk {s}: apa yang perlu saya amati (harga, menu/produk, ulasan, promosi), cara mencatatnya mingguan, dan cara mengubah temuan jadi aksi."],
                ['SOP harian {s}', "Buat SOP harian untuk {s}: buka toko, kebersihan, pelayanan, pencatatan kas, tutup toko. Format checklist yang bisa dicetak."],
                ['Naskah promosi WhatsApp {s}', "Tulis 5 pesan broadcast WhatsApp untuk {s} yang tidak terasa spam: promo baru, produk kembali tersedia, ucapan hari raya, minta ulasan, dan info jam operasional."],
                ['Rencana keuangan {s}', "Bantu susun pencatatan keuangan sederhana untuk {s}: kategori pemasukan/pengeluaran, format harian-mingguan-bulanan, dan 3 indikator sehat/tidaknya usaha."],
            ], ['warung makan', 'toko baju online', 'jasa laundry', 'kedai kopi', 'bengkel motor']],

            'Konten' => [[
                ['Hook video {s}', "Tulis 10 hook pembuka (3 detik pertama) untuk video {s}. Variasikan gaya: pertanyaan, fakta mengejutkan, konflik, before-after, dan larangan. Sertakan alasan kenapa tiap hook menahan penonton."],
                ['Naskah video 60 detik {s}', "Tulis naskah video 60 detik tentang {s}: hook, isi 3 poin, dan call to action. Sertakan petunjuk visual per bagian dan perkiraan durasi tiap segmen."],
                ['Caption Instagram {s}', "Tulis 5 caption Instagram untuk {s} dengan gaya berbeda: storytelling, edukatif singkat, humor ringan, ajakan diskusi, dan hard selling halus. Sertakan saran hashtag."],
                ['Ide konten 30 hari {s}', "Buat 30 ide konten tentang {s} dibagi 4 pilar: edukasi, hiburan, kredibilitas, dan penjualan. Format tabel: hari, pilar, ide, format (reel/carousel/story)."],
                ['Thread X {s}', "Tulis thread X (Twitter) 8 tweet tentang {s}: tweet pembuka yang membuat penasaran, isi berurutan dan mudah dicerna, tweet penutup dengan ajakan follow. Maksimal 260 karakter per tweet."],
                ['Judul YouTube {s}', "Buat 10 judul YouTube tentang {s} di bawah 60 karakter beserta konsep thumbnail 1 kalimat untuk masing-masing. Hindari clickbait yang menipu."],
                ['Repurpose konten {s}', "Saya punya satu konten panjang tentang {s}. Pecah menjadi: 3 reel pendek, 1 carousel 7 slide, 2 story interaktif, dan 1 email newsletter. Tulis kerangkanya masing-masing.\n\n[tempel konten]"],
                ['Skrip live {s}', "Susun rundown live 30 menit tentang {s}: pembukaan 3 menit, 3 segmen isi, sesi tanya jawab, dan penutup dengan penawaran. Sertakan pertanyaan pemancing kalau penonton sepi."],
                ['Storyboard reel {s}', "Buat storyboard reel 30 detik tentang {s}: 6-8 shot, teks di layar per shot, transisi, dan saran musik/tempo. Format tabel."],
                ['Konten pilar {s}', "Rancang strategi konten pilar untuk topik {s}: 1 konten utama mendalam + 8 konten turunan lintas format. Jelaskan urutan produksi paling efisien."],
            ], ['tips keuangan pribadi', 'kuliner lokal', 'produktivitas', 'parenting', 'skincare']],

            'Marketplace' => [[
                ['Judul produk {s}', "Tulis 5 opsi judul produk marketplace untuk {s} (maks 100 karakter): gabungkan kata kunci pencarian, merek, varian, dan manfaat utama tanpa terlihat spam kata kunci."],
                ['Deskripsi terstruktur {s}', "Tulis deskripsi marketplace untuk {s} dengan struktur: 3 poin keunggulan, spesifikasi lengkap format daftar, isi paket, garansi, dan FAQ singkat 3 pertanyaan."],
                ['Balas ulasan buruk {s}', "Tulis 3 template balasan untuk ulasan bintang 1-2 pada produk {s}: kasus barang rusak, kasus salah kirim, dan kasus ekspektasi tidak sesuai. Nada profesional, menawarkan solusi nyata."],
                ['Strategi harga {s}', "Bantu tentukan strategi harga untuk {s}: analisis rentang harga pasar, posisi yang saya mau (murah/menengah/premium), taktik harga coret, dan kapan ikut flash sale."],
                ['Riset kata kunci {s}', "Susun daftar 20 kata kunci pencarian untuk produk {s}: kelompokkan berdasarkan niat beli tinggi/sedang/rendah, dan tunjukkan cara memakainya di judul, deskripsi, dan nama varian."],
                ['Naskah iklan produk {s}', "Tulis naskah iklan singkat untuk {s} di fitur iklan marketplace: 3 variasi headline + 2 variasi deskripsi. Fokus pada masalah yang diselesaikan produk."],
                ['Paket bundling {s}', "Usulkan 3 ide bundling untuk {s}: kombinasi produk, harga paket vs satuan, nama paket yang menarik, dan cara menampilkannya di etalase."],
                ['Halaman toko {s}', "Rancang etalase toko untuk penjual {s}: kategori etalase, banner utama (teks + visual), produk unggulan yang ditonjolkan, dan deskripsi toko yang meyakinkan."],
                ['Analisis performa {s}', "Saya jualan {s}. Buat kerangka evaluasi mingguan: metrik yang harus dicek (kunjungan, konversi, keranjang), pertanyaan diagnosa kalau angka turun, dan aksi perbaikannya."],
                ['Chat penawaran {s}', "Tulis 5 template chat untuk calon pembeli {s}: menjawab 'ini ready?', nego harga, tanya perbedaan varian, minta rekomendasi, dan konfirmasi sebelum kirim."],
            ], ['casing HP', 'sepatu sneakers', 'peralatan dapur', 'tas wanita', 'aksesoris komputer']],

            'Excel' => [[
                ['Rumus untuk {s}', "Saya butuh rumus Excel/Spreadsheet untuk {s}. Jelaskan rumusnya langkah demi langkah, beri contoh data kecil, tunjukkan hasilnya, dan sebutkan jebakan umum (format sel, referensi absolut)."],
                ['Rekap otomatis {s}', "Bantu buat rekap otomatis {s}: struktur sheet input, sheet rekap dengan SUMIFS/COUNTIFS, dan validasi data supaya entri konsisten. Tulis rumus lengkapnya."],
                ['Pivot table {s}', "Pandu saya membuat pivot table untuk {s}: susunan rows/columns/values yang tepat, filter yang berguna, dan 3 insight yang biasanya muncul dari data seperti ini."],
                ['Dashboard sederhana {s}', "Rancang dashboard 1 layar untuk {s} di Spreadsheet: 4-6 kartu angka utama, 2 grafik, slicer/filter, dan rumus di balik masing-masing. Jelaskan tata letaknya."],
                ['Bersihkan data {s}', "Data {s} saya berantakan (spasi ganda, huruf besar-kecil campur, duplikat, format tanggal beda-beda). Beri urutan langkah membersihkannya beserta rumus/fitur yang dipakai."],
                ['Template kerja {s}', "Buatkan struktur template Spreadsheet siap pakai untuk {s}: nama kolom, tipe data, contoh 3 baris isi, conditional formatting yang membantu, dan proteksi sel yang disarankan."],
                ['Otomasi Apps Script {s}', "Tulis Google Apps Script untuk {s}: kode lengkap dengan komentar, cara memasangnya, trigger yang tepat, dan batasan kuota yang perlu diketahui."],
                ['Analisis penjualan {s}', "Dari data penjualan {s} (tanggal, produk, qty, harga), tunjukkan cara menghitung: penjualan per bulan, produk terlaris, rata-rata keranjang, dan tren sederhana. Tulis semua rumusnya."],
                ['Jadwal & reminder {s}', "Buat sistem jadwal {s} di Spreadsheet: kolom yang diperlukan, rumus status otomatis (terlambat/segera/aman) dengan conditional formatting, dan cara membuat reminder harian."],
                ['Laporan bulanan {s}', "Susun format laporan bulanan {s}: ringkasan eksekutif 5 angka, tabel detail, perbandingan bulan lalu (rumus % perubahan), dan catatan analisis. Siap dicetak satu halaman."],
            ], ['stok barang', 'absensi karyawan', 'keuangan pribadi', 'penjualan toko', 'data pelanggan']],

            'Desain' => [[
                ['Konsep logo {s}', "Bertindaklah sebagai brand designer. Buat 3 konsep logo untuk {s}: filosofi bentuk, palet warna (hex), tipografi yang cocok, dan bagaimana logo tampil di media kecil (favicon) dan besar (spanduk)."],
                ['Palet warna {s}', "Susun palet warna untuk {s}: warna utama, sekunder, netral, dan aksen (semua hex), aturan pemakaian 60-30-10, serta contoh kombinasi teks-latar yang lolos kontras aksesibilitas."],
                ['Brief desain feed {s}', "Tulis brief desain feed Instagram untuk {s}: gaya visual, grid 9 post pertama, elemen wajib per template, dan hal yang harus dihindari. Format siap kirim ke desainer."],
                ['Kemasan produk {s}', "Rancang konsep kemasan untuk {s}: bentuk dan bahan, hirarki informasi pada kemasan, elemen visual utama, dan varian ukuran. Jelaskan alasannya dari sisi rak toko."],
                ['Tipografi brand {s}', "Rekomendasikan pasangan font untuk {s}: font judul + font isi (tersedia di Google Fonts), ukuran skala tipografi, dan contoh pemakaian pada 3 media berbeda."],
                ['Banner promosi {s}', "Rancang banner promosi untuk {s}: ukuran untuk 3 platform, susunan elemen (headline, visual, CTA), teks yang dipakai, dan versi A/B untuk diuji."],
                ['Moodboard {s}', "Buat arahan moodboard untuk {s}: 5 kata kunci suasana, referensi gaya visual yang dicari, tekstur/pola, serta apa yang tidak boleh masuk. Sertakan kata kunci pencarian referensi."],
                ['Desain kartu nama {s}', "Rancang kartu nama untuk {s}: informasi yang dicantumkan, tata letak depan-belakang, pilihan finishing, dan kesalahan umum yang harus dihindari."],
                ['Identitas visual acara {s}', "Susun identitas visual untuk acara {s}: konsep tema, logo acara, palet, aplikasi ke tiket/lanyard/backdrop, dan template post pengumuman."],
                ['UI halaman {s}', "Rancang wireframe halaman {s}: struktur section dari atas ke bawah, isi tiap section, hirarki visual, dan micro-interaction yang layak ditambahkan. Jelaskan alasan urutannya."],
            ], ['brand kopi lokal', 'toko kue rumahan', 'startup teknologi', 'studio yoga', 'jasa fotografi']],

            'Prompt Gambar' => [[
                ['Foto produk {s}', "Foto produk profesional {s} di studio: pencahayaan softbox lembut, latar warna solid yang serasi, bayangan halus, detail tekstur tajam, sudut 45 derajat, gaya katalog e-commerce premium, resolusi tinggi."],
                ['Potret gaya {s}', "Potret close-up dengan gaya {s}: pencahayaan dramatis satu sisi, ekspresi natural, kedalaman bidang dangkal, bokeh lembut, kulit realistis tanpa berlebihan, komposisi rule of thirds."],
                ['Ilustrasi {s}', "Ilustrasi digital bergaya {s}: garis bersih, palet warna harmonis 4-5 warna, komposisi seimbang, karakter ekspresif, latar sederhana yang mendukung subjek, cocok untuk sampul artikel."],
                ['Pemandangan {s}', "Pemandangan {s} saat golden hour: cahaya hangat keemasan, kabut tipis di kejauhan, detail tekstur alam, komposisi leading lines, langit dramatis namun realistis, foto lanskap profesional."],
                ['Makanan {s}', "Food photography {s}: tampilan menggugah selera, uap tipis terlihat, properti pendukung minimal, pencahayaan jendela alami, sudut 30 derajat, kedalaman bidang dangkal, gaya editorial majalah kuliner."],
                ['Interior {s}', "Interior {s}: pencahayaan alami dari jendela besar, material kayu dan tekstil hangat, tanaman hias, komposisi simetris, suasana tenang dan hidup, foto arsitektur profesional wide-angle tanpa distorsi."],
                ['Karakter {s}', "Desain karakter {s}: pose dinamis, siluet kuat dan mudah dikenali, palet warna khas, detail kostum yang bercerita, latar netral untuk fokus ke karakter, sheet gaya konsisten."],
                ['Poster {s}', "Poster {s}: komposisi tipografi kuat, satu elemen visual dominan, palet warna berani namun serasi, ruang kosong yang disengaja, gaya desain grafis modern, siap cetak."],
                ['Isometrik {s}', "Ilustrasi isometrik {s}: sudut 45 derajat konsisten, warna-warna lembut, detail kecil yang hidup, bayangan halus, gaya flat modern dengan sedikit gradasi, latar bersih."],
                ['Abstrak {s}', "Karya abstrak bertema {s}: bentuk organik mengalir, gradasi warna halus, tekstur berlapis, komposisi seimbang asimetris, cocok untuk latar presentasi premium, tanpa teks."],
            ], ['kopi susu botolan', 'sinematik noir', 'flat design', 'pegunungan tropis', 'nasi goreng spesial']],

            'Prompt Video' => [[
                ['Video produk {s}', "Video showcase produk {s} 15 detik: buka dengan close-up detail, gerakan kamera orbit perlahan, pencahayaan studio lembut, transisi halus antar sudut, akhiri dengan tampilan penuh produk dan ruang untuk teks."],
                ['Sinematik {s}', "Adegan sinematik {s}: gerakan kamera dolly perlahan ke depan, pencahayaan golden hour, kedalaman bidang dangkal, tone warna teal-orange halus, gerak lambat 60fps, suasana tenang dan megah."],
                ['Timelapse {s}', "Timelapse {s}: perubahan waktu terlihat jelas, kamera statis pada tripod, langit bergerak dinamis, pencahayaan berubah natural, durasi 10 detik, resolusi tinggi tanpa flicker."],
                ['B-roll {s}', "Kumpulan b-roll {s}: 5 shot berbeda (wide, medium, close-up, detail, gerakan), masing-masing 3 detik, gerakan kamera halus handheld terstabilkan, pencahayaan natural konsisten antar shot."],
                ['Animasi logo {s}', "Animasi logo untuk {s}: muncul dari elemen dasar yang menyatu, durasi 4 detik, latar bersih, gerakan halus dengan easing profesional, akhiri dengan logo diam sempurna, cocok untuk intro video."],
                ['Drone {s}', "Footage drone {s}: mulai dari ketinggian rendah lalu naik perlahan mengungkap pemandangan penuh, gerakan stabil tanpa patah, cahaya pagi lembut, bayangan panjang, kualitas sinematik."],
                ['Slow motion {s}', "Slow motion {s}: 120fps, momen puncak gerakan tertangkap detail, pencahayaan kuat dari samping, partikel/tetesan terlihat jelas, latar gelap untuk kontras, durasi 8 detik."],
                ['Transisi kreatif {s}', "Video pendek {s} dengan 3 transisi kreatif: whip pan, match cut objek serupa, dan transisi tangan menutup lensa. Tempo cepat mengikuti beat, masing-masing adegan 2-3 detik."],
                ['Suasana kota {s}', "Video suasana {s} di malam hari: lampu neon terpantul di jalan basah, orang berlalu-lalang dengan motion blur ringan, kamera statis komposisi kuat, tone warna sinematik, durasi 10 detik."],
                ['Stop motion {s}', "Stop motion {s}: 12fps, objek bergerak dan tersusun sendiri, latar meja kayu dengan pencahayaan hangat, gaya playful dan rapi, durasi 8 detik, loop mulus."],
            ], ['botol skincare', 'pantai saat senja', 'pembuatan kopi', 'jalan kota tua', 'perakitan produk']],

            'Bisnis' => [[
                ['Rencana bisnis {s}', "Susun rencana bisnis ringkas 1 halaman untuk {s}: masalah, solusi, target pasar, model pendapatan, biaya awal realistis, dan 3 risiko terbesar beserta mitigasinya."],
                ['Pitch deck {s}', "Buat kerangka pitch deck 10 slide untuk {s}: isi tiap slide dalam 3 poin, data yang perlu disiapkan, dan satu kalimat kunci per slide yang harus diingat investor."],
                ['Validasi ide {s}', "Bantu validasi ide {s} dalam 2 minggu tanpa membangun produk: 5 eksperimen murah, metrik keberhasilan tiap eksperimen, dan kriteria lanjut/berhenti yang jujur."],
                ['Proposal kerja sama {s}', "Tulis proposal kerja sama untuk {s}: latar belakang singkat, bentuk kerja sama, keuntungan kedua pihak, skema bagi hasil yang wajar, dan langkah berikutnya. Maksimal 1 halaman."],
                ['Analisis SWOT {s}', "Buat analisis SWOT untuk {s} yang tidak generik: minimal 4 poin spesifik per kuadran, lalu ubah temuan menjadi 5 aksi prioritas 90 hari."],
                ['Email penawaran {s}', "Tulis email penawaran B2B untuk {s}: subjek yang dibuka, pembuka yang relevan dengan calon klien, nilai yang ditawarkan dalam 3 poin, bukti singkat, dan ajakan bertemu 15 menit."],
                ['Strategi ekspansi {s}', "Usaha {s} saya stabil. Susun 3 jalur ekspansi (produk baru, lokasi/kanal baru, segmen baru): syarat kesiapan, perkiraan biaya, risiko, dan urutan yang disarankan."],
                ['Job desc & rekrut {s}', "Buat job description untuk posisi pertama yang harus direkrut {s}: tanggung jawab, kriteria wajib vs nilai plus, kisaran gaji wajar, dan 5 pertanyaan interview yang mengungkap kualitas nyata."],
                ['Negosiasi {s}', "Siapkan saya untuk negosiasi {s}: target ideal-realistis-batas bawah, argumen berbasis data, 3 keberatan yang mungkin muncul dan jawabannya, serta kapan harus berhenti."],
                ['Laporan investor {s}', "Susun format update bulanan untuk investor {s}: highlight, angka utama vs bulan lalu, tantangan jujur, kebutuhan bantuan, dan rencana bulan depan. Nada percaya diri tanpa menutupi masalah."],
            ], ['aplikasi kasir UMKM', 'brand fashion lokal', 'jasa desain interior', 'katering sehat', 'kursus online']],

            'Belajar' => [[
                ['Rangkum materi {s}', "Rangkum materi {s} berikut menjadi: 5 poin inti, penjelasan sederhana tiap poin dengan analogi, istilah penting beserta artinya, dan 3 pertanyaan untuk menguji pemahaman.\n\n[tempel materi]"],
                ['Rencana belajar {s}', "Buat rencana belajar {s} selama 30 hari untuk pemula sibuk (45 menit/hari): pembagian topik mingguan, sumber belajar gratis, latihan tiap akhir minggu, dan cara tahu saya benar-benar paham."],
                ['Jelaskan seperti umur 12 {s}', "Jelaskan {s} seolah saya berumur 12 tahun: pakai analogi kehidupan sehari-hari, hindari istilah teknis, lalu naikkan bertahap ke penjelasan versi dewasa dalam 3 tingkat."],
                ['Kartu hafalan {s}', "Buat 20 kartu hafalan (flashcard) untuk topik {s}: sisi depan pertanyaan singkat, sisi belakang jawaban maksimal 2 kalimat. Urutkan dari konsep dasar ke lanjutan."],
                ['Latihan soal {s}', "Buat 10 soal latihan {s} bertingkat (3 mudah, 4 sedang, 3 sulit) beserta kunci jawaban dan pembahasan singkat yang menjelaskan cara berpikirnya, bukan cuma jawabannya."],
                ['Simulasi tanya jawab {s}', "Jadilah tutor {s}. Ajukan satu pertanyaan kepada saya, tunggu jawaban saya, nilai jawaban itu, jelaskan yang kurang, lalu lanjut ke pertanyaan berikutnya. Mulai dari dasar."],
                ['Peta konsep {s}', "Buat peta konsep {s} dalam bentuk teks terstruktur: konsep pusat, cabang utama, subcabang, dan hubungan antar cabang. Tandai 3 konsep yang paling sering disalahpahami."],
                ['Belajar dari kesalahan {s}', "Berikut jawaban saya untuk soal {s}. Analisis di mana letak salah pahamnya, jelaskan konsep yang benar, dan beri 2 soal serupa untuk memastikan saya tidak mengulangi kesalahan.\n\n[tempel soal dan jawaban]"],
                ['Persiapan ujian {s}', "Susun strategi 7 hari menjelang ujian {s}: prioritas topik berdasarkan bobot, jadwal harian, teknik mengingat yang sesuai materi, dan persiapan hari-H."],
                ['Terjemah & kosakata {s}', "Bantu saya belajar {s}: terjemahkan teks berikut, tandai 10 kosakata penting dengan artinya dan contoh kalimat baru, lalu buat 5 pertanyaan pemahaman.\n\n[tempel teks]"],
            ], ['matematika SMA', 'bahasa Inggris', 'sejarah Indonesia', 'fisika dasar', 'ekonomi']],
        ];
    }
}
