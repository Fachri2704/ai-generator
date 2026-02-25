<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Client\Response;
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

    private function callGeminiHtml(string $prompt, string $apiKey, string $model): string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        $full = '';
        $maxTurns = 3;
        $startedAt = microtime(true);
        $hardDeadlineSeconds = 95.0;

        $contents = [
            ['role' => 'user', 'parts' => [['text' => $prompt]]],
        ];

        for ($i = 0; $i < $maxTurns; $i++) {
            $elapsed = microtime(true) - $startedAt;
            $remaining = $hardDeadlineSeconds - $elapsed;
            if ($remaining <= 8) {
                break;
            }

            $timeoutSeconds = (int) max(10, min(35, floor($remaining - 3)));

            /** @var Response $resp */
            $resp = Http::timeout($timeoutSeconds)
                ->acceptJson()
                ->asJson()
                ->post($url . '?key=' . $apiKey, [
                    'contents' => $contents,
                    'generationConfig' => [
                        'temperature' => 0.6,
                        'maxOutputTokens' => 5600,
                    ],
                ]);

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
            throw ValidationException::withMessages([
                'ai' => ['Output AI kepotong dan belum lengkap. Coba generate lagi 1x, atau ringkas input supaya hasil lebih cepat selesai.'],
            ]);
        }

        return $full;
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
- Aturan gambar WAJIB anti broken image:
  - Jangan pakai URL gambar acak yang rawan 404.
  - Gunakan ilustrasi inline SVG di dalam HTML (preferred) ATAU data URI SVG.
  - Jika tetap pakai <img>, wajib tambahkan fallback `onerror` yang mengganti ke data URI SVG agar tidak ada ikon gambar rusak.
  - Semua gambar wajib punya ukuran jelas (`width/height` atau container aspect-ratio), `object-fit: cover`, dan `display:block`.
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
  4) Semua gambar tampil normal tanpa broken image/not found.
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
}
