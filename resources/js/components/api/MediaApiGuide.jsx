import { useEffect, useRef, useState } from 'react';
import { useLocale } from '../../contexts/LocaleContext';

export default function MediaApiGuide({ baseUrl }) {
    const { t } = useLocale();
    const [notice, setNotice] = useState('');
    const timer = useRef(null);
    useEffect(() => () => clearTimeout(timer.current), []);
    const api = `${String(baseUrl || '').replace(/\/+$/, '').replace(/\/v1$/, '')}/v1`;
    const auth = '-H "Authorization: Bearer $XSUPER_API_KEY"';
    const json = '-H "Content-Type: application/json"';
    const copy = async (value) => {
        clearTimeout(timer.current);
        try {
            await navigator.clipboard.writeText(value);
            setNotice(t('Contoh disalin.'));
        } catch {
            setNotice(t('Tidak dapat menyalin. Pilih teks contoh lalu salin secara manual.'));
        }
        timer.current = setTimeout(() => setNotice(''), 4000);
    };
    const examples = [
        {
            id: 'models', title: t('1. Pilih model dan periksa harga'), endpoint: 'GET /v1/media/models',
            description: t('Daftar model mengikuti izin akun dan batas model pada kunci. Gunakan kind, q, dan next_cursor untuk pencarian serta halaman berikutnya. Operasi realtime tidak tersedia di API ini.'),
            code: `curl --get "${api}/media/models" \\\n  ${auth} \\\n  --data-urlencode "kind=image" --data-urlencode "q="\n\n# Next page: add --data-urlencode "cursor=$NEXT_CURSOR"`,
        },
        {
            id: 'schema', title: t('2. Baca skema masukan'), endpoint: 'GET /v1/media/models/{model}',
            description: t('Simpan id model sebagai MODEL. Periksa operations, inputs, params, input_schema, billing, dan examples. Nama masukan berbeda antar model; misalnya prompt atau positivePrompt. Gunakan skema tersebut, bukan nama model upstream.'),
            code: `curl "${api}/media/models/$MODEL" \\\n  ${auth}`,
        },
        {
            id: 'generate', title: t('3. Buat generasi'), endpoint: 'POST /v1/media/generations',
            description: t('Server menghitung kutipan yang sama dengan Studio, lalu mencadangkan token. Respons 202 berisi id pekerjaan. Simpan Idempotency-Key yang sama saat mengulang permintaan identik agar tidak membayar dua kali. Kunci tidak boleh dipakai untuk masukan berbeda.'),
            code: `curl "${api}/media/generations" \\\n  ${auth} ${json} \\\n  -H "Idempotency-Key: image-order-001" \\\n  -d '{"model":"YOUR_MODEL_ID","operation":"text_to_image","inputs":{"prompt":"A cup on a wooden table"}}'`,
        },
        {
            id: 'list', title: t('4. Lihat riwayat'), endpoint: 'GET /v1/media/generations',
            description: t('Riwayat hanya berisi pekerjaan milik akun Anda, termasuk pekerjaan Studio. Gunakan kind dan next_cursor untuk menyaring serta berpindah halaman.'),
            code: `curl --get "${api}/media/generations" \\\n  ${auth} --data-urlencode "kind=image"\n\n# Next page: add --data-urlencode "cursor=$NEXT_CURSOR"`,
        },
        {
            id: 'poll', title: t('5. Pantau sampai selesai'), endpoint: 'GET /v1/media/generations/{id}',
            description: t('Simpan id respons sebagai JOB_ID. Periksa status, stage, progress, billing_status, outputs, dan result setiap beberapa detik. Status uncertain atau save_failed tidak berarti aman membuat ulang: token bisa tetap dicadangkan. Tinjau pekerjaan di Studio.'),
            code: `curl "${api}/media/generations/$JOB_ID" \\\n  ${auth}`,
        },
        {
            id: 'download', title: t('6. Unduh hasil asli'), endpoint: 'GET /v1/media/generations/{id}/outputs/{output}',
            description: t('url memerlukan kunci API. signed_url dapat diunduh tanpa kunci dan berlaku 60 menit; siapa pun yang memiliki tautannya dapat mengakses berkas selama masih berlaku. Jangan membagikannya secara publik. Ambil status lagi untuk mendapat tautan baru.'),
            code: `curl "${api}/media/generations/$JOB_ID/outputs/$OUTPUT_ID" \\\n  ${auth} --output result.bin\n\n# Without an API key: use the complete signed_url from the status response\ncurl "$SIGNED_URL" --output result.bin`,
        },
        {
            id: 'cancel', title: t('7. Batalkan pekerjaan yang masih mengantre'), endpoint: 'POST /v1/media/generations/{id}/cancel',
            description: t('Pembatalan hanya berhasil jika eksekusi belum dimulai. Pembatalan yang berhasil mengembalikan token cadangan. Respons 409 berarti pembatalan tidak dikonfirmasi; menutup koneksi tidak membatalkan atau mengembalikan biaya.'),
            code: `curl -X POST "${api}/media/generations/$JOB_ID/cancel" \\\n  ${auth}`,
        },
        {
            id: 'upload', title: t('8. Unggah referensi milik akun'), endpoint: 'POST /v1/files',
            description: t('Unggah multipart file dan role; ukuran, isi berkas, dan kuota penyimpanan diperiksa. Role yang tersedia mencakup image_ref, init_frame, end_frame, avatar_photo, speech_audio, reference_video, document, file, model_reference, audio_reference, dan mask_image. Gunakan role yang diminta skema.'),
            code: `curl "${api}/files" \\\n  ${auth} \\\n  -F "file=@reference.png" -F "role=image_ref"\n\n# Put the returned file id in the asset field declared by the model\ncurl "${api}/media/generations" \\\n  ${auth} ${json} \\\n  -H "Idempotency-Key: image-edit-001" \\\n  -d '{"model":"YOUR_MODEL_ID","operation":"image_edit","inputs":{"prompt":"Make the scene brighter","image":"YOUR_FILE_ID"}}'`,
        },
        {
            id: 'openai', title: t('9. Gunakan OpenAI Images di alat Anda'), endpoint: 'POST /v1/images/generations',
            description: t('Untuk Open WebUI, n8n, atau SDK OpenAI, gunakan URL dasar di bawah dan kunci XSuper Anda. Pilih model dengan text_to_image. n bernilai 1–4 sesuai kemampuan model; size dipetakan ke ukuran atau rasio terdekat yang didukung. Ukuran yang tidak dapat dipetakan ditolak dengan 400. Untuk kontrol skema lengkap, gunakan endpoint media.'),
            code: `curl "${api}/images/generations" \\\n  ${auth} ${json} \\\n  -H "Idempotency-Key: tool-image-001" \\\n  -d '{"model":"YOUR_MODEL_ID","prompt":"A cup on a wooden table","n":1,"size":"1024x1024","response_format":"url"}'\n\n# Inline bytes: use "response_format":"b64_json" instead of "url"`,
        },
    ];

    return <section className="space-y-6" aria-labelledby="media-api-guide-title">
        <header className="space-y-2">
            <h2 id="media-api-guide-title" className="text-xl font-bold text-slate-900 dark:text-white">{t('API Media untuk alat Anda')}</h2>
            <p className="text-sm leading-6 text-slate-600 dark:text-slate-300">{t('Gunakan saldo token media yang sama dengan Studio untuk membuat gambar, video, audio, dan keluaran lain. Masa aktif keanggotaan tidak mengunci token yang sudah dibeli.')}</p>
            <p className="text-sm leading-6 text-slate-600 dark:text-slate-300">{t('Harga token per hasil atau satuan tampil di /v1/media/models. Perhatikan price_unit: second dikalikan durasi, jumlah hasil dikalikan sesuai billing, dan Pro dapat menambah biaya. price_tokens pada pekerjaan adalah harga yang dicadangkan, bukan biaya penyedia.')}</p>
        </header>
        <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-white/10 dark:bg-white/5">
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{t('URL dasar OpenAI')}</p>
            <div className="flex flex-wrap items-center gap-3">
                <code className="min-w-0 flex-1 break-all text-sm text-slate-800 dark:text-slate-200">{api}</code>
                <button type="button" onClick={() => copy(api)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 dark:border-white/20 dark:text-slate-200 dark:hover:bg-white/10">{t('Salin URL')}</button>
            </div>
            <p className="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">{t('Simpan kunci hanya di server atau penyimpanan rahasia alat Anda. Contoh memakai variabel XSUPER_API_KEY, MODEL, JOB_ID, dan OUTPUT_ID; ganti nilai contoh dengan id dari respons Anda.')}</p>
        </div>
        <p role="status" aria-live="polite" className="min-h-5 text-sm text-slate-600 dark:text-slate-300">{notice}</p>
        <div className="space-y-5">
            {examples.map((example) => <article key={example.id} className="overflow-hidden rounded-xl border border-slate-200 dark:border-white/10">
                <div className="space-y-2 p-4 sm:p-5">
                    <h3 className="font-semibold text-slate-900 dark:text-white">{example.title}</h3>
                    <code className="block break-all text-xs text-slate-500 dark:text-slate-400">{example.endpoint}</code>
                    <p className="text-sm leading-6 text-slate-600 dark:text-slate-300">{example.description}</p>
                    <button type="button" onClick={() => copy(example.code)} aria-label={`${t('Salin contoh')}: ${example.endpoint}`} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 dark:border-white/20 dark:text-slate-200 dark:hover:bg-white/10">{t('Salin contoh')}</button>
                </div>
                <pre className="overflow-x-auto bg-slate-950 p-4 text-xs leading-6 text-slate-200 sm:p-5" tabIndex={0}><code>{example.code}</code></pre>
            </article>)}
        </div>
        <aside className="space-y-3 rounded-xl border border-slate-200 p-4 text-sm leading-6 text-slate-600 dark:border-white/10 dark:text-slate-300">
            <h3 className="font-semibold text-slate-900 dark:text-white">{t('Batas, biaya, dan penanganan kesalahan')}</h3>
            <p>{t('Pembuatan generasi, unggahan, dan OpenAI Images berbagi batas 20 permintaan per menit per kunci. Batas umum kunci tetap berlaku. Respons kesalahan memakai error.message, error.type, dan error.code.')}</p>
            <p>{t('402 insufficient_tokens berarti token tidak cukup; 403 berarti izin atau batas model; 404 menyembunyikan sumber daya yang bukan milik Anda; 429 berarti batas permintaan. Permintaan identik dengan Idempotency-Key yang sama mengembalikan pekerjaan lama tanpa debit kedua.')}</p>
            <p>{t('OpenAI Images menunggu hingga 120 detik secara bawaan. Respons 504 dan 502 menyertakan error.generation_ids dan error.poll_urls untuk setiap pekerjaan dari permintaan tersebut. Pada 504 pekerjaan tetap berjalan: pantau URL tersebut dengan kunci Anda, jangan membuat ulang dengan kunci idempotensi baru. Respons 502 menjelaskan kegagalan yang aman dibaca anggota; pekerjaan lain dalam batch yang sama mungkin tetap selesai.')}</p>
            <p>{t('count, pro, billing_seconds, dan rights_confirmed tersedia pada endpoint media sesuai billing dan skema model. Avatar memerlukan konfirmasi hak atas foto dan suara. Untuk batch native, respons menyertakan generation_ids: pantau setiap id untuk mengambil semua hasil; total_tokens adalah total cadangannya. Status setiap pekerjaan dalam batch juga mencantumkan generation_ids yang sama.')}</p>
        </aside>
    </section>;
}
