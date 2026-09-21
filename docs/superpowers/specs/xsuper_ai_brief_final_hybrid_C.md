# BRIEF IMPLEMENTASI FINAL — XSUPER.AI MEDIA PLATFORM

## 1. Keputusan arsitektur: C — Hybrid

Implementasikan pilihan **C — Hybrid** sebagai arsitektur Media Platform xsuper.ai. Keputusan ini sudah ditetapkan; jangan mengembalikan pekerjaan menjadi pemilihan A/B/C.

Hybrid dalam brief ini berarti satu kontrak capability internal, adapter per provider, layanan aset bersama, alur job bersama, dan antarmuka Studio yang menerima spesifikasi input seragam. Capability boleh berasal dari konfigurasi existing yang diterjemahkan atau dari schema tersimpan yang sudah dipublikasikan. Semua sumber harus menghasilkan kontrak internal yang sama melalui satu resolver.

Gunakan kembali protocol existing melalui adapter setelah perilakunya diperiksa dan diuji. Tambahkan penyimpanan capability minimum sejak fondasi; perluasan importer dan sinkronisasi katalog dilakukan bertahap. **Ini tetap C, bukan membangun A dahulu lalu mengganti arsitektur menjadi B.** Registry adalah sumber definisi model di dalam arsitektur C, bukan pengganti adapter.

Target produk adalah Studio Gambar, Video, Audio, Avatar, dan 3D yang konsisten lintas provider, serta katalog yang dapat diperluas tanpa membuat halaman atau class baru untuk setiap model yang kompatibel. Keseragaman berlaku pada kontrak dan alur bersama, bukan berarti semua media harus memakai formulir identik.

“Global” berarti model dari provider yang terintegrasi memakai pola publik dan komponen bersama. Istilah ini tidak berarti setiap fitur semua provider otomatis didukung atau boleh ditampilkan sebelum terverifikasi.

## 2. Audit awal dan batas perubahan

Periksa repository sebelum menetapkan nama tabel, class, endpoint, library, dan migration. Nama seperti `MediaModelConfig`, `FalProtocol`, dan `KinoviProtocol` adalah titik pemeriksaan dari konteks proyek; cocokkan dengan implementasi aktual.

Petakan alur generation, konfigurasi model, harga/kredit, autentikasi, upload/storage, riwayat, callback/polling, halaman Studio, dan pengaturan admin yang sudah ada. Identifikasi bagian yang dipakai ulang, diperluas, atau benar-benar perlu diganti. Rekam perilaku existing dalam pengujian sebelum melakukan refactor.

Pertahankan stack dan pola repository yang masih layak. Jangan membangun ulang billing, autentikasi, atau storage tanpa kebutuhan konkret. “Layanan” dalam brief ini boleh berupa modul dalam aplikasi existing; tidak mewajibkan microservices, message broker baru, atau pemindahan infrastruktur.

Model ID publik, harga jual, saldo, riwayat, dan API existing tidak boleh berubah diam-diam. Gunakan migration yang aman, pemetaan kompatibilitas, feature flag bila sesuai dengan proyek, dan langkah rollback yang tidak menghapus data baru.

Target katalog fal dan “sisa 13 Kinovi” harus menjadi inventaris ID model yang dapat diaudit. Verifikasi daftar, akses akun, schema, endpoint, dan status existing; jangan menciptakan model atau menganggap semuanya sudah tersedia.

## 3. Dekomposisi pekerjaan

| Tahap | Lingkup dan hasil wajib | Dependensi / batas selesai |
|---|---|---|
| **F0a — Audit & desain bersama** | Peta implementasi existing, inventaris model, keputusan kontrak awal, rancangan layout Studio/admin, dan standar visual. | Menjadi acuan implementasi; bukan proyek redesign terpisah tanpa integrasi. |
| **F0b — Media core minimum** | Capability berversi, resolver, provider adapters, aset/upload, validasi backend, job tersimpan, integrasi kredit existing, respons member/admin, dan pengaturan model minimum. | Cukup untuk membuktikan satu alur generation nyata; tidak menunggu seluruh katalog. |
| **F6a — Uji kompatibilitas schema** | Sampel schema lintas karakteristik, importer minimum, pengujian normalisasi/input/output, dan daftar keterbatasan yang eksplisit. | Berjalan bersama F0b/F1 agar kekurangan kontrak ditemukan lebih awal. |
| **F1 — Studio Gambar** | Studio gambar end-to-end dengan input sesuai capability, referensi, hasil, draft, biaya, riwayat, dan download. | F0b; menjadi implementasi acuan komponen bersama. |
| **F2 — Studio Video** | Mode video sesuai model, input gambar/audio wajib sesuai operasi, preview referensi, pemutar hasil, dan pemulihan status. | F0b dan komponen bersama yang sudah teruji; memakai temuan F6a. |
| **F3 — Studio Audio** | Alur speech/music yang dibedakan, prompt-only sesuai integrasi, input yang relevan, pemutar audio, dan download. | F0b; dapat paralel dengan F2 setelah kontrak dan komponen stabil. |
| **F4 — Studio Avatar** | Foto + audio untuk integrasi yang mendukung, pemilihan aset, validasi pasangan input, dan preview video hasil. | F0b dan komponen video. F3 hanya diperlukan untuk alur membuat suara dari teks. |
| **F5 — Studio 3D** | Teks/gambar sesuai capability, hasil terkelola, viewer untuk format yang didukung, dan download file asli. | F0b; dukungan model, format output, dan viewer harus diverifikasi. |
| **F6b — Ekspansi katalog & admin** | Import/sync bertahap, peninjauan schema, harga, publikasi, filter/pagination, status kompatibilitas, dan rollback konfigurasi. | Dimulai per kategori ketika Studio terkait siap; tidak harus menunggu semua Studio selesai. |
| **Quality gate — setiap tahap** | Pengujian fungsi, integrasi, akses, regresi, aksesibilitas, visual, dan laporan bukti. | Wajib sebelum sebuah tahap disebut selesai; bukan pekerjaan penutup saja. |

Tabel ini adalah ringkasan pekerjaan. Persyaratan berikut merupakan bagian wajib dari scope, bukan tambahan opsional yang boleh diabaikan karena tidak muat di tabel.

## 4. Fondasi C yang wajib diimplementasikan

### 4.1. Satu `MediaCapability` untuk model dan operasi

Definisikan capability berdasarkan model dan operasi/mode yang benar-benar didukung. Kontrak minimum mencakup identitas publik, versi, jenis hasil, input beserta perannya, tipe data, nilai default, pilihan parameter, batas valid, jumlah aset, serta aturan wajib dan wajib-bersyarat.

Bedakan peran aset: gambar referensi, frame awal, frame akhir, foto avatar, atau audio ucapan tidak boleh digabung menjadi satu field tanpa makna. Bedakan pula satu file dari beberapa file.

Aturan wajib mengikuti operasi aktual. Image-to-video mewajibkan gambar awal untuk operasi tersebut; audio-to-video mewajibkan audio dan input tambahan yang disyaratkan model. Talking-avatar yang memerlukan foto dan audio harus memvalidasi keduanya. Jangan menyimpulkan capability hanya dari nama model atau kategori Studio.

Gunakan aturan deklaratif yang tervalidasi, bukan JavaScript atau ekspresi bebas dari database. Nyatakan subset schema yang didukung importer dan validator. Aturan yang belum didukung tidak boleh dibuang diam-diam sehingga model tampak kompatibel.

### 4.2. Satu resolver, versi yang jelas

Seluruh Studio dan jalur submit menggunakan satu `CapabilityResolver`. Untuk model yang telah dimigrasikan, gunakan revisi tersimpan yang berstatus published. Untuk model legacy yang belum dimigrasikan, gunakan derivasi eksplisit dari konfigurasi existing. Keduanya menghasilkan bentuk kontrak yang sama.

Konfigurasi published yang rusak harus menghasilkan error konfigurasi yang dapat ditindaklanjuti admin, bukan diam-diam beralih ke legacy. Jangan membiarkan frontend, backend, dan database mempunyai tiga aturan input berbeda.

Pisahkan versi format kontrak dari revisi konfigurasi model. Simpan revisi capability yang digunakan pada setiap job, berikut identitas routing internal dan informasi harga yang relevan. Pertahankan revisi yang masih dirujuk job.

Jika capability atau harga berubah setelah formulir dibuka, backend harus mendeteksi ketidakcocokan dan meminta pembaruan/konfirmasi sesuai dampaknya. Jangan diam-diam menjalankan generation dengan input atau biaya yang berubah.

### 4.3. Pisahkan capability, presentasi, dan adapter

**Capability** menentukan input yang valid. **Metadata UI** menentukan label, urutan field, kelompok pengaturan, teks bantuan, widget, satuan, dan bagian lanjutan. **Adapter** menerjemahkan input ternormalisasi menjadi request provider.

Metadata UI tidak boleh mengubah kewajiban input secara sepihak. Anggap schema provider sebagai bahan normalisasi, bukan formulir yang langsung ditampilkan ke member. Field teknis seperti `uploadedUrls` harus dipresentasikan sebagai uploader dengan label yang sesuai perannya.

Gunakan renderer bersama untuk field umum dan komponen khusus untuk interaksi yang memang berbeda. Penambahan model yang memakai capability dan widget yang sudah didukung tidak boleh memerlukan halaman Studio baru.

### 4.4. Provider adapter dan alur eksekusi

Gunakan `MediaProviderAdapter` sebagai batas integrasi. Bungkus protocol fal/Kinovi existing yang masih layak. Provider tambahan mengikuti interface yang sama ketika integrasinya benar-benar tersedia; jangan membuat integrasi kosong lalu menandainya aktif.

Adapter menangani autentikasi provider, pemetaan payload, submit, pembacaan status, parsing hasil, serta normalisasi error. Alur bersama menangani otorisasi pengguna, resolver, validasi, koordinasi job, aset, dan integrasi kredit.

Interface harus dapat mewakili hasil langsung maupun job asinkron. Polling, webhook, dan cancel merupakan kemampuan yang harus dinyatakan dukungannya; jangan memaksakan seluruh provider mempunyai pola yang sama. Pengecualian model boleh memakai mapping deklaratif atau handler teruji, bukan duplikasi seluruh adapter per model.

### 4.5. Backend sebagai penentu validasi dan akses

Frontend memberi feedback dan membantu pengguna mengisi formulir. Backend wajib memvalidasi ulang input, mode, batas parameter, kepemilikan aset, akses model, saldo, dan batas penggunaan sebelum request diteruskan. Validasi browser tidak menggantikan validasi server. [R1]

Gunakan aturan backend yang sama untuk Studio dan API publik existing, bila API tersebut ada. Tolak field yang tidak diizinkan; jangan meneruskan payload member mentah ke provider.

Respons member hanya berisi identitas model publik, capability publik, harga jual yang relevan, status, hasil, dan pesan error yang aman. Provider routing, kredensial, harga modal, endpoint internal, serta debug mentah tetap di server dan hanya tersedia kepada admin berizin sesuai kebutuhan.

Jangan hanya menyembunyikan provider di CSS atau komponen. Terapkan pemisahan respons API dan kontrol akses pada endpoint, job, serta aset. Nama tampilan model tetap mengikuti konfigurasi publik; jangan menjanjikan bahwa asal teknologi mustahil ditebak dari nama atau hasilnya.

### 4.6. Layanan aset media terkontrol

Gunakan ID aset internal yang memiliki pemilik, jenis media, ukuran, metadata relevan, lokasi penyimpanan, dan status retensi. Backend memeriksa akses sebelum memberikan URL atau mengirim referensi ke provider.

Validasi ukuran, jenis file yang diizinkan, dan isi/signature file sesuai kebutuhan; jangan hanya mempercayai ekstensi atau `Content-Type` dari browser. Batasi upload kepada pengguna berizin dan terapkan kuota yang sesuai. [R2]

“URL publik untuk provider” tidak berarti bucket pengguna dibuka seluruhnya. Pilih signed URL, URL aset terkontrol, atau upload provider sesuai dukungan integrasi. Masa berlaku harus cukup untuk waktu antre dan pengambilan aset oleh provider. Perubahan metode pengiriman ini tidak boleh mengubah ID aset yang digunakan produk.

Kinovi mendokumentasikan penggunaan URL publik milik aplikasi maupun upload ke storage mereka. Adapter harus memilih mekanisme berdasarkan integrasi yang diverifikasi, bukan asumsi bahwa satu metode selalu wajib. [R3]

Tentukan retensi referensi dan hasil, perilaku penghapusan, serta penanganan URL kedaluwarsa. Hasil yang ditawarkan sebagai riwayat tersimpan harus mempunyai strategi persistensi yang jelas. Jangan menjadikan URL sementara provider sebagai satu-satunya identitas permanen aset.

Jika aplikasi mengambil media dari URL eksternal, batasi protokol dan tujuan jaringan, periksa redirect, dan blokir akses ke jaringan internal. URL callback atau endpoint provider tidak boleh dikendalikan bebas oleh member.

### 4.7. Job persisten, retry aman, kredit konsisten

Simpan job secara persisten beserta pemilik, input tervalidasi, revisi konfigurasi, ID request provider bila tersedia, status, hasil, dan informasi transaksi existing yang relevan. Upload dan generation mempunyai status berbeda; progress upload bukan progress generation.

Job harus bisa dibuka setelah refresh, pindah halaman, atau browser ditutup. Gunakan polling/webhook sesuai dukungan provider dan lakukan rekonsiliasi untuk job yang statusnya belum pasti. Lindungi callback dengan mekanisme verifikasi yang tersedia; bila tidak ada autentikasi callback yang memadai, verifikasi status melalui API provider sebelum memperbarui hasil atau kredit.

Timeout submit tidak otomatis berarti provider gagal menerima request. Jangan langsung membuat request berbayar baru ketika hasil submit belum pasti. Pisahkan retry pengecekan status dari pembuatan generation baru.

Terapkan deduplikasi submit pengguna dan pemrosesan idempotent pada perubahan job/transaksi internal. Callback duplikat atau datang tidak berurutan tidak boleh menggandakan debit/refund atau mengembalikan status final ke status lama. Gunakan idempotency provider jika tersedia; jangan mengklaim jaminan exactly-once eksternal bila provider tidak mendukungnya.

Integrasikan cek saldo, reservasi/debit, settlement, dan refund sesuai model kredit existing. Tetapkan aturan untuk kegagalan, status tidak pasti, dan hasil parsial. Jangan refund hanya karena browser timeout, atau otomatis berpindah provider/model yang mengubah biaya dan hasil.

## 5. Standar desain Studio

### Ruang kerja, bukan halaman promosi

Gunakan input dan hasil sebagai pusat halaman. Pada desktop, sediakan panel konfigurasi yang jelas, area hasil utama yang lapang, dan riwayat yang mudah diakses tanpa menguasai layar. Pada mobile, susun ulang alur; jangan mengecilkan tiga kolom desktop hingga tidak terbaca.

Gunakan satu kerangka Studio bersama, tetapi tampilkan komponen sesuai media: preview gambar, pemutar video, kontrol audio, atau viewer 3D. Hindari menyalin lima halaman beserta logikanya hanya untuk mengganti judul dan ikon.

Jangan menambahkan hero besar, statistik dekoratif, kartu fitur promosi, atau panel kosong pengisi ruang. Empty state harus memberi petunjuk tindakan yang spesifik terhadap mode yang dipilih.

### Identitas xsuper.ai

Gunakan logo final yang tersedia tanpa menggambar ulang atau mengubah proporsinya. Pertahankan arah merah–charcoal–putih dengan permukaan netral dominan. Merah digunakan untuk penekanan terpilih, bukan mewarnai seluruh elemen.

Gunakan satu keluarga ikon dan standar konsisten untuk tipografi, spacing, radius, border, serta hierarki tombol. Gunakan ulang design tokens yang baik di proyek; rapikan ketidakkonsistenan sebelum menambahkan gaya baru.

Hindari glow berlebihan, gradient dekoratif pada setiap kartu, glassmorphism menyeluruh, robot stok, dan animasi latar yang tidak membantu pekerjaan. Status sukses, peringatan, dan error mempunyai teks/ikon pembeda; jangan hanya mengandalkan warna brand.

### Form dan interaksi

Tampilkan pengaturan penting terlebih dahulu, lalu pengaturan lanjutan. Label harus menjelaskan tindakan pengguna, bukan nama field API. Beri konteks batas file, rasio, durasi, jumlah output, dan pilihan yang relevan berdasarkan capability.

Ketika model atau mode berganti, pertahankan draft dan aset yang masih kompatibel. Tandai nilai yang perlu disesuaikan. Jangan membuang input tanpa penjelasan, dan jangan mengirim parameter tersembunyi yang tidak lagi berlaku.

Tampilkan biaya atau estimasinya dekat tombol Generate, termasuk satuan. Backend tetap menentukan biaya yang sah. Ketika biaya belum diketahui, jelaskan keterbatasannya; jangan menampilkan nol seolah gratis.

Tombol yang belum dapat digunakan harus memiliki alasan terlihat, misalnya “Tambahkan gambar awal”. Error perlu menyebut apa yang dapat diperbaiki tanpa membocorkan detail internal. Pertahankan input saat terjadi kegagalan.

Sediakan kondisi kosong, memuat, validasi gagal, upload berjalan/gagal, mengantre, memproses, memeriksa status, selesai, gagal, dan akses tidak tersedia. Batalkan generation hanya bila memang didukung; jangan menampilkan tombol palsu. Jangan membuat persentase generation atau estimasi waktu yang tidak mempunyai dasar.

### Animasi dan aksesibilitas

Gunakan animasi untuk feedback tombol, pergantian panel, upload, dan pergantian hasil. Hindari efek berulang yang bersaing dengan pekerjaan pengguna. Durasi awal dapat mengikuti token motion existing; evaluasi melalui penggunaan nyata, bukan mengejar banyaknya animasi.

Dukung keyboard, fokus yang terlihat, label form, dialog yang dapat ditutup, kontras memadai, dan pengumuman status untuk pembaca layar. Hormati `prefers-reduced-motion` dengan mengurangi atau meniadakan gerakan nonesensial. W3C membahas kemampuan menonaktifkan animasi interaksi yang tidak esensial. [R4]

Lazy-load komponen berat seperti viewer 3D dan riwayat media. Jangan memuat seluruh katalog, seluruh history, atau semua preview resolusi penuh pada kunjungan awal.

## 6. Admin provider dan model

Halaman awal admin provider menggunakan **kartu provider**. Mengklik kartu harus membuka **halaman detail provider tersendiri**, bukan hanya modal, accordion, atau perluasan kartu. Sesuaikan route dengan struktur repository.

Kartu menampilkan data nyata: nama, status konfigurasi/koneksi yang telah diketahui, jumlah model terkonfigurasi/aktif, dan tindakan yang relevan. Bedakan “belum diuji”, “terhubung”, “error”, dan “dinonaktifkan”. Jangan menyebut koneksi sehat tanpa pemeriksaan.

Desain mendukung pertumbuhan ke sekitar 10 provider tanpa mengarang provider aktif. Provider yang belum tersedia diberi status jujur atau tidak ditampilkan sesuai kebijakan produk.

Halaman detail memuat koneksi, daftar model, harga dan capability, serta aktivitas/error yang aman bagi admin berizin. Pengaturan admin minimum tersedia pada F0b; bulk import dan pengelolaan katalog diperluas pada F6b.

Tabel model mendukung pencarian, filter kategori/status, sorting, pagination, dan pengaturan kolom bila diperlukan. Bedakan ID publik dari ID provider. Tampilkan nama, operasi/media, harga jual, satuan, status aktivasi, revisi capability, dan status kompatibilitas. Field lanjutan dapat berada di halaman/detail model agar tabel tidak menjadi lautan kolom.

Harga modal dan margin hanya ditampilkan kepada peran berizin. Kredensial tidak ditampilkan kembali secara utuh. Catat perubahan konfigurasi penting beserta pelakunya. Sync tidak boleh menimpa harga jual dan label publik hasil kurasi admin tanpa peninjauan.

## 7. Persyaratan khusus per Studio

### F1 — Gambar

Dukung text-to-image dan operasi referensi/edit yang memang tersedia. Jumlah referensi, resolusi, rasio, format, serta jumlah output mengikuti capability. Sediakan preview, hasil terpilih, download, dan penggunaan ulang aset yang kompatibel. Jangan menambahkan mask editor atau fitur gambar lain bila endpoint dan komponen pendukungnya belum diimplementasikan.

### F2 — Video

Bedakan text-to-video, image-to-video, dan audio-to-video berdasarkan operasi aktual. Tampilkan input frame awal/akhir atau audio hanya bila sesuai model, lalu tegakkan kewajibannya. Sediakan preview referensi, durasi yang valid, pemutar hasil, dan status job persisten. Mode yang tidak didukung tidak boleh hanya diberi label lalu tetap memakai request mode lain.

### F3 — Audio

Pisahkan pengalaman speech dan music karena field dan hasilnya berbeda. Suno prompt-only merupakan target integrasi yang harus diverifikasi, bukan aturan seluruh Studio. Voice, bahasa, instrumental, lirik, atau parameter lain muncul hanya jika didukung. Sediakan playback, seek, durasi yang tersedia, dan download. Jangan menampilkan waveform buatan yang tidak berasal dari audio aktual.

### F4 — Avatar

Alur dasar adalah memilih/mengunggah foto dan audio untuk integrasi talking-avatar yang sudah diverifikasi. Validasi kedua aset sesuai capability dan tampilkan preview hasil video. Sediakan konfirmasi hak penggunaan foto/suara serta kebijakan penggunaan yang sesuai produk.

Alur “teks → suara → avatar” adalah perluasan terpisah yang bergantung pada F3. Jika masuk scope, tampilkan dua proses, biaya, dan kegagalannya secara jelas; simpan audio antara agar kegagalan avatar tidak memaksa pembuatan suara ulang.

### F5 — 3D

Dukung teks atau gambar sesuai model aktif yang terverifikasi. Viewer harus membaca format yang memang didukung dan menyediakan interaksi dasar yang berfungsi. Ketika format belum dapat dipreview, tampilkan penjelasan dan download yang tetap benar. Jangan menawarkan konversi/ekspor format yang tidak dihasilkan atau belum diimplementasikan.

Semua Studio memakai layanan aset, validasi, job, riwayat, dan informasi biaya yang sama. Tambahan fitur di luar scope dicatat terpisah; jangan menyelipkan video editor lengkap, timeline, atau workflow builder ke dalam proyek ini.

## 8. F6a/F6b — Ekspansi katalog tanpa mengganti arsitektur C

F6a menguji sampel model dengan bentuk input/output yang berbeda: prompt sederhana, referensi wajib, beberapa aset, pilihan parameter, struktur bertingkat, dan hasil non-gambar. Pilih sampel berdasarkan inventaris aktual, bukan angka acak. Uji kontrak lebih awal tanpa menunggu seluruh halaman Studio tersedia.

F6b menggunakan pipeline **discovery → ambil schema → normalisasi → validasi → uji kompatibilitas → review → publish**. Fal menyediakan Model Search dengan ekspansi `openapi-3.0`; gunakan sebagai sumber importer setelah integrasi diperiksa. OpenAPI sumber tetap harus diterjemahkan ke kontrak internal, bukan diasumsikan identik dengannya. [R5]

Simpan schema sumber, versi/hash yang relevan, capability ternormalisasi, override kurasi, dan laporan kompatibilitas dengan pemisahan yang jelas. Deteksi perubahan sebelum menerapkannya. Sync harus mendukung pagination, pembatasan request, retry terkontrol, dan eksekusi ulang tanpa duplikasi data.

Bedakan status **ditemukan, diimpor, membutuhkan penanganan, teruji, published, dan disabled**. Perubahan schema menghasilkan kandidat revisi baru, bukan langsung menimpa konfigurasi aktif. Unknown required fields atau output yang belum dapat diproses menjadi blocker publikasi.

Target seluruh katalog fal tetap dicatat sebagai target cakupan, tetapi publikasi hanya dilakukan untuk model yang benar-benar kompatibel dan diizinkan untuk akun/produk. Jangan menyatakan “1.000 model didukung” hanya karena metadata berhasil diimpor. Laporkan jumlah tiap status beserta model yang terblokir dan alasannya.

Selesaikan inventaris “sisa 13 Kinovi” berdasarkan ID aktual dengan pengujian per integrasi. Jangan menyamakan keberadaan model pada dokumentasi dengan keberhasilan eksekusi menggunakan akun proyek.

## 9. Urutan eksekusi dan pembatasan scope

Mulai dengan F0a. Bangun F0b bersama pembuktian satu alur F1 dari pemilihan model sampai hasil tersimpan. Jalankan F6a pada fase yang sama agar desain diuji terhadap variasi input sebelum terlanjur disalin ke banyak Studio.

Setelah alur acuan dan kontrak stabil, selesaikan F1 serta kembangkan F2/F3. Keduanya boleh paralel jika kapasitas tim memungkinkan; bila tidak, kerjakan berurutan tanpa menggandakan komponen bersama.

F4 upload-foto-plus-audio tidak menunggu fitur text-to-speech F3. F5 mengikuti kesiapan integrasi dan viewer. Jalankan F6b per kategori ketika Studio terkait siap, bukan menunggu seluruh produk selesai dan bukan mempublikasikan model sebelum Studio dapat menanganinya.

Jangan memperluas pekerjaan menjadi engine universal seluruh provider, penulisan ulang aplikasi, pergantian framework, atau billing baru. Bila menemukan kebutuhan perubahan besar, jelaskan masalah konkretnya dan usulkan perubahan paling kecil yang menyelesaikannya.

## 10. Kriteria penerimaan

| Skenario | Bukti yang harus ditunjukkan |
|---|---|
| **Generation nyata** | Input valid menghasilkan job nyata, status terpantau, hasil dapat dibuka, dan download sesuai media. |
| **Required input** | Request tanpa aset/parameter wajib ditolak frontend dan backend; tidak diteruskan untuk generation berbayar. |
| **Ganti model/mode** | Input kompatibel bertahan, ketidakcocokan dijelaskan, parameter tidak berlaku tidak ikut terkirim. |
| **Refresh / koneksi putus** | Job ditemukan kembali tanpa submit ulang dan tanpa hilangnya riwayat yang sudah tersimpan. |
| **Timeout / callback** | Status tidak pasti direkonsiliasi; event duplikat/tidak berurutan tidak merusak status maupun transaksi. |
| **Kredit** | Debit/reservasi/refund mengikuti aturan existing, konsisten, tercatat, dan tidak diproses dua kali. |
| **Keamanan akses** | Member tidak dapat membaca/mengubah job atau aset pengguna lain dan tidak menerima data internal admin. |
| **Upload** | Jenis/ukuran tidak valid ditolak; aset yang dikirim dapat dibaca provider; URL kedaluwarsa ditangani. |
| **Schema berubah** | Revisi baru tidak merusak job lama atau diam-diam mengubah harga/input submission yang sudah disiapkan. |
| **Admin** | Kartu membuka halaman provider tersendiri; data dan status nyata; perubahan model/harga dapat ditelusuri. |
| **Katalog** | Penambahan model kompatibel dilakukan lewat data/importer tanpa membuat halaman Studio baru; blocker dilaporkan. |
| **Tampilan & regresi** | Desktop/mobile, keyboard, reduced motion, empty/error/loading states, dan alur existing diuji. |

Pengujian mencakup unit test untuk resolver/mapping/validasi, contract test adapter dengan fixture representatif, integration test untuk aset/job/kredit, serta end-to-end test untuk alur utama dan kegagalan. Gunakan sarana pengujian yang sesuai dengan repository.

Bedakan tegas pengujian live, fixture/mock, dan bagian yang belum diuji. Eksekusi live berbayar mengikuti kredensial, lingkungan, dan batas biaya yang sudah diotorisasi; jangan meluncurkan uji massal berbayar tanpa batas. Jika akses provider tidak tersedia, laporkan blocker spesifik dan selesaikan pekerjaan independen yang masih dapat diuji. Jangan mengklaim end-to-end live berhasil berdasarkan mock.

## 11. Hasil serah-terima dan instruksi eksekusi

Serahkan implementasi kode, migration/configuration yang diperlukan, dokumentasi kontrak dan onboarding model, pemetaan perubahan utama, hasil pengujian, serta petunjuk rollout/rollback. Sertakan bukti visual untuk keadaan penting desktop/mobile dan laporan cakupan katalog berdasarkan ID model.

Laporan akhir harus memisahkan pekerjaan selesai, sebagian, terblokir, dan belum dikerjakan. Jelaskan penyebab blocker, efeknya, dan bukti yang tersedia. Jangan menyatakan seluruh F0–F6 selesai ketika yang selesai baru fondasi atau tampilan.

**Mulai dengan audit repository, kemudian implementasikan arsitektur C sesuai urutan di atas. Gunakan tabel sebagai pembagian pekerjaan dan seluruh ketentuan brief sebagai kriteria pelaksanaannya. Jangan kembali menawarkan A/B/C. Jangan berhenti pada mockup, skeleton, proposal, atau tombol yang belum terhubung ke perilaku nyata.**

---

## Referensi teknis

Rujukan berikut mendukung sebagian persyaratan; seluruh pilihan scope dan desain di atas adalah keputusan implementasi proyek, bukan kutipan dari sumber.

- [R1] OWASP — Input Validation Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html
- [R2] OWASP — File Upload Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html
- [R3] Kinovi — Uploading Assets: https://kinovi.ai/docs/uploads
- [R4] W3C — Understanding SC 2.3.3, Animation from Interactions: https://www.w3.org/WAI/WCAG22/Understanding/animation-from-interactions.html
- [R5] fal — Model Search: https://fal.ai/docs/platform-apis/v1/models

Dokumentasi diakses 21 September 2026. Periksa kembali perilaku endpoint yang digunakan saat implementasi.
