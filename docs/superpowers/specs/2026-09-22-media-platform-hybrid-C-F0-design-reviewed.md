# XSuper.ai Media Platform — Architecture C (Hybrid) — F0 Foundation Design

**Status:** Revisi desain berdasarkan dokumen sumber dan hasil audit percakapan. Verifikasi repository pada checkpoint awal tetap wajib.

**Scope:** F0a (konfirmasi hasil audit) + F0b (media core minimum) + F6a (schema-compatibility probe).

**Keputusan arsitektur:** **C — Hybrid.**

**Cara eksekusi F0b:** **Inline — task-by-task, TDD, checkpoint berbasis dependensi, dan review dua tahap.**

**Dokumen sumber:** `2026-09-22-media-platform-hybrid-C-F0-design.md` — tanggal yang tercantum pada sumber: 2026-09-22.

**Acuan kebutuhan produk:** `xsuper_ai_brief_final_hybrid_C.md`, sebagaimana ditetapkan dokumen sumber. Dokumen ini memperinci fondasi F0, bukan mengganti brief produk atau memperluasnya menjadi seluruh F1–F6.

## Dasar dan batas review

Bagian 1 merangkum keadaan repository **yang dilaporkan dokumen sumber**. Review ini tidak melakukan pemeriksaan kode, menjalankan test suite, mengakses provider, atau memverifikasi production. Karena itu, klaim tersebut harus dikonfirmasi pada commit yang benar sebelum dipakai sebagai dasar perubahan.

Bagian 2–8 mempertahankan rancangan C dan memasukkan koreksi audit sebagai **persyaratan desain**, bukan sebagai klaim bahwa fitur tersebut sudah tersedia. Bagian 9 menetapkan eksekusi Inline. Perubahan material terhadap sumber dicatat pada bagian 10.

> Bangun C sejak awal: satu kontrak capability, satu resolver, adapter yang memakai kembali protocol existing, layanan aset terkontrol, dan koordinasi job bersama. Capability tersimpan dan capability hasil derivasi legacy adalah dua sumber dalam arsitektur yang sama. Tidak ada tahap mengganti A menjadi C atau mengganti C menjadi B.

---

## 1. Audit result (F0a) — baseline dari dokumen sumber

### 1.1. Stack dan lingkungan yang dilaporkan

Sumber menyebut Laravel 12, React 19, Tailwind v4 SPA, serta Caddy → `php_fastcgi`. Lingkungan production disebut `xsuper.dev`. Ini adalah konteks audit sumber, bukan hasil pemeriksaan ulang lingkungan pada revisi ini.

### 1.2. Credit/billing

Sumber melaporkan bahwa media memakai token integer melalui `MediaTokenBillingService::reserve/settle/release`, `UserToken.balance`, dan `TokenReservation`. Reservasi memiliki keunikan `user_id + reference_id`, status `reserved/settled/released`, serta payload berisi `reference_id`, `amount_tokens`, `unit_tokens`, dan `billing_mode`.

Harga satuan media dilaporkan berasal dari `AiModelProfile.token_cost`, bukan `UsageRate`. API/chat memakai ledger micro-USD terpisah melalui `Wallet`, `UsageBillingService`, dan `UsageRate`.

**Implikasi F0b:** pertahankan mekanisme tersebut. Kesamaan media core tidak mengizinkan pemindahan ledger. Dokumen sumber belum merinci billing setiap endpoint `/v1`; pemetaan tersebut harus diperiksa, bukan ditebak dari prefix URL.

### 1.3. Auth/access

Sumber melaporkan session-cookie melalui Fortify dan grup `web` pada `/api`, tanpa Sanctum. Akses admin memakai pemeriksaan `role === 'admin'`, middleware `admin`, dan `admin.ip`. Izin member tersimpan dalam `users.permissions`, termasuk `image_generator`, `video_generator`, dan `audio_generator`; penegakan melibatkan `CheckExpiry` berbasis path dan `User::hasPermission()`.

External `/v1/*` dilaporkan memakai Bearer API key melalui `VerifyApiKey`.

**Implikasi F0b:** endpoint bersama harus mempertahankan izin per operasi. Mengganti path tidak boleh menghilangkan pembatasan fitur, masa berlaku akun, atau cakupan API key.

### 1.4. Assets

Sumber menyatakan hasil media memakai `Storage::disk('local')` di `storage/app/private` dan dilayani melalui controller berautentikasi dengan pemeriksaan admin/pemilik. `VideoReferenceStore` bersifat private dan memiliki `dataUri()` untuk pengiriman base64 ke fal.

Disk `public` dan `s3`, serta konfigurasi `storage:link`, dilaporkan tersedia, tetapi pengiriman melalui public/signed URL belum dipakai pada alur tersebut.

**Implikasi F0b:** pertahankan aset sumber dan hasil sebagai private. Ketersediaan konfigurasi public disk bukan keputusan untuk mempublikasikan file pengguna.

### 1.5. Studios/frontend

Shared kit yang dilaporkan berada di `resources/js/components/studios/`: `StudioUI.jsx`, `studios.css`, `useMediaStudio.js`, dan `AudioPlayer.jsx`. Token global `--ui-*` memakai brand `#ef4444`, sedangkan token Studio `--studio-*` memakai action `#ba2943`.

Sumber juga melaporkan sistem motion existing, `.dark` melalui `ThemeContext`, serta registry inline SVG `SidebarIcons` dan `StudioIcon`. Penambahan Studio melibatkan endpoint map, page, route, navigasi, ikon, dan kelas warna Studio.

**Implikasi F0b:** gunakan shared kit existing. Polling yang sudah ada belum membuktikan integrasi capability atau deteksi revisi harga. Perubahan frontend minimum diperbolehkan untuk acceptance; redesign, animasi lengkap, dan renderer lintas Studio tetap F1 dan tahap berikutnya.

### 1.6. Admin

Sumber melaporkan `AICatalog.jsx` sudah menampilkan kartu provider yang membuka route `/admin/ai/:providerId` menuju `ProviderDetail.jsx`. Tab existing mencakup Model & Harga, Koneksi, Pengaturan, dan Aktivitas. `ModelBulkTable.jsx` memiliki pencarian, filter, client pagination, serta bulk/inline edit.

Pemisahan payload dilaporkan sudah ada melalui `MediaModelConfig::publicModel` dan `AiCatalogController::modelPayload/adminPayload`.

Gap yang dicatat sumber: sorting kolom, penampilan `last_error`, default pricing media, kategori avatar/3D, server pagination katalog, dan gating provider/media yang masih memakai protocol string.

**Implikasi F0b:** jangan membangun ulang kartu dan halaman provider. Tambahkan pengaturan minimum yang diperlukan fondasi. Sorting, pagination katalog besar, dan perluasan kategori tetap mengikuti scope F1–F6b, kecuali terbukti menghalangi proof-of-flow.

### 1.7. fal discovery

Sumber melaporkan Model Search API fal menyediakan pencarian/listing, cursor pagination, dan ekspansi `openapi-3.0`. Integrasi existing dilaporkan masih memakai curated map sembilan model dan belum mempunyai importer umum.

Sumber juga mencatat asset URL fields pada schema yang diperiksa tidak selalu mempunyai petunjuk format yang memadai. **Jangan menggeneralisasi pengamatan itu ke seluruh katalog.** F6a harus menyimpan sampel schema, ID model, dan hasil mapping yang dapat diperiksa.

Heuristik nama/title/description/examples hanya menghasilkan kandidat role. Mapping yang belum pasti membutuhkan kurasi; ketidakjelasan required input merupakan blocker publikasi.

### 1.8. Bukti yang harus ditambahkan sebelum implementasi core

Pada checkpoint CP0, catat commit/branch yang diperiksa, lokasi file dan simbol relevan, baseline pengujian, serta hasil pemetaan endpoint. Untuk setiap klaim penting, bedakan `terverifikasi`, `berbeda dari sumber`, dan `belum terverifikasi`.

Pemetaan endpoint minimum mencakup: metode/path, controller/service, jenis autentikasi, izin operasi, model yang dirutekan, ledger, satuan harga, dan perilaku error existing. Jangan mengisi bagian yang belum diketahui menggunakan asumsi.

---

## 2. F0 goal & non-goals

### 2.1. Goal F0b

Perkenalkan fondasi bersama C dan buktikan **satu alur generation gambar nyata** melalui frontend, backend, capability, adapter, database, aset, dan kredit. Gunakan kembali billing, autentikasi, storage, serta protocol existing yang masih layak.

Pilih model dan operasi dari integrasi yang benar-benar tersedia. Utamakan operasi gambar berbasis referensi jika tersedia agar satu alur juga menguji upload dan pengiriman input ke provider.

Jika proof utama hanya dapat memakai text-to-image, uji layanan referensi secara terpisah dan laporkan tingkat pembuktiannya. Keberhasilan text-to-image tidak boleh dilaporkan sebagai bukti bahwa reference upload telah berhasil end-to-end.

### 2.2. Non-goals

F0b tidak mencakup redesign penuh Studio Gambar, Studio baru F2–F5, viewer 3D, avatar dual-upload UI, full fal import F6b, atau engine universal seluruh provider. F6a berjalan sebagai probe kompatibilitas, bukan halaman produk atau import massal.

Tidak ada penulisan ulang billing/auth/storage, pergantian stack, kewajiban microservices, perubahan desain brand, atau tuning production yang tidak dibutuhkan fondasi.

### 2.3. Batas kompatibilitas

Pertahankan model ID publik, harga existing, saldo, riwayat, autentikasi, dan kontrak API yang masih digunakan. Terapkan perubahan secara additive atau melalui jalur kompatibilitas yang diuji.

Core ditujukan untuk dipakai lintas entrypoint, tetapi penyambungan endpoint dilakukan bertahap. Setiap path yang masuk core menggunakan resolver dan validator yang sama. Jangan mengklaim seluruh endpoint sudah dimigrasikan bila F0b baru membuktikan pilot gambar.

Jangan menambahkan endpoint media `/v1` baru hanya untuk memenuhi kalimat “shared flow”. Inventaris existing menentukan integrasi yang diperlukan.

---

## 3. Core contracts

### 3.1. MediaCapability — internal, versioned

Capability berlaku per **model + operation**, bukan hanya per model. Pertahankan pemisahan versi format dan revisi konfigurasi model.

| Komponen | Kontrak minimum |
|---|---|
| Identitas | `contract_version`, `model_public_id`, `operation`, dan referensi/revisi capability efektif. |
| Hasil | `output_kind`: `image`, `video`, `audio`, atau `model3d`. |
| `inputs[]` | `key` stabil, `role`, `type`, `required`, `required_when` bila didukung, cardinality, dan constraints. |
| `params[]` | `name` stabil, `type`, aturan wajib/kondisional, default bila ada, opsi/rentang, satuan, dan constraints yang relevan. |
| Aturan | Deklaratif, tervalidasi, dan hanya memakai subset yang didukung; tidak ada JavaScript/ekspresi bebas dari database. |

Contoh operasi tetap mengikuti sumber: `text_to_image`, `image_edit`, `text_to_video`, `image_to_video`, `audio_to_video`, `talking_avatar`, `text_to_speech`, `music`, `text_to_3d`, dan `image_to_3d`. Pencantuman nama operasi tidak berarti integrasinya sudah tersedia.

Role aset tetap eksplisit, misalnya `image_ref`, `init_frame`, `end_frame`, `avatar_photo`, `speech_audio`, dan `reference_video`. `key` adalah identitas field; `role` menjelaskan maknanya. Jangan memakai label tampilan sebagai identifier request.

Sebelum coding lintas task, tetapkan semantik nilai tidak dikirim, `null`, string kosong, default, enum, serta konversi tipe. Array harus mempunyai tipe item dan batas jumlah yang diperlukan model. Struktur bertingkat dan kondisi lintas-field hanya diterima jika validator dan mapping mendukungnya.

Field asing pada kontrak baru ditolak. Legacy payload hanya diterjemahkan melalui mapping kompatibilitas yang eksplisit dan diuji; jangan meneruskan field mentah ke provider. Aturan yang tidak dapat direpresentasikan dicatat sebagai limitation, dan required field yang tidak didukung memblokir publikasi model.

Frontend baru menggunakan ID aset internal. Dukungan URL/data URI pada entrypoint existing, bila ada, dipertahankan atau dimigrasikan secara eksplisit dengan validasi dan pemeriksaan keamanan yang sesuai.

### 3.2. CapabilityResolver — satu otoritas

Gunakan satu `CapabilityResolver::resolve(model, operation)` untuk menghasilkan capability efektif.

- Model yang dimigrasikan menggunakan revisi published yang aktif. Perubahan definition menghasilkan revisi baru, bukan mengedit isi revisi yang dipakai job.
- Model legacy yang belum dimigrasikan memakai derivasi eksplisit dari `MediaModelConfig`/protocol config dengan bentuk kontrak yang sama.
- Published config yang rusak menghasilkan error konfigurasi; tidak ada silent fallback ke legacy. Model disabled atau operasi tidak diizinkan juga tidak boleh diaktifkan kembali melalui fallback.

Resolver mengembalikan identitas versi yang stabil dan asal definisinya untuk penggunaan internal. Capability publik tidak membocorkan provider/routing.

Setiap job baru melalui core harus mempunyai referensi revisi atau snapshot capability yang stabil. Untuk derivasi legacy, gunakan revisi hasil derivasi atau snapshot/hash beserta sumber konfigurasi yang cukup untuk audit. Pilih bentuk yang cocok dengan repository; jangan membuat job baru tanpa jejak hanya karena foreign key nullable.

Simpan pula routing/mapping identity dan harga yang dipakai pada submission. Jangan menyimpan salinan kredensial dalam snapshot job. Revisi yang dirujuk job tidak boleh dihapus ketika konfigurasi aktif diganti.

**Perubahan formulir/harga:** FE minimum membawa identitas capability dan penawaran/revisi harga server yang relevan. Backend memeriksa perubahan sebelum membuat request berbayar. Jika ada mismatch yang memengaruhi input/biaya, kembalikan respons yang meminta pembaruan/konfirmasi dan pertahankan draft. Jangan mempercayai nominal harga dari client.

Pemeriksaan dan reservasi harus memakai snapshot yang konsisten, bukan membaca harga berbeda pada dua langkah tanpa pengendalian. Untuk API existing, dokumentasikan kompatibilitas request tanpa field revisi; jangan memutus klien lama dengan field wajib baru tanpa keputusan migrasi.

### 3.3. Separation of concerns

**Capability** menentukan nilai dan input yang valid. **UI metadata** menentukan label, urutan, grouping, help text, widget, satuan, dan bagian advanced. **Adapter** menentukan request provider dari nilai ternormalisasi.

UI metadata tidak boleh mengubah kewajiban input. Schema provider adalah masukan normalisasi, bukan form mentah untuk member. Field teknis seperti `uploadedUrls` ditampilkan sebagai uploader berlabel sesuai role.

Renderer bersama adalah arah F1 dan Studio berikutnya. F0b cukup menghubungkan field alur gambar yang dipilih ke capability efektif tanpa membuat aturan wajib versi kedua yang terpisah.

### 3.4. MediaProviderAdapter — integration boundary

Bungkus `FalProtocol`, `KinoviProtocol`, dan protocol provider lain yang benar-benar ada setelah perilakunya dikunci lewat test. Nama `OpenAiAdapter` pada rencana sumber bukan bukti bahwa integrasi tersebut sudah lengkap; verifikasi sebelum mengaktifkannya.

Kontrak berikut adalah bentuk tanggung jawab yang harus disepakati. Nama tipe/metode dapat mengikuti repository, tetapi maknanya tidak boleh berbeda antarpelaksana task.

| Operasi | Input dan hasil yang wajib didefinisikan |
|---|---|
| `buildRequest` | Normalized inputs + resolved capability + konteks server → provider request internal. |
| `submit` | Provider request + konteks submission → tagged result: selesai langsung, diterima sebagai job, ditolak pasti, atau penerimaan belum pasti. |
| `pollStatus` | Referensi job provider yang valid → status ternormalisasi dan informasi hasil/error yang tersedia. |
| `parseResult` | Hasil provider → daftar output dengan jenis media, locator internal/server-only, dan metadata yang tersedia. |
| `normalizeError` | Error provider → kode/pesan publik aman, klasifikasi pemulihan, dan diagnostik tersanitasi untuk akses internal. |

Nyatakan support flags untuk sinkron/asinkron, polling, webhook, dan cancel berdasarkan implementasi aktual. Jangan menyediakan method palsu atau menganggap setiap provider bisa dipoll tanpa request ID.

Shared flow memiliki authz, resolver, validasi, koordinasi job/aset, serta pemanggilan billing sesuai entrypoint. Adapter tidak langsung mengubah saldo pengguna. Pengecualian model memakai mapping deklaratif atau handler teruji, bukan salinan seluruh adapter.

F0b mengaktifkan adapter yang diperlukan pilot dan jalur existing yang dimigrasikan. Provider lain boleh tetap melalui jalur lama yang teruji; laporkan cakupannya, bukan menandai wrapper kosong sebagai dukungan selesai.

### 3.5. Controlled asset service

Aset menggunakan ID internal dengan owner, media type, ukuran, MIME/signature yang tervalidasi, metadata relevan, lokasi storage, status retensi, dan waktu kedaluwarsa bila berlaku. Role pada request menjelaskan penggunaan aset; jangan menganggap satu file selamanya hanya boleh mempunyai satu role.

Validasi permission, kuota, ukuran, jenis file, dan isi/signature yang relevan sebelum dipakai. Terapkan constraints media dari capability. Backend memeriksa kepemilikan/izin sebelum mengeluarkan akses atau mengirim aset ke provider.

**Default F0b: storage private.** Hasil tetap mengikuti penyimpanan private existing. Referensi yang perlu diambil provider menggunakan signed/grant URL terkontrol atau upload provider sesuai integrasi yang diverifikasi. Jangan membuat salinan public permanen hanya karena disk tersebut sudah dikonfigurasi.

URL provider-fetch tidak boleh bergantung pada cookie sesi pengguna. Grant diberikan server setelah otorisasi pemilik dan dibatasi pada aset serta tujuan yang sesuai. Validasi signature/token, masa berlaku, status aset, dan revocation bila digunakan. Jangan memperluas route tersebut menjadi akses anonim ke seluruh aset.

Tetapkan TTL berdasarkan kebutuhan antre/fetch provider yang diperiksa, bukan angka asal. Penggantian delivery method atau URL tidak mengubah ID aset internal. URL signed maupun kredensial upload tidak boleh muncul pada payload member atau log mentah.

Retensi membutuhkan mekanisme cleanup yang berjalan dan teruji; `expires_at` saja bukan pelaksanaan penghapusan. Bedakan masa berlaku URL, masa simpan file, dan riwayat job. Jangan menghapus referensi yang masih diperlukan job aktif tanpa kebijakan yang jelas.

App-side fetch URL eksternal wajib dibatasi: protokol/tujuan yang diperbolehkan, penolakan jaringan internal, pemeriksaan redirect, dan batas pengambilan file. Endpoint provider dan callback tidak dapat ditentukan bebas oleh member.

Uji akses provider tanpa cookie, signature salah/kedaluwarsa, grant dicabut bila didukung, aset terhapus, dan akses lintas pengguna. Sediakan bukti delivery yang dipilih, bukan hanya keberhasilan upload ke storage lokal.

### 3.6. Persistent jobs, safe retry, consistent credit

#### Data dan lifecycle

Job menyimpan owner, input tervalidasi, snapshot/revisi capability, routing identity, pricing snapshot beserta satuan/ledger, referensi transaksi, `provider_request_id` bila tersedia, status, hasil, dan penanda rekonsiliasi yang diperlukan.

Upload status berbeda dari generation status. Job dapat dibuka kembali setelah refresh, navigasi, atau browser ditutup. Polling/webhook mengikuti dukungan provider; status browser tidak menjadi sumber kebenaran.

Tetapkan state transitions dan siapa yang boleh menjalankannya. Callback duplikat atau tidak berurutan tidak boleh menurunkan status final atau menggandakan transaksi. Verifikasi callback dengan mekanisme yang tersedia; bila callback tidak cukup dapat dipercaya, konfirmasi melalui API provider sebelum menetapkan hasil atau kredit.

#### Deduplikasi submission

Gunakan key deduplikasi yang terikat pada principal/entrypoint dan payload ternormalisasi. Key yang sama dengan payload yang sama mengembalikan job existing. Key yang sama dengan payload berbeda menghasilkan konflik, bukan job berbayar baru.

Fingerprint mengacu pada nilai dan ID aset stabil, bukan signed URL sementara. Operasi Generate baru yang disengaja memakai key baru. Jangan menganggap keunikan `TokenReservation` saja otomatis mencegah dua request provider.

Untuk jalur existing yang belum mengirim key, gunakan strategi kompatibilitas yang tercatat. Jangan menjanjikan jaminan retry yang belum didukung caller maupun provider.

#### Reservasi dan dispatch

Gunakan `MediaTokenBillingService` untuk jalur token yang memang memakainya. Pertahankan ledger entrypoint lain; pemilihan ledger berasal dari kebijakan server, bukan parameter pengguna.

Periksa transaction boundary existing untuk pembuatan job dan reservasi. Jangan menganggap keduanya otomatis atomik bila berada di koneksi/storage berbeda. Pilih transaksi/locking atau prosedur pemulihan yang sesuai, lalu uji proses yang berhenti pada batas tersebut.

Jika queue digunakan, worker tidak boleh menerima job sebelum data yang dibutuhkan committed. Tambahkan pemulihan untuk job tersimpan yang gagal didispatch. Mekanisme queue/dispatch harus mengikuti konfigurasi repository; tidak ada kewajiban menambah broker baru.

#### Ketidakpastian dan finalisasi

| Keadaan | Perilaku wajib |
|---|---|
| Submit diterima pasti | Simpan request ID/status dan lanjutkan pemantauan sesuai dukungan. |
| Submit ditolak pasti | Terapkan transisi gagal dan pelepasan/refund sesuai aturan kredit yang disetujui. |
| Timeout; penerimaan belum pasti | Jangan resubmit berbayar atau refund hanya karena timeout. Tandai untuk rekonsiliasi. |
| Tidak ada request ID | Jangan menganggap polling tersedia. Gunakan mekanisme lookup provider yang benar-benar ada atau jalur penanganan internal yang terdokumentasi. |
| Provider selesai; penyimpanan output gagal | Retry pengambilan/finalisasi output, bukan generation. Bedakan keberhasilan provider dari kesiapan hasil aplikasi. |
| Callback/event berulang | Transisi job dan mutasi kredit tetap idempotent. |

Istilah status seperti `submission_unknown` atau `finalizing` boleh disesuaikan dengan enum existing; keadaan tersebut tetap harus terwakili.

Tetapkan pemilik proses rekonsiliasi, interval/batas pemulihan yang dikonfigurasi, serta kebijakan reservasi yang tertahan. Jangan membiarkan ketidakpastian menggantung selamanya, tetapi jangan mengarang kepastian gagal atau aturan refund.

Simpan dan uji titik settlement yang dipakai produk. Jika kebijakan biaya saat finalisasi output gagal belum ada, catat sebagai keputusan yang harus diselesaikan sebelum live proof; jangan menetapkan charge/refund secara implisit di adapter.

Tidak ada silent fallback ke model/provider lain yang mengubah biaya atau hasil. Idempotensi internal tidak boleh disebut jaminan exactly-once provider bila dukungan eksternalnya belum diverifikasi.

---

## 4. Member vs admin response — enforced, not cosmetic

Member Studio melihat model **ID publik + title**, capability publik, harga jual token yang sah untuk jalur tersebut, status, hasil yang berizin, dan pesan error aman. Harga/satuan API existing mengikuti kontrak entrypoint-nya, bukan otomatis diubah menjadi token Studio.

Admin berizin dapat melihat provider dan informasi operasional yang diperlukan. Provider routing, endpoint internal, harga modal, credential, serta raw debug tidak dikirim ke member. Nama model tetap mengikuti konfigurasi publik; tidak ada janji bahwa asal teknologi mustahil ditebak.

Sanitasi juga berlaku untuk admin. `last_error` tidak boleh langsung diekspos sebagai raw exception: hapus secret, authorization header, signed URL, dan data sensitif yang tidak diperlukan. Gunakan referensi error/log untuk investigasi yang berizin.

Tegakkan akses pada endpoint, model/operasi, job, dan aset, bukan hanya komponen UI. Shared endpoint harus memeriksa permission berdasarkan operasi agar pembatasan `image_generator`/`video_generator`/`audio_generator` tidak hilang akibat perubahan path.

---

## 5. Planned changes (F0b) — concrete

### 5.1. Database dan migration

Nama dan bentuk fisik mengikuti schema existing setelah CP0. Jangan membuat tabel/kolom duplikat jika repository sudah menyimpan data yang diperlukan.

| Area | Perubahan yang dibutuhkan |
|---|---|
| Capability | Penyimpanan definition/UI metadata berversi per model+operation; revision immutable; identitas sumber/hash bila ada; penentu revisi published aktif yang deterministik. |
| Aset | `media_assets` atau perluasan struktur existing untuk owner, lokasi private, ukuran/jenis tervalidasi, metadata, retensi, dan grant/referensi pengiriman bila dibutuhkan. |
| Job | Referensi capability/snapshot, routing/mapping identity, pricing/ledger snapshot, referensi aset/transaksi, dan data deduplikasi/rekonsiliasi yang belum tersedia. |
| Harga | Pertahankan `AiModelProfile.token_cost` dan ledger existing. Tidak ada migration/seed yang menimpa harga jual kurasi. |

Untuk capability, satu tabel dengan satu row per revisi dapat digunakan bila cocok. Tidak wajib membuat tabel master+revision terpisah. Yang wajib adalah uniqueness yang benar, pemilihan revisi aktif, dan retensi revisi yang dirujuk job.

Status sumber `found/imported/needs_handling/tested/published/disabled` tetap menjadi vocabulary katalog. Untuk F0b, pisahkan makna lifecycle import, status publikasi, dan aktivasi model secara jelas; jangan mencampurkannya tanpa aturan transisi. Implementasi bulk katalog tetap F6b.

Foreign key nullable dapat diperlukan bagi historical jobs. Job baru melalui core tetap harus memiliki referensi/snapshot stabil. Migrasikan tabel pilot terlebih dahulu bila itu jalur paling aman; perluasan `video_jobs`/`audio_jobs` mengikuti penggunaan yang diuji, bukan perubahan serentak tanpa kebutuhan.

Rollback aplikasi harus tetap dapat membaca data lama yang relevan dan tidak menghapus revisi/aset/job baru. Utamakan migration additive. Jangan menjadikan destructive down-migration pada data production sebagai strategi pemulihan default.

### 5.2. Backend

Implementasikan value object capability, resolver, validator deklaratif minimum, kontrak adapter, wrapper protocol yang masuk scope, asset service yang menggeneralisasi kebutuhan `VideoReferenceStore`, dan shared submission/job coordination.

Pisahkan kebijakan entrypoint dari pekerjaan core: autentikasi, permission, ledger, serta serialisasi respons tetap sesuai jalurnya. Jangan merutekan API/chat ke token media hanya karena fungsi submit dibagikan.

Harga model existing dipertahankan. Default model baru hanya boleh menggunakan aturan yang mempunyai sumber, satuan, pembulatan, dan persetujuan yang jelas. Bila aturan belum ada, tandai harga belum dikonfigurasi dan cegah publikasi model baru untuk jalur berbayar; jangan membuat angka yang disebut “real default”.

Pisahkan log operasional yang aman dari respons pengguna. Catat correlation/job ID, tahap kegagalan, dan hasil rekonsiliasi tanpa mencatat secret atau seluruh media/prompt secara default.

### 5.3. Frontend minimum

Tidak ada redesign visual F0b. Namun implementasikan perubahan minimum bila diperlukan untuk membaca capability efektif pada alur pilot, menegakkan required input, membawa revisi/quote yang sesuai, dan menangani mismatch.

Pertahankan draft dan aset yang kompatibel saat error. Ganti model/mode tidak boleh mengirim parameter yang sudah tidak berlaku. Perubahan harga tidak boleh otomatis membuat submission baru.

Gunakan `useMediaStudio` dan shared components existing setelah diverifikasi. Status pending/polling existing tetap diuji melalui refresh. Jangan menambahkan persentase generation palsu atau animasi dekoratif sebagai pengganti status yang benar.

### 5.4. Admin minimum

Pertahankan kartu provider → halaman detail provider tersendiri. Sediakan pengaturan minimum untuk capability/harga/status model pilot dan diagnostik tersanitasi yang diperlukan.

Jangan membuat koneksi/provider palsu terlihat aktif. Perluasan sorting, server pagination, importer besar, dan pengaturan kategori baru tetap dicatat pada tahap katalog berikutnya kecuali terbukti menjadi dependensi langsung.

### 5.5. F6a — schema-compatibility probe

Gunakan sampel aktual dengan karakteristik berbeda: prompt sederhana, referensi wajib, multi-asset, pilihan/rentang parameter, dan struktur yang membutuhkan penanganan khusus. Jika fixture output non-gambar digunakan, bedakan pengujian kontrak dari implementasi Studio media tersebut.

Simpan schema sumber, ID model, metadata pengambilan yang tersedia, hasil normalisasi, override kurasi, dan keterbatasan. Heuristik role tidak mengaktifkan model secara otomatis. Required field yang belum dapat ditangani adalah blocker.

F6a tidak melakukan mass import berbayar, sync production otomatis, atau klaim dukungan seluruh katalog. Temuan F6a memperbaiki kontrak C tanpa menggantinya menjadi engine universal.

---

## 6. Proof-of-flow (F0b acceptance)

### 6.1. Tetapkan pilot sebelum implementasi alurnya

Catat ID model publik dan ID internal/provider yang berizin, operasi, input wajib, capability source, adapter, delivery method, ledger, harga, lingkungan uji, serta batas live run yang diotorisasi.

Pilih operasi gambar berbasis referensi bila tersedia. Jika memakai text-to-image, sertakan pengujian reference service terpisah dan tandai keterbatasan bukti live-nya. Jangan mengarang akses provider, ID model, atau budget.

### 6.2. Alur yang harus dibuktikan

Pengguna memilih model → menerima capability/harga → memberikan input → backend memeriksa revisi, izin, aset, dan saldo → membuat job/reservasi sesuai aturan → adapter submit → status terpantau → output tersimpan → settlement mengikuti kebijakan → hasil bisa dibuka kembali dan diunduh.

Frontend dan backend menolak required input yang hilang sebelum request generation berbayar. Refresh tidak menciptakan job baru. Member tidak menerima informasi provider internal; admin hanya menerima detail yang sesuai hak akses.

### 6.3. Lingkungan pembuktian

Suite otomatis penuh dijalankan pada lingkungan test terisolasi. Browser e2e dijalankan pada lingkungan pengujian yang sesuai dan menggunakan data test.

Production hanya untuk **smoke test terbatas** setelah mekanisme rollout/rollback siap dan eksekusinya diotorisasi. Dokumen ini bukan izin untuk deployment, pengujian destruktif, atau generation massal berbayar.

Jika staging tidak tersedia, tetap jalankan test terisolasi dan jelaskan keterbatasan lingkungan. Jangan menggantinya dengan suite destruktif pada production. Tanpa live run yang benar-benar dilakukan, status live proof tetap `belum diverifikasi`.

---

## 7. Testing — bukti, bukan hanya checklist

### 7.1. Lapisan pengujian

Unit test meliputi resolver, validasi, normalisasi, dan aturan harga/izin yang tersentuh. Contract test memakai fixture adapter representatif. Integration test mencakup aset, persistence, dispatch, job, serta billing. E2E mencakup alur gambar dan jalur kegagalan yang relevan.

Gunakan framework/test runner repository. Catat perintah yang benar-benar dijalankan, environment, hasil, serta kegagalan baseline yang sudah ada. Jangan mengubah ekspektasi test hanya agar suite hijau ketika kontrak belum dipenuhi.

### 7.2. Test matrix wajib

| ID | Skenario | Hasil yang harus dibuktikan |
|---|---|---|
| Q01 | Published dan legacy capability | Bentuk kontrak konsisten; published rusak tidak fallback; disabled tidak hidup kembali. |
| Q02 | Required/conditional input | Missing/null/empty dan batas parameter mengikuti kontrak; request invalid tidak memanggil generation. |
| Q03 | Revisi atau harga berubah | Mismatch terdeteksi sebelum request berbayar; draft bertahan; klien existing mengikuti jalur kompatibilitas. |
| Q04 | Aset dan permission | Akses lintas pengguna ditolak; validasi file/kuota berjalan; perubahan endpoint tidak melewati izin fitur. |
| Q05 | Provider delivery | Metode terpilih bekerja tanpa cookie member; grant invalid/kedaluwarsa ditolak; cleanup sesuai retensi. |
| Q06 | Submit berulang | Key+payload sama mengembalikan job sama; key sama+payload berbeda konflik; tidak ada debit/request baru tak disengaja. |
| Q07 | Transaction/dispatch failure | Data tidak diproses sebelum commit; job/reservasi tertinggal mempunyai jalur pemulihan yang diuji. |
| Q08 | Timeout dan status tidak pasti | Tidak ada resubmit/refund otomatis; kondisi tanpa request ID ditangani secara eksplisit. |
| Q09 | Callback duplikat/tidak berurutan | Tidak ada double settlement/refund atau regresi status final; callback diverifikasi sesuai dukungan. |
| Q10 | Provider selesai, output gagal disimpan | Retry finalisasi tidak membuat generation berbayar baru; keadaan dan kredit tetap konsisten. |
| Q11 | Refresh dan hasil | Job dapat dibuka ulang, status benar, hasil private dapat diakses pemilik, download berfungsi. |
| Q12 | Ledger dan regresi | Jalur token/API tetap menggunakan kebijakan existing; harga, model ID, API, saldo, dan history tidak berubah diam-diam. |
| Q13 | Payload member/admin | Member tidak menerima routing/secret; diagnostik admin tersanitasi; hak akses ditegakkan backend. |
| Q14 | F6a | Sampel schema dan mapping terlacak; unsupported required fields memblokir publikasi; fixture tidak disebut live. |
| Q15 | Rollout/rollback | Aktivasi terbatas dapat dinonaktifkan tanpa menghapus job/revisi; smoke test hanya dalam otorisasi. |

Skenario yang belum didukung integrasi tertentu ditandai `tidak berlaku` dengan alasan, bukan dihapus atau dianggap lulus. Risiko yang tetap berlaku tetapi belum diuji ditandai `belum diverifikasi`.

### 7.3. Pelaporan

Pisahkan hasil `live provider`, `fixture/mock`, dan `belum diuji`. Screenshot browser membuktikan keadaan UI yang terlihat, bukan menggantikan pemeriksaan DB, kredit, idempotensi, dan keamanan.

Live paid run hanya menggunakan akun, model, lingkungan, dan budget yang sudah diotorisasi. Jika akses/budget belum tersedia, selesaikan pengujian independen yang dapat dilakukan dan laporkan blocker live secara spesifik.

---

## 8. Risks / open decisions

### 8.1. Keputusan yang sudah ditetapkan oleh revisi ini

Arsitektur tetap C. Eksekusi F0b memakai Inline. Storage private menjadi default; akses provider diberikan secara terkontrol. Billing existing tidak digabung. Perubahan FE minimum boleh dilakukan, sedangkan redesign tetap F1. Suite penuh tidak dijalankan pada production. Tidak ada pricing seed spekulatif.

### 8.2. Keputusan yang harus ditutup pada checkpoint awal

| Keputusan | Bukti/hasil yang harus ada |
|---|---|
| Pilot model+operation | ID aktual, dukungan input, akses provider, harga, dan jalur pengujian. |
| Endpoint/ledger mapping | Autentikasi, permission, ledger, satuan, dan kompatibilitas tiap entrypoint yang diubah. |
| Revision persistence | Bentuk tabel/snapshot, revisi aktif, legacy snapshot, dan retensi job lama. |
| Delivery referensi | Metode terverifikasi, akses tanpa cookie, TTL/retensi, dan kebijakan pencabutan bila digunakan. |
| Submit uncertainty | Kemampuan lookup provider, penanganan tanpa request ID, rekonsiliasi, dan reservasi tertahan. |
| Finalisasi hasil | Strategi retry output dan aturan settlement bila provider selesai tetapi hasil belum siap. |
| Rollout/live budget | Lingkungan, otorisasi, batas run, mekanisme aktivasi, dan rollback. |

Jangan memblokir pekerjaan independen hanya karena satu akses provider belum tersedia. Namun task yang bergantung pada keputusan billing/kontrak tersebut tidak boleh berjalan dengan asumsi yang tidak dicatat.

### 8.3. Risiko scope

F6a dapat menemukan fitur schema yang belum terwakili. Catat dan tambahkan hanya subset yang diperlukan, atau tandai model membutuhkan penanganan. Jangan mengubah F0b menjadi full importer.

Member/admin split tidak cukup diperiksa secara visual. Risiko kebocoran payload, izin path-based, duplicate submission, serta aset yang terbuka harus mempunyai pengujian backend.

### 8.4. Catatan operasional di luar scope

Dokumen sumber memuat klaim bahwa tuning `php-fpm pm.max_children` telah diterapkan. Klaim itu belum diverifikasi pada review ini dan tidak menjadi instruksi untuk mengubah production. Pindahkan detail tuning ke catatan operasional/incident yang sesuai; jangan gunakan sebagai acceptance F0b.

---

## 9. Cara eksekusi: Inline dengan checkpoint dan review

### 9.1. Pilihan

**Pilih Inline, bukan subagent baru sebagai implementer untuk setiap task.**

Alasannya adalah dependensi kontrak, transport, services, jobs, aset, dan kredit yang saling terkait pada F0b. Satu implementer utama menjaga konsistensi perubahan dan konteks migrasi. Ini adalah keputusan organisasi pekerjaan, bukan klaim terukur bahwa Inline pasti paling cepat.

Review dua tahap tetap wajib: (1) kesesuaian terhadap spesifikasi/acceptance; (2) kualitas implementasi, keamanan, transaksi, dan regresi. Subagent/reviewer terpisah boleh digunakan untuk review read-only bila tersedia, tetapi tidak mengubah mode utama menjadi subagent-driven per task.

Jangan menjalankan beberapa implementer paralel yang mengubah kontrak/file bersama tanpa pembagian kepemilikan. Jika review dilakukan oleh implementer yang sama, nyatakan sebagai self-review; jangan mengklaim review independen.

### 9.2. Task sequence dan checkpoint

| Task | Pekerjaan | Checkpoint / keluaran |
|---|---|---|
| T0 | Konfirmasi baseline repository, pemetaan endpoint/ledger/izin, pilih pilot, dan verifikasi batas test/live. | **CP0:** bukti sumber dikonfirmasi; keputusan yang memblokir core diselesaikan. |
| T1 | Tetapkan capability fields/rules, revision semantics, bentuk input-output adapter, dan test kontraknya. Jalankan probe F6a minimum. | **CP1:** kontrak pilot disepakati dan diuji; limitation eksplisit. |
| T2 | Implementasikan persistence capability/resolver, legacy compatibility, serta snapshot/migration yang dibutuhkan. | Targeted tests resolver, revisi, dan kompatibilitas lulus. |
| T3 | Implementasikan asset service dan delivery terpilih; bungkus adapter existing yang diperlukan. | **CP2:** kontrak → aset → adapter konsisten; akses dan mapping diuji. |
| T4 | Implementasikan koordinasi job, deduplikasi, billing policy entrypoint, transaction/dispatch, dan rekonsiliasi. | **CP3:** jalur normal/gagal tidak merusak kredit; ketidakpastian dan finalisasi tercakup. |
| T5 | Hubungkan controller/API existing yang masuk scope, FE minimum, serta admin minimum. | Integration tests dan payload/access tests lulus. |
| T6 | Jalankan proof-of-flow, failure paths, regresi, dan suite penuh terisolasi; selesaikan laporan F6a. | **CP4:** bukti Q01–Q15 tersedia atau keterbatasan dicatat jujur. |
| T7 | Siapkan rollout/rollback; lakukan smoke test terbatas hanya jika diotorisasi; serahkan laporan final. | **CP5:** hasil live/mock terpisah, cakupan aktual jelas, tidak ada klaim selesai palsu. |

Urutan menunjukkan dependensi. F6a boleh disisipkan saat T1–T3 untuk menguji kontrak; itu bukan instruksi membuka implementasi paralel pada file yang sama.

### 9.3. TDD dan aturan checkpoint

Untuk perubahan perilaku: tulis/reproduksi test yang gagal karena perilaku yang dimaksud, implementasikan perubahan minimum, lalu refactor dengan test tetap lulus. Kunci perilaku protocol existing sebelum membungkusnya. Untuk UI visual/manual dan konfigurasi yang tidak tepat diuji lewat unit test, gunakan bukti pengujian yang sesuai tanpa mengklaim seluruhnya TDD.

Setiap checkpoint mencatat perubahan dan file terkait, keputusan kontrak, perintah test serta hasilnya, status review dua tahap, risiko/blocker, dan langkah berikutnya. Jangan mengganti checkpoint dengan kalimat “sudah aman” tanpa bukti.

Checkpoint adalah gate kualitas, bukan kewajiban meminta konfirmasi pengguna setiap beberapa task. Pertanyaan hanya diperlukan untuk keputusan material yang tidak dapat diselesaikan dari repository/brief, akses baru, perubahan kebijakan, atau tindakan berbayar/production yang belum diotorisasi.

Perubahan kontrak setelah CP1 harus dicatat dan seluruh consumer/test terdampak diperbarui sebelum task berikutnya dilanjutkan. Jangan memperbaiki satu adapter dengan perubahan kontrak diam-diam yang merusak adapter lain.

### 9.4. Definition of done

F0b selesai hanya jika fondasi pilot terimplementasi, test yang relevan lulus, batas kompatibilitas jelas, dan proof-of-flow memiliki bukti sesuai tingkat pengujian yang dilakukan.

Jika live provider belum diuji, laporkan implementasi/fixture selesai tetapi live proof terblokir atau belum diverifikasi. Jika hanya text-to-image yang diuji live, nyatakan status pengujian referensi secara terpisah. Jangan mengubah keterbatasan itu menjadi klaim dukungan semua model/Studio.

---

## 10. Perubahan dari dokumen sumber dan instruksi serah-terima

### 10.1. Change log review

| Bagian sumber | Revisi / penajaman |
|---|---|
| §1 Audit result | Klaim repository diatribusikan ke dokumen sumber; wajib konfirmasi commit, lokasi kode, dan baseline test. |
| §3.1 Capability | Identitas field dan semantik params/required/null/array diperinci; subset tetap minimum. |
| §3.2 Resolver + §5 DB | Revisi immutable, snapshot legacy, routing/pricing, active revision, serta rollback data diperjelas. |
| §3.4 Adapter | Input-output dan hasil submit sinkron/asinkron/tidak pasti ditetapkan sebelum coding lintas task. |
| §3.5 Asset delivery | Private menjadi default; public disk bukan pilihan otomatis; akses provider, TTL, retensi, dan pengujian diperjelas. |
| §3.6 Jobs/credit | Deduplikasi, transaction/dispatch, ketidakpastian tanpa request ID, dan retry finalisasi output ditambahkan sebagai acceptance. |
| §4 Member/admin | Ledger/satuan mengikuti entrypoint; sanitasi diagnostik admin dan izin operasi diperjelas. |
| §5 Shared flow | Berbagi validator/adapter tidak menggabungkan ledger dan tidak otomatis menambah endpoint `/v1`. |
| §5 Pricing seed | Default “real” tanpa dasar dihapus; harga existing tetap, model baru memerlukan harga valid yang disetujui. |
| §5 Frontend | “None required” diganti “tanpa redesign, integrasi minimum wajib bila acceptance memerlukannya”. |
| §6 Proof-of-flow | Model/operasi harus konkret; pengujian referensi tidak boleh tersamarkan oleh keberhasilan text-to-image. |
| §6–7 Production/test | Suite penuh terisolasi; production hanya smoke test terbatas berizin; live dan mock dipisah. |
| §8 Risks | Inferensi schema dibatasi ke sampel; tuning php-fpm dikeluarkan dari scope implementasi F0b. |
| Pilihan eksekusi tambahan | Inline dengan implementer utama, TDD, checkpoint dependensi, dan review dua tahap. |

### 10.2. Hasil yang harus diserahkan implementer

Serahkan kode/migration yang berubah, kontrak final dan mapping legacy, pemetaan ledger/izin entrypoint yang disentuh, daftar model/adapter yang benar-benar masuk core, hasil test aktual, bukti browser yang relevan, laporan F6a, serta petunjuk rollout/rollback.

Laporan final membedakan `selesai`, `sebagian`, `terblokir`, dan `belum dikerjakan`, serta `live`, `fixture/mock`, dan `belum diverifikasi`. Jelaskan perubahan terhadap desain ini beserta alasan dan pengujiannya. Jangan menyatakan F1–F6 selesai karena fondasi F0b sudah berjalan.

### 10.3. Instruksi eksekusi yang diteruskan

> Gunakan dokumen revisi ini sebagai acuan F0. Arsitektur tetap C—Hybrid. Pilih Inline: satu implementer utama mengerjakan task berurutan dengan TDD, checkpoint berbasis dependensi, dan review dua tahap. Mulai dari CP0 untuk mengonfirmasi fakta repository, ledger/izin, kontrak, dan model pilot; kemudian ikuti urutan T1–T7. Pertahankan kode dan perilaku existing yang masih layak, jangan menggabungkan ledger, jangan menerapkan pricing spekulatif, dan jangan memperluas F0b menjadi seluruh Studio atau importer universal. Jalankan suite penuh pada lingkungan test terisolasi. Production dan live paid run hanya sesuai otorisasi yang benar-benar tersedia. Serahkan bukti implementasi dan pengujian, serta nyatakan seluruh keterbatasan dengan jelas.
