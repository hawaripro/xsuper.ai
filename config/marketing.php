<?php

return [
    'name' => 'XSuper.ai',
    'url' => 'https://xsuper.dev',
    'description' => 'Platform AI untuk berkarya, belajar, dan membangun. Akses pilihan model AI premium, akun pribadi, dan API untuk developer.',
    'hero' => [
        'headline' => "Satu ruang.\nSemua kemungkinan.",
        'description' => 'Berkarya dengan Chat, Studio & API memakai saldo/token sesuai pemakaian. Pilih langganan untuk bonus token media, Saldo AI, dan penyimpanan selama aktif.',
        'primary_action' => ['label' => 'Temukan paketmu', 'url' => '/pricing'],
    ],
    'support' => ['phone' => '6287786866648', 'display' => '+62 877-8686-6648'],
    'models' => [
        ['id' => 'anthropic', 'name' => 'Anthropic', 'maker' => 'Claude by Anthropic', 'logo' => '/brands/ai/anthropic.svg', 'description' => 'Claude untuk menulis, menganalisis, dan berdiskusi tentang kode.'],
        ['id' => 'chatgpt', 'name' => 'ChatGPT', 'maker' => 'OpenAI', 'logo' => '/brands/ai/chatgpt.svg', 'description' => 'Keluarga GPT untuk percakapan, kreativitas, dan pengembangan.'],
        ['id' => 'gemini', 'name' => 'Gemini', 'maker' => 'Google', 'logo' => '/brands/ai/gemini.svg', 'description' => 'Pilihan model Google untuk mengeksplorasi informasi dan ide.'],
        ['id' => 'deepseek', 'name' => 'DeepSeek', 'maker' => 'DeepSeek', 'logo' => '/brands/ai/deepseek.svg', 'description' => 'Eksplorasi penalaran dan pemrograman dengan DeepSeek.'],
        ['id' => 'zai', 'name' => 'GLM (Z.ai)', 'maker' => 'Z.ai', 'logo' => '/brands/ai/zai.svg', 'description' => 'Keluarga GLM dari Z.ai untuk berbagai pekerjaan berbasis AI.'],
        ['id' => 'kimi', 'name' => 'Kimi', 'maker' => 'Moonshot AI', 'logo' => '/brands/ai/kimi.svg', 'description' => 'Kimi dari Moonshot AI untuk percakapan dan eksplorasi konteks.'],
    ],
    'payments' => [
        ['id' => 'qris', 'name' => 'QRIS', 'logo' => '/brands/qris.svg'],
        ['id' => 'bca', 'name' => 'BCA', 'logo' => '/brands/bca.svg'],
        ['id' => 'mandiri', 'name' => 'Mandiri', 'logo' => '/brands/mandiri.svg'],
        ['id' => 'bri', 'name' => 'BRI', 'logo' => '/brands/bri.svg'],
        ['id' => 'gopay', 'name' => 'GoPay', 'logo' => '/brands/gopay.svg'],
        ['id' => 'shopeepay', 'name' => 'ShopeePay', 'logo' => '/brands/shopeepay.svg'],
    ],
    'audiences' => [
        ['id' => 'dev', 'title' => 'Developer & pembangun produk', 'description' => 'Diskusikan kode, cari pendekatan baru, atau integrasikan API ke aplikasi dan alat pengembanganmu.', 'prompt' => 'Bantu uraikan bug ini dan pilihan perbaikannya.'],
        ['id' => 'student', 'title' => 'Mahasiswa & pembelajar', 'description' => 'Pahami konsep, rangkum bacaan, dan susun kerangka belajar. Tetap periksa sumber dan kerjakan dengan pemahamanmu sendiri.', 'prompt' => 'Jelaskan konsep ini dengan contoh yang sederhana.'],
        ['id' => 'creator', 'title' => 'Kreator & storyteller', 'description' => 'Mulai dari ide konten, draft naskah, caption, sampai arah visual. Temukan sudut yang terasa seperti dirimu.', 'prompt' => 'Buat tiga sudut cerita untuk ide konten ini.'],
        ['id' => 'biz', 'title' => 'Freelancer, UMKM & bisnis', 'description' => 'Rapikan proposal, siapkan komunikasi pelanggan, dan susun ide pemasaran yang relevan untuk pekerjaanmu.', 'prompt' => 'Bantu susun proposal yang singkat dan meyakinkan.'],
        ['id' => 'researcher', 'title' => 'Peneliti & penjelajah ide', 'description' => 'Bandingkan pendekatan model, eksplorasi pertanyaan riset, dan rapikan temuan menjadi langkah berikutnya.', 'prompt' => 'Apa asumsi yang perlu diuji dari gagasan ini?'],
    ],
    'faqs' => [
        ['question' => 'Apa itu XSuper.ai?', 'answer' => 'XSuper.ai menyediakan pilihan model AI dalam satu akun pribadi. Chat dan API memakai Saldo AI; Studio memakai token media. Akun aktif dan terverifikasi dapat memakai fitur sesuai izin serta saldo yang tersedia, tanpa wajib berlangganan. Model pihak ketiga tetap milik pembuat masing-masing.'],
        ['question' => 'Model apa saja yang bisa digunakan?', 'answer' => 'Pilihan layanan mencakup keluarga Claude dari Anthropic, GPT dari OpenAI, Gemini, DeepSeek, GLM dari Z.ai, dan Kimi. Model yang tersedia dapat berubah dan mengikuti izin akun. Konfirmasikan kebutuhan model tertentu kepada tim sebelum membeli.'],
        ['question' => 'Bisa dipakai di VSCode, Cursor, atau aplikasi sendiri?', 'answer' => 'Akses API XSuper.ai menggunakan format yang kompatibel dengan OpenAI API melalui https://api.xsuper.dev/v1. Kamu dapat menghubungkannya ke aplikasi atau alat developer yang mendukung custom endpoint, sesuai akses API pada akunmu. Jangan simpan kunci API rahasia di kode frontend.'],
        ['question' => 'Bagaimana cara mendaftar dan membayar?', 'answer' => 'Pilih paket lalu hubungi tim melalui WhatsApp untuk pendaftaran dan konfirmasi pembayaran. Metode yang tersedia meliputi QRIS, BCA, Mandiri, BRI, GoPay, dan ShopeePay. Aktivasi atau penambahan durasi dilakukan setelah pembayaran diverifikasi dan mendapat persetujuan admin.'],
        ['question' => 'Apa manfaat paket langganan?', 'answer' => 'Langganan memberi bonus token media, Saldo AI, dan tambahan penyimpanan selama aktif. Bonus saldo dan token diberikan sekali setelah pembayaran disetujui. Penggunaan Chat, Studio & API tetap ditagih sesuai pemakaian; paket bukan akses tanpa batas. Bonus penyimpanan memakai paket aktif terbesar, bukan dijumlahkan.'],
        ['question' => 'Bagaimana keamanan akun dan percakapan saya?', 'answer' => 'Setiap pengguna memiliki akun pribadi. Sistem memakai sesi login, kontrol izin, serta pencatatan perangkat untuk menjalankan dan melindungi layanan. Prompt dan bahan yang dikirim untuk diproses AI dapat diteruskan ke penyedia model terkait. Baca Privacy Policy sebelum mengirim informasi pribadi atau rahasia.'],
        ['question' => 'Apakah ada refund atau penggantian akses?', 'answer' => 'Paket baru yang belum dipakai dapat diajukan untuk refund penuh dalam 24 jam pertama. Untuk kendala teknis seperti akses berakhir lebih awal, limit penyedia, atau error token, hubungi tim untuk pemeriksaan dan penanganan berupa penggantian akses atau refund pro-rata. Ketentuan selengkapnya ada di Refund Policy.'],
        ['question' => 'Apa yang terjadi ketika masa aktif berakhir?', 'answer' => 'Hanya bonus penyimpanan langganan yang berakhir. Saldo AI dan token media tetap dapat digunakan selama akun aktif dan memiliki izin fitur. Kamu dapat mengisi saldo/token atau memperpanjang langganan melalui dashboard.'],
    ],
    'policies' => [
        'privacy-policy' => [
            'title' => 'Privacy Policy',
            'description' => 'Kebijakan privasi XSuper.ai: data akun, percakapan, perangkat, pembayaran, dan pilihan pengguna.',
            'sections' => [
                ['heading' => 'Ruang lingkup', 'paragraphs' => ['Kebijakan ini menjelaskan pemrosesan data saat kamu menggunakan situs, akun, Chat AI, dan akses API XSuper.ai. Pemrosesan diperlukan untuk menyediakan layanan, menjaga akun, serta menangani bantuan dan pembayaran.'], 'items' => []],
                ['heading' => 'Data yang diproses', 'paragraphs' => ['Data bergantung pada fitur yang digunakan. Jangan mengirim kata sandi, kunci rahasia, data sensitif, atau informasi pihak lain yang tidak berhak kamu bagikan.'], 'items' => ['Data akun seperti nama, alamat email, identitas login Google jika digunakan, peran, izin, dan masa aktif.', 'Prompt, percakapan, pilihan model, respons, dan bahan yang kamu kirim melalui fitur AI; fitur media juga dapat memproses prompt, pengaturan, status, dan tautan hasil.', 'Informasi perangkat dan penggunaan seperti alamat IP, user agent, fingerprint perangkat, aktivitas terakhir, model, token, dan catatan permintaan.', 'Catatan paket, status pembayaran atau pesanan, penambahan durasi, dan komunikasi bantuan.']],
                ['heading' => 'Tujuan penggunaan', 'paragraphs' => ['Kami menggunakan data untuk autentikasi, menyediakan fitur yang kamu minta, menampilkan riwayat, mengelola izin serta perangkat, menghitung penggunaan, memproses pesanan, menyelesaikan kendala, dan menjaga keamanan layanan.'], 'items' => []],
                ['heading' => 'Penyedia layanan dan model AI', 'paragraphs' => ['Prompt, pesan, dan bahan yang diperlukan untuk menjawab permintaan dapat diproses oleh penyedia model atau layanan teknis yang mendukung XSuper.ai. Kebijakan pemrosesan penyedia tersebut juga dapat berlaku. Nama model pada layanan tidak berarti model itu dibuat oleh XSuper.ai.', 'Login Google, layanan hosting, jaringan, serta penyedia pembayaran memproses informasi sesuai fungsinya. Situs dapat menggunakan analitik Umami jika diaktifkan untuk memahami penggunaan situs. Kebijakan ini tidak menjanjikan bahwa semua penyedia memiliki kebijakan pelatihan atau retensi yang sama.'], 'items' => []],
                ['heading' => 'Cookie, sesi, dan keamanan', 'paragraphs' => ['Sesi dan cookie membantu mempertahankan login serta perlindungan permintaan. Pencatatan perangkat membantu pengendalian akses. Kami menerapkan kontrol teknis yang sesuai dengan layanan, tetapi tidak ada sistem yang dapat menjamin keamanan mutlak.', 'Lindungi kredensial dan kunci API. Gunakan perangkat tepercaya dan hubungi tim bila menduga akun diakses tanpa izin.'], 'items' => []],
                ['heading' => 'Penyimpanan dan penghapusan', 'paragraphs' => ['Data disimpan selama diperlukan untuk menjalankan layanan, menangani pesanan, keamanan, dan kewajiban yang berlaku. Kebutuhan penyimpanan berbeda untuk tiap jenis data; tidak ada jangka retensi khusus yang dijanjikan di halaman ini.', 'Kamu dapat menghapus percakapan melalui fitur riwayat yang tersedia. Permintaan akses, koreksi, atau penghapusan data akun dapat diajukan melalui WhatsApp. Tim mungkin perlu memverifikasi identitas dan menjelaskan bila data tertentu masih diperlukan untuk kewajiban atau keamanan.'], 'items' => []],
                ['heading' => 'Pertanyaan dan perubahan kebijakan', 'paragraphs' => ['Hubungi tim XSuper.ai melalui WhatsApp untuk pertanyaan privasi atau permintaan terkait data. Kebijakan dapat diperbarui ketika layanan atau kebutuhan pemrosesan berubah. Tinjau halaman ini sebelum menggunakan fitur yang memproses informasi penting.'], 'items' => []],
            ],
        ],
        'terms-of-service' => [
            'title' => 'Terms of Service',
            'description' => 'Ketentuan penggunaan XSuper.ai mengenai akun, akses, pembayaran, model AI, dan tanggung jawab pengguna.',
            'sections' => [
                ['heading' => 'Penggunaan layanan', 'paragraphs' => ['Dengan menggunakan XSuper.ai, kamu menyetujui ketentuan pada halaman ini dan kebijakan terkait. XSuper.ai menyediakan platform untuk mengakses fitur AI; model pihak ketiga tetap dimiliki dan dikembangkan oleh pembuatnya.'], 'items' => []],
                ['heading' => 'Akun dan akses', 'paragraphs' => ['Berikan informasi akun yang benar dan lindungi kredensialmu. Akun harus aktif dan terverifikasi; penggunaan mengikuti izin fitur dan saldo/token yang tersedia. Langganan yang berakhir tidak mengunci saldo atau akses fitur.', 'Pembatasan perangkat dan pemeriksaan login dapat diterapkan. Perangkat tambahan atau perubahan tertentu dapat memerlukan persetujuan admin. Jangan membagikan akun atau kunci API secara tidak sah.'], 'items' => []],
                ['heading' => 'Paket dan pembayaran', 'paragraphs' => ['Harga, durasi, dan bonus pada pesanan menjadi acuan paket yang dipilih. Bonus diberikan setelah verifikasi pembayaran dan persetujuan admin. Chat, Studio & API memakai saldo/token sesuai pemakaian.', 'Langganan menambah durasi bonus penyimpanan. Saldo dan token tidak kedaluwarsa bersama langganan. Pengembalian dana mengikuti Refund Policy, bukan janji pengembalian tanpa syarat.'], 'items' => []],
                ['heading' => 'Penggunaan yang diperbolehkan', 'paragraphs' => ['Gunakan layanan sesuai hukum, hak pihak lain, serta ketentuan model yang digunakan. Tim dapat membatasi akses yang menyalahgunakan layanan atau mengancam keamanan.'], 'items' => ['Jangan menggunakan layanan untuk penipuan, akses tanpa izin, malware, atau kegiatan melanggar hukum.', 'Jangan mengirim data yang tidak berhak kamu proses atau membagikan rahasia pihak lain.', 'Jangan menghindari pembatasan akun, perangkat, atau perlindungan teknis.', 'Jaga kunci API sebagai rahasia dan jangan menanamkannya pada aplikasi publik di sisi browser.']],
                ['heading' => 'Hasil AI dan hak atas konten', 'paragraphs' => ['Respons AI dapat keliru, tidak lengkap, atau tidak sesuai konteks. Kamu bertanggung jawab memeriksa fakta, kode, referensi, serta kelayakan hasil sebelum digunakan. Hasil AI bukan pengganti nasihat profesional yang diperlukan.', 'Kamu tetap bertanggung jawab atas hak dan izin bahan yang dikirim. Hak atas hasil dapat dipengaruhi ketentuan penyedia model dan hukum yang berlaku; XSuper.ai tidak menjamin keunikan atau bebas pelanggaran untuk setiap hasil.'], 'items' => []],
                ['heading' => 'Ketersediaan dan perubahan', 'paragraphs' => ['Pilihan model, fitur, serta kapasitas dapat berubah mengikuti penyedia dan kebutuhan operasional. Kami tidak menjanjikan layanan tanpa gangguan atau semua model selalu tersedia. Kendala akses dapat dilaporkan untuk diperiksa sesuai kebijakan bantuan dan refund.', 'Perubahan ketentuan ditampilkan pada halaman ini. Untuk pertanyaan, hubungi tim melalui WhatsApp sebelum melanjutkan pembelian atau penggunaan.'], 'items' => []],
            ],
        ],
        'refund-policy' => [
            'title' => 'Refund Policy',
            'description' => 'Kebijakan refund dan penggantian akses XSuper.ai untuk paket baru serta kendala teknis.',
            'sections' => [
                ['heading' => 'Paket baru yang belum digunakan', 'paragraphs' => ['Untuk paket baru yang belum dipakai, kamu dapat mengajukan refund penuh dalam 24 jam pertama setelah pembelian. Hubungi tim melalui WhatsApp dan sertakan informasi akun serta bukti transaksi agar status pembelian dan pemakaian dapat diperiksa.'], 'items' => []],
                ['heading' => 'Kendala teknis', 'paragraphs' => ['Jika akses berakhir sebelum waktunya, terkena limit dari penyedia model, atau mengalami error token, laporkan kepada tim. Setelah pemeriksaan, penanganan dapat berupa penggantian akses atau refund pro-rata sesuai bagian layanan atau masa aktif yang terdampak.', 'Penggantian akses dan refund tidak otomatis terjadi ketika tombol laporan ditekan. Tim perlu mencocokkan kendala, status akun, dan transaksi untuk menentukan penanganan yang sesuai.'], 'items' => []],
                ['heading' => 'Cara mengajukan', 'paragraphs' => ['Kirim pengajuan ke WhatsApp resmi XSuper.ai. Untuk mempercepat pemeriksaan, siapkan informasi berikut tanpa membagikan kata sandi atau kunci API.'], 'items' => ['Nama atau email akun XSuper.ai.', 'Paket yang dibeli, waktu transaksi, dan bukti pembayaran.', 'Penjelasan kendala beserta waktu kejadian; sertakan tangkapan layar yang tidak memuat rahasia.', 'Penjelasan apakah paket belum digunakan atau pengajuan berkaitan dengan kendala teknis.']],
                ['heading' => 'Pemeriksaan dan penyelesaian', 'paragraphs' => ['Tim akan memeriksa kelayakan berdasarkan kondisi paket baru yang belum dipakai atau kendala teknis di atas. Bila refund pro-rata berlaku, dasar perhitungan dan metode pengembalian dikonfirmasikan saat penanganan.', 'Waktu pemeriksaan dan penerimaan dana dapat bergantung pada kelengkapan informasi serta metode pembayaran. Halaman ini tidak menetapkan SLA pengembalian tertentu. Simpan komunikasi konfirmasi sampai masalah selesai.'], 'items' => []],
                ['heading' => 'Bantuan sebelum membeli', 'paragraphs' => ['Tanyakan kompatibilitas alat developer, model yang dibutuhkan, dan izin fitur sebelum pembayaran. Kebijakan ini tidak mengubah hak konsumen yang berlaku menurut hukum. Hubungi tim bila membutuhkan penjelasan mengenai kasusmu.'], 'items' => []],
            ],
        ],
    ],
];
