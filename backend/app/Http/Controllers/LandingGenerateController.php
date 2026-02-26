<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class LandingGenerateController extends Controller
{
    public function generate(Request $request)
    {
        // Hindari timeout default 60 detik saat generate konten panjang.
        @set_time_limit(300);

        $data = $request->validate([
            'company_name' => 'required|string|max:100',
            'product'      => 'required|string|max:150',
            'audience'     => 'required|string|max:150',
            'tone'         => 'required|string|in:profesional,santai,formal',
            'main_offer'   => 'nullable|string|max:180',
            'price_note'   => 'nullable|string|max:120',
            'bonus'        => 'nullable|string|max:160',
            'urgency'      => 'nullable|string|max:140',
            'cta'          => 'required|string|max:60',
            'contact'      => 'nullable|string|max:200',
            'brand_color'  => 'nullable|string|max:40',
        ]);

        $apiKey = (string) config('services.gemini.key');
        $model  = (string) config('services.gemini.model', 'gemini-2.0-flash');

        if (trim($apiKey) === '') {
            throw ValidationException::withMessages([
                'ai' => ['GEMINI_API_KEY belum di-set di .env / config/services.php.'],
            ]);
        }

        $prompt = $this->buildPrompt($data);

        try {
            $html = $this->callGeminiHtml($prompt, $apiKey, $model);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'ai' => ['Koneksi ke layanan AI terputus. Coba lagi sebentar.'],
            ]);
        }

        return response()->json([
            'html' => $html,
        ]);
    }

    public function generateCompanyProfile(Request $request)
    {
        @set_time_limit(300);

        $data = $request->validate([
            'company_name'       => 'required|string|max:120',
            'industry'           => 'required|string|max:120',
            'tagline'            => 'nullable|string|max:140',
            'company_overview'   => 'required|string|max:1500',
            'vision'             => 'nullable|string|max:400',
            'mission'            => 'nullable|string|max:1200',
            'services'           => 'required|string|max:1600',
            'target_market'      => 'nullable|string|max:500',
            'unique_value'       => 'nullable|string|max:600',
            'achievements'       => 'nullable|string|max:1000',
            'portfolio'          => 'nullable|string|max:1200',
            'team_info'          => 'nullable|string|max:1200',
            'contact_email'      => 'nullable|string|max:150',
            'contact_phone'      => 'nullable|string|max:80',
            'address'            => 'nullable|string|max:260',
            'social_links'       => 'nullable|string|max:500',
            'cta'                => 'required|string|max:80',
            'tone'               => 'required|string|in:profesional,santai,formal',
            'brand_color'        => 'nullable|string|max:40',
        ]);

        $apiKey = (string) config('services.gemini.key');
        $model  = (string) config('services.gemini.model', 'gemini-2.0-flash');

        if (trim($apiKey) === '') {
            throw ValidationException::withMessages([
                'ai' => ['GEMINI_API_KEY belum di-set di .env / config/services.php.'],
            ]);
        }

        $prompt = $this->buildCompanyProfilePrompt($data);

        try {
            $html = $this->callGeminiHtml($prompt, $apiKey, $model);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'ai' => ['Koneksi ke layanan AI terputus. Coba lagi sebentar.'],
            ]);
        }

        return response()->json([
            'html' => $html,
        ]);
    }

    private function callGeminiHtml(string $prompt, string $apiKey, string $model): string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        $full = '';
        $maxTurns = 6;
        $startedAt = microtime(true);
        $hardDeadlineSeconds = 240.0;

        $contents = [
            ['role' => 'user', 'parts' => [['text' => $prompt]]],
        ];

        for ($i = 0; $i < $maxTurns; $i++) {
            $elapsed = microtime(true) - $startedAt;
            $remaining = $hardDeadlineSeconds - $elapsed;
            if ($remaining <= 8) {
                break;
            }

            $timeoutSeconds = (int) max(20, min(90, floor($remaining - 5)));

            try {
                /** @var Response $resp */
                $resp = Http::connectTimeout(15)
                    ->timeout($timeoutSeconds)
                    ->retry(2, 800)
                    ->acceptJson()
                    ->asJson()
                    ->post($url . '?key=' . $apiKey, [
                        'contents' => $contents,
                        'generationConfig' => [
                            'temperature' => 0.45,
                            'maxOutputTokens' => 4096,
                        ],
                    ]);
            } catch (ConnectionException $e) {
                throw ValidationException::withMessages([
                    'ai' => ['Koneksi ke Gemini timeout/terputus. Coba lagi, atau ringkas input agar proses lebih cepat.'],
                ]);
            }

            if (!$resp instanceof Response) {
                throw ValidationException::withMessages([
                    'ai' => ['Respons dari layanan AI tidak valid. Coba lagi sebentar.'],
                ]);
            }

            if ($resp->failed()) {
                $msg = data_get($resp->json(), 'error.message') ?? ('HTTP ' . $resp->status());
                throw ValidationException::withMessages(['ai' => ["Gagal generate: {$msg}"]]);
            }

            $chunk = $this->extractTextFromGeminiResponse($resp);
            $full .= $chunk;

            if (str_contains($full, '<!-- END -->') || preg_match('/<\/html>/i', $full)) {
                break;
            }

            $contents[] = ['role' => 'model', 'parts' => [['text' => $chunk]]];
            $contents[] = ['role' => 'user', 'parts' => [[
                'text' => 'Lanjutkan tepat dari posisi terakhir. Jangan ulangi konten sebelumnya. Prioritaskan menyelesaikan sisa HTML dan akhiri dengan <!-- END -->.'
            ]]];
        }

        $full = str_replace('<!-- END -->', '', $full);
        $full = trim($full);

        if ($full === '') {
            throw ValidationException::withMessages([
                'ai' => ['AI tidak mengembalikan HTML.'],
            ]);
        }

        if (!$this->looksLikeCompleteHtml($full)) {
            $repaired = $this->repairIncompleteHtml($full, $apiKey, $url);
            if ($this->looksLikeCompleteHtml($repaired)) {
                return $repaired;
            }

            throw ValidationException::withMessages([
                'ai' => ['Output AI kepotong dan belum lengkap. Coba generate lagi 1x, atau ringkas input supaya hasil lebih cepat selesai.'],
            ]);
        }

        return $full;
    }

    private function repairIncompleteHtml(string $partialHtml, string $apiKey, string $url): string
    {
        $repairPrompt = <<<PROMPT
Lengkapi HTML company profile berikut karena output sebelumnya terpotong.

ATURAN:
- Kembalikan HTML lengkap dari `<!doctype html>` sampai `</html>`.
- Pertahankan struktur, style, dan isi yang sudah ada sebisa mungkin.
- Hanya perbaiki bagian yang terpotong/kurang.
- Output HARUS murni HTML, tanpa markdown.
- Akhiri dengan `<!-- END -->`.

HTML TERPOTONG:
{$partialHtml}
PROMPT;

        try {
            /** @var Response $resp */
            $resp = Http::connectTimeout(15)
                ->timeout(60)
                ->retry(2, 800)
                ->acceptJson()
                ->asJson()
                ->post($url . '?key=' . $apiKey, [
                    'contents' => [
                        ['role' => 'user', 'parts' => [['text' => $repairPrompt]]],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'maxOutputTokens' => 4096,
                    ],
                ]);
        } catch (ConnectionException $e) {
            return $partialHtml;
        }

        if (!$resp instanceof Response) {
            return $partialHtml;
        }

        if ($resp->failed()) {
            return $partialHtml;
        }

        $fixed = $this->extractTextFromGeminiResponse($resp);
        $fixed = str_replace('<!-- END -->', '', $fixed);
        $fixed = trim($fixed);

        return $fixed !== '' ? $fixed : $partialHtml;
    }

    private function extractTextFromGeminiResponse(Response $resp): string
    {
        $parts = data_get($resp->json(), 'candidates.0.content.parts', []);
        if (!is_array($parts)) {
            return '';
        }

        $text = '';
        foreach ($parts as $part) {
            $text .= (string) data_get($part, 'text', '');
        }

        return $text;
    }

    private function looksLikeCompleteHtml(string $html): bool
    {
        $hasDoctype = preg_match('/<!doctype html>/i', $html) === 1;
        $hasHtmlOpen = preg_match('/<html\b/i', $html) === 1;
        $hasHtmlClose = preg_match('/<\/html>/i', $html) === 1;
        $hasBodyOpen = preg_match('/<body\b/i', $html) === 1;
        $hasBodyClose = preg_match('/<\/body>/i', $html) === 1;

        return $hasDoctype && $hasHtmlOpen && $hasHtmlClose && $hasBodyOpen && $hasBodyClose;
    }


    private function buildPrompt(array $d): string
    {
        $company = $d['company_name'];
        $product = $d['product'];
        $aud     = $d['audience'];
        $tone    = $d['tone'];
        $offer   = $d['main_offer'] ?? '';
        $price   = $d['price_note'] ?? '';
        $bonus   = $d['bonus'] ?? '';
        $urgency = $d['urgency'] ?? '';
        $cta     = $d['cta'];
        $contact = $d['contact'] ?? '';
        $color   = $d['brand_color'] ?? '';

        return <<<PROMPT
Kamu adalah senior web developer + direct response copywriter. Buat 1 file landing page HTML yang rapi, modern, dan fokus konversi penjualan produk.

ATURAN OUTPUT:
- Output HARUS HANYA HTML (mulai dari <!doctype html>), tanpa markdown, tanpa penjelasan.
- Semua CSS ditulis di <style> (tidak boleh link CDN).
- Layout WAJIB boxed seperti referensi: ada background luar abu-abu, lalu 1 kolom utama putih di tengah.
- Lebar kolom utama desktop wajib dibatasi (gunakan max-width sekitar 760px-860px), center (`margin: 0 auto`), bukan full-width.
- Di mobile tetap full lebar layar HP (padding secukupnya), tapi di tablet/desktop tetap kolom tengah.
- Semua section konten harus berada di dalam kolom tengah tersebut.
- Wajib pakai fondasi CSS yang rapi dan lengkap (tidak boleh setengah):
  - CSS reset minimal: `*{box-sizing:border-box}` + reset margin default elemen utama.
  - Definisikan variabel warna di `:root` (primary, accent, text, muted, bg, surface, border).
  - Definisikan style global untuk `body`, `h1-h4`, `p`, `img`, `a`, `button`, `input`, `textarea`.
  - Semua elemen form harus fully styled (jangan ada style default browser yang polos).
  - Semua tombol CTA harus konsisten (radius, padding, warna, shadow, hover) dan center menggunakan wrapper (`.cta-wrap {text-align:center}`).
  - WAJIB pastikan kontras tombol aman: warna teks tombol dan background tombol tidak boleh sama/terlalu mirip pada state normal, hover, dan focus.
  - Definisikan token khusus tombol (`--btn-bg`, `--btn-text`, `--btn-hover-bg`, `--btn-hover-text`) lalu pakai konsisten di semua tombol.
  - Form field harus tersusun vertikal rapi, label di atas input, jarak antar field konsisten.
  - Card/testimoni/faq harus punya padding, border/radius, dan background yang konsisten.
  - Gunakan layout grid/flex yang aman agar tidak overflow horizontal.
- Aturan visual media:
  - Untuk section fitur/benefit/card seperti referensi, JANGAN gunakan `<img>`.
  - Gunakan icon saja (inline SVG atau karakter icon/emoji) di dalam elemen `.icon-badge` agar pasti tampil.
  - Icon harus konsisten ukuran (mis. 28px-40px), center, dan punya background badge yang rapi.
  - Jika perlu hero visual, tetap prioritaskan ilustrasi CSS/SVG inline, bukan gambar link eksternal.
- Aturan FAQ WAJIB stabil:
  - Gunakan struktur semantik `<details class="faq-item"><summary>...</summary><div class="faq-answer">...</div></details>`.
  - `summary` wajib full-width, rapi, tanpa border aneh, cursor pointer, ikon +/- konsisten.
  - Style state terbuka (`details[open]`) harus jelas; jawaban punya padding dan line-height nyaman.
  - Tidak boleh ada teks/ikon FAQ yang keluar container di mobile.
- Gaya visual dan ritme konten harus mirip landing page sales "scalev style": headline kuat, blok offer jelas, CTA berulang, trust section, dan struktur meyakinkan untuk closing.
- JANGAN meniru brand/salin teks referensi mentah. Buat desain + copy orisinal namun nuansanya sekelas landing page referensi.
- Gunakan pola copywriting khas referensi: ada label promo di atas hero, narasi masalah audiens, bagian "siapa yang cocok", bonus eksklusif, harga coret vs harga promo, lalu FAQ dan CTA penutup.
- Wajib ada section ini:
  1) Hero dengan label promo, headline penawaran, subheadline, CTA utama, mini trust badge
  2) Problem -> Solution (pain points target audiens + solusi produk)
  3) Siapa yang cocok untuk produk ini (minimal 3 persona)
  4) Benefit list (minimal 4 poin)
  5) Detail produk/program
  6) Paket/harga + promo/urgency + bonus (jika data tersedia)
  7) Testimoni sosial proof (minimal 3 testimoni realistis)
  8) FAQ (minimal 5 pertanyaan)
  9) Form lead/order bernuansa jualan (field: nama, no whatsapp, kebutuhan; tombol submit pakai CTA)
  10) Footer berisi kontak dan disclaimer ringkas
- Tampilkan CTA button dengan teks: "{$cta}".
- Bahasa Indonesia, tone: {$tone}.
- Jika ada contact, tampilkan di bagian footer.
- Wajib responsive di desktop dan mobile.
- Jangan buat navbar, top menu, atau hamburger menu.
- Gunakan copy yang terasa menjual produk, bukan sekadar company profile.
- Sisipkan elemen urgency yang natural (contoh kuota, periode promo, atau bonus terbatas) tanpa terkesan menakut-nakuti.
- Hindari komponen yang melebar 100vw. Prioritaskan komposisi rapat dan fokus seperti sales letter column.
- Checklist WAJIB sebelum menulis <!-- END -->:
  1) Tidak ada elemen input/button/textarea yang tampil default browser.
  2) Semua CTA button terlihat center secara visual.
  3) Kontras warna tombol aman di semua state (normal/hover/focus) dan tetap terbaca.
  4) Untuk fitur/benefit, hanya gunakan icon (tanpa `<img>`), dan icon tampil normal.
  5) FAQ berfungsi dan tampil rapi (tertutup/terbuka) di mobile + desktop.
  6) Tidak ada teks keluar container atau terpotong di mobile.
  7) Struktur section lengkap dan jarak antar section konsisten.
- WAJIB akhiri output dengan string persis: <!-- END -->
- Jangan berhenti sebelum menulis <!-- END -->.


DATA:
- Nama perusahaan: {$company}
- Produk/jasa: {$product}
- Target audiens: {$aud}
- Penawaran utama (opsional): {$offer}
- Harga/promo singkat (opsional): {$price}
- Bonus (opsional): {$bonus}
- Urgency/kelangkaan (opsional): {$urgency}
- Warna brand (opsional): {$color}
- Contact (opsional): {$contact}

Buat konten yang masuk akal dan tidak ada placeholder seperti "lorem ipsum".
PROMPT;
    }

    private function buildCompanyProfilePrompt(array $d): string
    {
        $company       = $d['company_name'];
        $industry      = $d['industry'];
        $tagline       = $d['tagline'] ?? '';
        $overview      = $d['company_overview'];
        $vision        = $d['vision'] ?? '';
        $mission       = $d['mission'] ?? '';
        $services      = $d['services'];
        $target        = $d['target_market'] ?? '';
        $uvp           = $d['unique_value'] ?? '';
        $achievements  = $d['achievements'] ?? '';
        $portfolio     = $d['portfolio'] ?? '';
        $team          = $d['team_info'] ?? '';
        $email         = $d['contact_email'] ?? '';
        $phone         = $d['contact_phone'] ?? '';
        $address       = $d['address'] ?? '';
        $social        = $d['social_links'] ?? '';
        $cta           = $d['cta'];
        $tone          = $d['tone'];
        $brandColor    = $d['brand_color'] ?? '';

        return <<<PROMPT
Kamu adalah senior web developer + brand copywriter. Buat 1 file HTML company profile yang profesional, modern, dan siap dipakai.

ATURAN OUTPUT:
- Output HARUS HANYA HTML lengkap (mulai dari <!doctype html>) tanpa markdown.
- Semua CSS wajib inline di tag <style>, tanpa framework/CDN.
- Layout harus clean, boxed, responsif mobile dan desktop.
- WAJIB definisikan color token yang jelas dan dipakai konsisten:
  `--bg`, `--surface`, `--text`, `--text-muted`, `--primary`, `--border`.
- Kontras WAJIB aman:
  - Teks utama jangan gunakan warna putih/abu sangat muda di background terang.
  - Jika background section terang, teks harus gelap (`var(--text)`).
  - Hindari overlay/gradient yang membuat teks sulit dibaca.
  - CTA, heading, paragraph, dan link nav harus tetap terbaca jelas di desktop/mobile.
- Wajib ada navbar dengan anchor link ke section utama.
- Link navbar WAJIB dibatasi hanya 4 item ini saja (urutan boleh sama):
  1) Home (`#home`)
  2) Tentang (`#tentang`)
  3) Layanan (`#layanan`)
  4) Kontak (`#kontak`)
- Jangan tampilkan link nav tambahan lain (seperti: Visi, Portfolio, Tim, dll) di navbar.
- Navbar wajib responsif:
  - Desktop/tablet tampil horizontal.
  - Di desktop/tablet (>=769px): hamburger WAJIB tidak tampil sama sekali (`display:none`), hanya nav links horizontal yang tampil.
  - Mobile (<=768px) wajib pakai hamburger menu yang bisa toggle buka/tutup.
  - Di mobile (<=768px): nav links horizontal WAJIB disembunyikan default, hanya tombol hamburger yang tampil.
  - Posisi hamburger WAJIB di pojok kanan header, sejajar vertikal dengan logo (header pakai flex `justify-content: space-between; align-items: center;`).
  - Tombol hamburger jangan overlap logo/konten, punya ukuran tap target minimal 40x40px.
  - Menu mobile harus rapi (tidak numpuk), mudah diklik, dan menutup otomatis setelah link diklik.
  - Panel menu mobile tampil tepat di bawah header (bukan di tengah section), full width container, dengan background solid dan z-index aman.
  - Transisi menu halus dan tidak bikin layout geser berlebihan.
  - Hamburger HARUS benar-benar berfungsi pakai JavaScript vanilla (bukan CSS-only yang rawan gagal klik).
  - Gunakan pola minimal:
    - tombol: `id="menu-toggle"` + `aria-controls="mobile-menu"` + `aria-expanded`
    - panel: `id="mobile-menu"`
    - script `DOMContentLoaded` dengan `addEventListener('click', ...)` untuk toggle class open/close.
  - Pastikan elemen header/menu tidak tertutup layer lain:
    - header `position: sticky/fixed` dengan `z-index` tinggi.
    - tombol dan panel menu `pointer-events: auto`.
    - tidak ada pseudo-element/layer transparan menimpa area klik hamburger.
- Wajib pakai media query eksplisit agar behavior tidak ambigu:
  - `@media (min-width: 769px) { .menu-toggle { display: none !important; } .desktop-nav { display: flex !important; } .mobile-menu { display: none !important; } }`
  - `@media (max-width: 768px) { .menu-toggle { display: inline-flex !important; } .desktop-nav { display: none !important; } }`
- Wajib set `html { scroll-behavior: smooth; }` agar scroll antar section smooth.
- Wajib ada section: Hero, Tentang Kami, Visi & Misi, Layanan, Keunggulan, Portfolio/Project, Tim, Pencapaian, CTA, Kontak + Footer.
- Hindari gaya hard-selling seperti landing page promo. Fokus trust, kredibilitas, dan informasi perusahaan.
- Gunakan icon sederhana (SVG inline) untuk card layanan/keunggulan, tidak pakai gambar eksternal.
- Ukuran icon WAJIB dibatasi agar tidak oversize:
  - Set wrapper icon tetap (contoh `.icon-wrap`) dengan `width`/`height` 56px-72px.
  - Icon SVG di dalamnya wajib `width: 28px-36px; height: 28px-36px; max-width: 100%;`.
  - Dilarang set icon dengan unit yang bisa membesar liar (`vw`, `clamp` terlalu besar, atau `%` tanpa batas).
  - Pastikan icon tidak pernah lebih besar dari judul card pada semua breakpoint.
- Form kontak sederhana wajib ada dengan field: Nama, Email, Pesan, dan tombol CTA bertuliskan "{$cta}".
- Jika data optional kosong, isi dengan copy yang relevan dan natural (tanpa lorem ipsum).
- Gunakan bahasa Indonesia dengan tone: {$tone}.
- Jika tone = profesional atau formal: jangan gunakan emoji sama sekali di seluruh halaman, gunakan icon SVG yang clean.
- Jika tone = santai: boleh gunakan icon playful secukupnya, tapi tetap profesional dan rapi.
- Gunakan warna brand bila tersedia: {$brandColor}.
- Pastikan aksesibilitas dasar: heading berurutan, kontras warna cukup, tombol dan input styled rapi.
- Footer WAJIB pakai layout seperti company website modern:
  - Background gelap (bukan putih), kontras teks jelas.
  - Konten utama footer 3 kolom:
    1) Kolom brand/nama perusahaan + deskripsi singkat
    2) Kolom navigasi (hanya: Home, Tentang, Layanan, Kontak)
    3) Kolom media sosial dengan icon bulat outline (SVG inline, tanpa emoji)
  - Ada garis pemisah tipis lalu baris copyright di bagian paling bawah.
  - Di mobile, 3 kolom footer stack vertikal rapi dengan jarak yang enak.
  - Hindari footer polos; pastikan spacing, tipografi, dan alignment terlihat premium.
- Akhiri output dengan string persis: <!-- END -->
- SELF-CHECK sebelum tulis <!-- END -->:
  1) Cek visual kontras: tidak ada teks yang nyaru dengan background.
  2) Cek mobile 375px: tombol hamburger bisa diklik buka/tutup.
  3) Setelah klik link di mobile menu, menu menutup otomatis.
  4) Tidak ada elemen menutupi header/menu (z-index aman).
  5) Cek desktop >=1024px: hamburger tidak muncul sama sekali.
  6) Cek icon card: ukuran konsisten kecil-menengah, tidak ada icon oversize.

DATA PERUSAHAAN:
- Nama perusahaan: {$company}
- Industri: {$industry}
- Tagline: {$tagline}
- Ringkasan perusahaan: {$overview}
- Visi: {$vision}
- Misi: {$mission}
- Layanan utama: {$services}
- Target market: {$target}
- Unique value proposition: {$uvp}
- Pencapaian: {$achievements}
- Portfolio/project: {$portfolio}
- Info tim: {$team}
- Email: {$email}
- Telepon: {$phone}
- Alamat: {$address}
- Sosial media/link: {$social}

Buat hasil final yang siap pakai, tidak ada placeholder kosong, dan tetap ringkas dibaca pengunjung.
PROMPT;
    }
}
