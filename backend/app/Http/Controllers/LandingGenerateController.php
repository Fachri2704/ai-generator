<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LandingGenerateController extends Controller
{
    public function generate(Request $request)
    {
        $this->applyExecutionLimits();

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
        $fallbackModel = (string) config('services.gemini.fallback_model', '');

        if (trim($apiKey) === '') {
            throw ValidationException::withMessages([
                'ai' => ['GEMINI_API_KEY belum di-set di .env / config/services.php.'],
            ]);
        }

        $prompt = $this->buildPrompt($data);

        try {
            $html = $this->callGeminiHtmlWithFallback($prompt, $apiKey, $model, $fallbackModel);
        } catch (ValidationException $e) {
            $msg = $this->extractValidationMessage($e);
            if ($this->shouldServeFallbackHtml($msg)) {
                Log::warning('Serving local landing fallback HTML', [
                    'message' => $msg,
                ]);

                return response()->json([
                    'html' => $this->buildLandingFallbackHtml($data),
                    'fallback' => true,
                ]);
            }

            throw $e;
        } catch (\Throwable $e) {
            Log::error('Generate landing failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
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
        $this->applyExecutionLimits();

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
        $fallbackModel = (string) config('services.gemini.fallback_model', '');

        if (trim($apiKey) === '') {
            throw ValidationException::withMessages([
                'ai' => ['GEMINI_API_KEY belum di-set di .env / config/services.php.'],
            ]);
        }

        $prompt = $this->buildCompanyProfilePrompt($data);

        try {
            $html = $this->callGeminiHtmlWithFallback($prompt, $apiKey, $model, $fallbackModel);
        } catch (ValidationException $e) {
            $msg = $this->extractValidationMessage($e);
            if ($this->shouldServeFallbackHtml($msg)) {
                Log::warning('Serving local company profile fallback HTML', [
                    'message' => $msg,
                ]);

                return response()->json([
                    'html' => $this->buildCompanyProfileFallbackHtml($data),
                    'fallback' => true,
                ]);
            }

            throw $e;
        } catch (\Throwable $e) {
            Log::error('Generate company profile failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            throw ValidationException::withMessages([
                'ai' => ['Koneksi ke layanan AI terputus. Coba lagi sebentar.'],
            ]);
        }

        return response()->json([
            'html' => $html,
        ]);
    }

    private function applyExecutionLimits(): void
    {
        $seconds = (int) env('GEMINI_MAX_EXECUTION_SECONDS', 240);
        $seconds = max(60, min($seconds, 600));
        @ini_set('max_execution_time', (string) $seconds);
        @set_time_limit($seconds);
    }

    private function buildLandingFallbackHtml(array $d): string
    {
        $theme = $this->pickFallbackTheme();
        $company = $this->e($d['company_name'] ?? 'Brand Anda');
        $product = $this->e($d['product'] ?? 'Produk unggulan');
        $audience = $this->e($d['audience'] ?? 'Audiens yang ingin hasil lebih cepat dan terarah.');
        $offer = $this->e($d['main_offer'] ?? "Solusi praktis untuk {$product} dengan hasil yang lebih terukur.");
        $price = $this->e($d['price_note'] ?? 'Hubungi kami untuk penawaran terbaik hari ini.');
        $bonus = $this->e($d['bonus'] ?? 'Bonus onboarding, panduan implementasi, dan support awal.');
        $urgency = $this->e($d['urgency'] ?? 'Slot promo terbatas untuk batch pendaftaran saat ini.');
        $cta = $this->e($d['cta'] ?? 'Daftar Sekarang');
        $contact = $this->e($d['contact'] ?? 'Tim kami siap membantu konsultasi lebih lanjut.');
        $brandColor = $this->normalizeColor($d['brand_color'] ?? $theme['primary']);
        $tone = strtolower((string) ($d['tone'] ?? 'profesional'));
        $toneLine = match ($tone) {
            'formal' => 'Pendekatan rapi, jelas, dan meyakinkan untuk membantu calon pelanggan mengambil keputusan.',
            'santai' => 'Bahasa yang hangat, jelas, dan tetap fokus bikin calon pelanggan cepat paham manfaatnya.',
            default => 'Copy yang profesional, fokus manfaat, dan tetap enak dibaca dari awal sampai CTA.',
        };

        return <<<HTML
<!doctype html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{$company} - {$product}</title>
  <style>
    :root {
      --primary: {$brandColor};
      --accent: {$theme['heroB']};
      --text: {$theme['text']};
      --muted: {$theme['muted']};
      --bg: #edf1f5;
      --surface: #ffffff;
      --border: {$theme['border']};
      --btn-bg: {$brandColor};
      --btn-text: #ffffff;
      --btn-hover-bg: {$theme['footerBg']};
      --btn-hover-text: #ffffff;
      --success: #e8fff2;
    }
    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
      margin: 0;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      background: radial-gradient(circle at top left, #ffffff 0, #edf1f5 45%, #e6ebf2 100%);
      color: var(--text);
      line-height: 1.65;
    }
    h1, h2, h3, p { margin: 0; }
    a { color: inherit; text-decoration: none; }
    button, input, textarea {
      font: inherit;
      border: 0;
      outline: 0;
    }
    .page-shell {
      width: min(840px, calc(100vw - 24px));
      margin: 0 auto;
      padding: 20px 0 48px;
    }
    .page-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 28px;
      overflow: hidden;
      box-shadow: 0 30px 80px rgba(15, 23, 42, 0.10);
    }
    .section {
      padding: 28px 22px;
      border-top: 1px solid var(--border);
    }
    .hero {
      padding: 34px 22px 28px;
      background: linear-gradient(180deg, #ffffff 0%, {$theme['heroB']} 100%);
    }
    .promo-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      border-radius: 999px;
      background: rgba(255,255,255,0.85);
      border: 1px solid var(--border);
      font-size: 13px;
      font-weight: 700;
      color: var(--primary);
    }
    .hero h1 {
      margin-top: 18px;
      font-size: clamp(1.9rem, 5vw, 3rem);
      line-height: 1.12;
      letter-spacing: -0.03em;
    }
    .hero p {
      margin-top: 14px;
      color: var(--muted);
      font-size: 1rem;
    }
    .cta-wrap {
      text-align: center;
      margin-top: 20px;
    }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 14px 22px;
      border-radius: 14px;
      background: var(--btn-bg);
      color: var(--btn-text);
      font-weight: 700;
      box-shadow: 0 14px 30px rgba(0,0,0,0.14);
      transition: background .2s ease, color .2s ease, transform .2s ease;
    }
    .btn:hover {
      background: var(--btn-hover-bg);
      color: var(--btn-hover-text);
      transform: translateY(-1px);
    }
    .trust-row, .grid-2, .grid-3 {
      display: grid;
      gap: 14px;
    }
    .trust-row { margin-top: 18px; grid-template-columns: repeat(3, 1fr); }
    .grid-2 { grid-template-columns: repeat(2, 1fr); }
    .grid-3 { grid-template-columns: repeat(3, 1fr); }
    .card {
      padding: 18px;
      border: 1px solid var(--border);
      border-radius: 18px;
      background: #ffffff;
    }
    .soft {
      background: #f8fafc;
    }
    .icon-badge {
      width: 42px;
      height: 42px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 12px;
      background: {$theme['heroB']};
      color: var(--primary);
      font-size: 20px;
      margin-bottom: 12px;
    }
    .section-title {
      font-size: clamp(1.3rem, 3vw, 1.8rem);
      line-height: 1.2;
      margin-bottom: 10px;
    }
    .section-intro {
      color: var(--muted);
      margin-bottom: 18px;
    }
    ul.clean {
      margin: 0;
      padding-left: 18px;
      color: var(--muted);
    }
    ul.clean li + li {
      margin-top: 8px;
    }
    .price-box {
      background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    }
    .old-price {
      color: #94a3b8;
      text-decoration: line-through;
      font-size: 15px;
    }
    .new-price {
      display: block;
      margin-top: 6px;
      font-size: 1.8rem;
      font-weight: 800;
      color: var(--primary);
    }
    .note {
      display: inline-block;
      margin-top: 12px;
      padding: 8px 12px;
      border-radius: 999px;
      background: var(--success);
      color: #166534;
      font-size: 13px;
      font-weight: 700;
    }
    .testimonial-name {
      margin-top: 12px;
      font-weight: 700;
    }
    .faq-item {
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 0 16px;
      background: #fff;
    }
    .faq-item + .faq-item {
      margin-top: 12px;
    }
    .faq-item summary {
      list-style: none;
      cursor: pointer;
      padding: 16px 0;
      font-weight: 700;
    }
    .faq-item summary::-webkit-details-marker { display: none; }
    .faq-answer {
      padding: 0 0 16px;
      color: var(--muted);
    }
    .form-stack {
      display: grid;
      gap: 12px;
    }
    label {
      display: grid;
      gap: 8px;
      font-size: 14px;
      font-weight: 600;
    }
    input, textarea {
      width: 100%;
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 13px 14px;
      background: #ffffff;
      color: var(--text);
    }
    textarea {
      min-height: 110px;
      resize: vertical;
    }
    .footer {
      background: {$theme['footerBg']};
      color: {$theme['footerText']};
    }
    .footer p {
      color: {$theme['footerText']};
    }
    .footer-cta {
      color: #ffffff;
      font-weight: 700;
    }
    @media (max-width: 760px) {
      .grid-2, .grid-3, .trust-row {
        grid-template-columns: 1fr;
      }
      .section, .hero {
        padding-left: 16px;
        padding-right: 16px;
      }
      .page-shell {
        width: min(100vw - 12px, 840px);
        padding-top: 10px;
      }
      .page-card {
        border-radius: 22px;
      }
    }
  </style>
</head>
<body>
  <div class="page-shell">
    <main class="page-card">
      <section class="hero">
        <span class="promo-pill">Promo aktif untuk {$product}</span>
        <h1>{$offer}</h1>
        <p>{$company} membantu {$audience}</p>
        <p>{$toneLine}</p>
        <div class="cta-wrap">
          <a href="#form-order" class="btn">{$cta}</a>
        </div>
        <div class="trust-row">
          <div class="card soft"><strong>Fokus manfaat</strong><p>Pesan utama dibuat jelas agar pengunjung cepat paham value-nya.</p></div>
          <div class="card soft"><strong>Siap dipakai</strong><p>Struktur halaman sudah siap untuk presentasi, iklan, atau follow up penjualan.</p></div>
          <div class="card soft"><strong>Responsive</strong><p>Tetap nyaman dibaca di desktop dan mobile dengan layout boxed yang rapi.</p></div>
        </div>
      </section>

      <section class="section">
        <h2 class="section-title">Masalah yang Sering Dihadapi Calon Pelanggan</h2>
        <p class="section-intro">Sebelum membeli {$product}, audiens biasanya butuh alasan yang jelas kenapa solusi ini layak dipilih sekarang.</p>
        <div class="grid-2">
          <div class="card">
            <div class="icon-badge">!</div>
            <h3>Masalah utama</h3>
            <p>Target audiens {$audience} sering bingung memilih solusi yang benar-benar relevan, aman, dan terasa sepadan dengan biaya.</p>
          </div>
          <div class="card">
            <div class="icon-badge">+</div>
            <h3>Solusi yang ditawarkan</h3>
            <p>{$product} dari {$company} disusun untuk memberi arah yang lebih jelas, proses yang lebih praktis, dan hasil yang lebih terukur.</p>
          </div>
        </div>
      </section>

      <section class="section">
        <h2 class="section-title">Siapa yang Cocok Menggunakan {$product}</h2>
        <div class="grid-3">
          <div class="card"><div class="icon-badge">1</div><h3>Calon pembeli baru</h3><p>Cocok untuk yang ingin mulai dengan panduan yang lebih terarah dan tidak ingin trial and error terlalu lama.</p></div>
          <div class="card"><div class="icon-badge">2</div><h3>Yang ingin hasil lebih cepat</h3><p>Pas untuk audiens yang butuh proses lebih ringkas, jelas, dan punya langkah yang mudah diikuti.</p></div>
          <div class="card"><div class="icon-badge">3</div><h3>Pencari solusi praktis</h3><p>Ideal untuk yang mengutamakan manfaat nyata, komunikasi jelas, dan penawaran yang gampang dipahami.</p></div>
        </div>
      </section>

      <section class="section">
        <h2 class="section-title">Benefit Utama</h2>
        <div class="grid-2">
          <div class="card"><div class="icon-badge">*</div><h3>Pesan lebih fokus</h3><p>Copy dan alur penawaran menonjolkan manfaat paling penting dari {$product}.</p></div>
          <div class="card"><div class="icon-badge">*</div><h3>Lebih meyakinkan</h3><p>Setiap section dirancang untuk membangun kepercayaan sebelum calon pelanggan menekan CTA.</p></div>
          <div class="card"><div class="icon-badge">*</div><h3>Siap follow up</h3><p>Form lead memudahkan kamu mengumpulkan data pengunjung yang tertarik.</p></div>
          <div class="card"><div class="icon-badge">*</div><h3>Tampilan rapi</h3><p>Layout boxed, kartu konten, dan hierarchy visual dibuat aman untuk presentasi maupun iklan.</p></div>
        </div>
      </section>

      <section class="section">
        <h2 class="section-title">Detail Program / Produk</h2>
        <div class="card">
          <p><strong>Nama brand:</strong> {$company}</p>
          <p><strong>Produk:</strong> {$product}</p>
          <p><strong>Audiens utama:</strong> {$audience}</p>
          <p><strong>Fokus penawaran:</strong> {$offer}</p>
          <p><strong>Catatan tambahan:</strong> {$toneLine}</p>
        </div>
      </section>

      <section class="section">
        <h2 class="section-title">Paket dan Penawaran</h2>
        <div class="card price-box">
          <span class="old-price">Harga normal dapat berbeda tergantung kebutuhan</span>
          <span class="new-price">{$price}</span>
          <p style="margin-top:12px;color:var(--muted);">Bonus: {$bonus}</p>
          <span class="note">{$urgency}</span>
          <div class="cta-wrap">
            <a href="#form-order" class="btn">{$cta}</a>
          </div>
        </div>
      </section>

      <section class="section">
        <h2 class="section-title">Apa Kata Mereka</h2>
        <div class="grid-3">
          <div class="card">
            <p>"Penawarannya lebih mudah dipahami dan calon pelanggan jadi lebih cepat respon."</p>
            <div class="testimonial-name">Rina, owner bisnis jasa</div>
          </div>
          <div class="card">
            <p>"Struktur halamannya rapi, poin pentingnya jelas, dan CTA-nya terasa meyakinkan."</p>
            <div class="testimonial-name">Fajar, marketer UMKM</div>
          </div>
          <div class="card">
            <p>"Kami jadi punya halaman yang siap dipakai untuk promosi tanpa perlu nunggu lama."</p>
            <div class="testimonial-name">Nadia, tim operasional</div>
          </div>
        </div>
      </section>

      <section class="section">
        <h2 class="section-title">FAQ</h2>
        <details class="faq-item" open>
          <summary>Apa manfaat utama {$product}?</summary>
          <div class="faq-answer">Manfaat utamanya adalah membantu audiens mendapatkan solusi yang lebih jelas, terarah, dan terasa sepadan dengan kebutuhan mereka.</div>
        </details>
        <details class="faq-item">
          <summary>Apakah cocok untuk pemula?</summary>
          <div class="faq-answer">Ya. Struktur informasi dan penawarannya dibuat agar mudah dipahami bahkan oleh calon pelanggan yang baru mengenal produk ini.</div>
        </details>
        <details class="faq-item">
          <summary>Bagaimana dengan bonus atau promo?</summary>
          <div class="faq-answer">Bonus dan promo bisa langsung ditonjolkan pada section penawaran agar alasan membeli terasa lebih kuat.</div>
        </details>
        <details class="faq-item">
          <summary>Apakah tampilannya aman di mobile?</summary>
          <div class="faq-answer">Ya. Layout fallback ini dibuat boxed dan responsive supaya tetap rapi di layar HP maupun desktop.</div>
        </details>
        <details class="faq-item">
          <summary>Bagaimana cara lanjut konsultasi?</summary>
          <div class="faq-answer">Pengunjung bisa isi form di bawah atau langsung menghubungi kontak yang tersedia di bagian footer.</div>
        </details>
      </section>

      <section class="section" id="form-order">
        <h2 class="section-title">Siap Ambil Penawaran Ini?</h2>
        <p class="section-intro">Isi data singkat berikut supaya tim {$company} bisa bantu proses lebih lanjut.</p>
        <form class="form-stack">
          <label>Nama Lengkap<input type="text" placeholder="Nama kamu" /></label>
          <label>No WhatsApp<input type="text" placeholder="08xxxxxxxxxx" /></label>
          <label>Kebutuhan<textarea placeholder="Ceritakan kebutuhan singkat kamu"></textarea></label>
          <div class="cta-wrap">
            <button type="button" class="btn">{$cta}</button>
          </div>
        </form>
      </section>

      <section class="section footer">
        <h2 class="section-title footer-cta">{$company}</h2>
        <p>{$product} untuk {$audience}</p>
        <p style="margin-top:10px;">Kontak: {$contact}</p>
        <p style="margin-top:10px;">Disclaimer: halaman ini disiapkan otomatis sebagai fallback saat layanan AI sedang padat, namun tetap siap dipakai sebagai draft promosi.</p>
      </section>
    </main>
  </div>
</body>
</html>
HTML;
    }

    private function buildCompanyProfileFallbackHtml(array $d): string
    {
        $theme = $this->pickFallbackTheme();
        $company = $this->e($d['company_name'] ?? 'Perusahaan Anda');
        $industry = $this->e($d['industry'] ?? 'Industri');
        $tagline = $this->e($d['tagline'] ?? 'Mitra tepercaya untuk pertumbuhan bisnis Anda.');
        $overview = $this->e($d['company_overview'] ?? 'Kami membantu bisnis berkembang dengan solusi yang terukur.');
        $vision = $this->e($d['vision'] ?? 'Menjadi perusahaan terpercaya di bidang layanan kami.');
        $mission = $this->listFromText($d['mission'] ?? '');
        $services = $this->listFromText($d['services'] ?? '');
        $target = $this->e($d['target_market'] ?? 'Perusahaan, UMKM, dan organisasi yang ingin bertumbuh lebih cepat.');
        $uvp = $this->e($d['unique_value'] ?? 'Pendekatan strategis, eksekusi cepat, dan komunikasi yang transparan.');
        $achievements = $this->listFromText($d['achievements'] ?? '');
        $portfolio = $this->listFromText($d['portfolio'] ?? '');
        $team = $this->e($d['team_info'] ?? 'Tim berpengalaman lintas disiplin untuk memastikan hasil yang konsisten.');
        $email = $this->e($d['contact_email'] ?? '-');
        $phone = $this->e($d['contact_phone'] ?? '-');
        $address = $this->e($d['address'] ?? '-');
        $social = $this->e($d['social_links'] ?? '-');
        $cta = $this->e($d['cta'] ?? 'Hubungi Kami');
        $brandColor = $this->normalizeColor($d['brand_color'] ?? $theme['primary']);

        return <<<HTML
<!doctype html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{$company} - Company Profile</title>
  <style>
    :root {
      --primary: {$brandColor};
      --text: {$theme['text']};
      --muted: {$theme['muted']};
      --bg: {$theme['bg']};
      --surface: {$theme['surface']};
      --border: {$theme['border']};
      --footer-bg: {$theme['footerBg']};
      --footer-text: {$theme['footerText']};
      --footer-border: {$theme['footerBorder']};
      --footer-link: {$theme['footerLink']};
      --hero-grad-a: {$theme['heroA']};
      --hero-grad-b: {$theme['heroB']};
    }
    * { box-sizing:border-box; }
    html { scroll-behavior:smooth; }
    body {
      margin:0;
      font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
      color:var(--text);
      background:radial-gradient(circle at top right, rgba(255,255,255,.45), transparent 36%), var(--bg);
      line-height:1.6;
    }
    .container { width:min(1080px, 92vw); margin:0 auto; }
    .header { position:sticky; top:0; z-index:20; background:#fff; border-bottom:1px solid var(--border); }
    .header-inner { display:flex; justify-content:space-between; align-items:center; padding:14px 0; gap:16px; }
    .brand { font-weight:700; color:var(--primary); text-decoration:none; font-size:1.1rem; }
    .nav { display:flex; gap:18px; }
    .nav a { color:var(--text); text-decoration:none; font-weight:600; font-size:.95rem; }
    .hero { padding:56px 0 30px; }
    .hero-card {
      background:linear-gradient(135deg, var(--hero-grad-a), var(--hero-grad-b));
      border:1px solid var(--border);
      border-radius:20px;
      padding:28px;
      box-shadow:0 12px 30px rgba(0,0,0,.08);
    }
    h1,h2,h3 { margin:0 0 10px; line-height:1.3; }
    h1 { font-size:clamp(1.6rem, 4vw, 2.4rem); }
    h2 { font-size:clamp(1.3rem, 3vw, 1.8rem); margin-top:14px; }
    p { margin:0 0 10px; color:var(--muted); }
    .btn {
      display:inline-block;
      margin-top:12px;
      background:var(--primary);
      color:#fff;
      text-decoration:none;
      border-radius:12px;
      padding:10px 16px;
      font-weight:700;
      box-shadow:0 8px 20px rgba(0,0,0,.12);
    }
    .grid { display:grid; gap:16px; grid-template-columns:repeat(12, 1fr); margin:22px 0; }
    .card {
      background:var(--surface);
      border:1px solid var(--border);
      border-radius:16px;
      padding:18px;
      box-shadow:0 8px 22px rgba(0,0,0,.06);
    }
    .col-6 { grid-column:span 6; } .col-12 { grid-column:span 12; } .col-4 { grid-column:span 4; }
    ul { margin:0; padding-left:18px; color:var(--muted); }
    li { margin:6px 0; }
    .footer { background:var(--footer-bg); color:var(--footer-text); margin-top:28px; }
    .footer a { color:var(--footer-link); text-decoration:none; }
    .footer-top { display:grid; grid-template-columns:2fr 1fr 1fr; gap:20px; padding:28px 0 18px; }
    .footer h3 { color:#fff; font-size:1.05rem; margin-bottom:8px; }
    .social { display:flex; gap:10px; flex-wrap:wrap; }
    .social span {
      display:inline-flex;
      width:34px;
      height:34px;
      align-items:center;
      justify-content:center;
      border:1px solid var(--footer-border);
      border-radius:999px;
      font-size:12px;
    }
    .footer-bottom { border-top:1px solid var(--footer-border); padding:14px 0 22px; font-size:.9rem; color:var(--footer-text); }
    @media (max-width: 900px) {
      .col-6, .col-4 { grid-column:span 12; }
      .footer-top { grid-template-columns:1fr; }
      .nav { gap:12px; }
    }
  </style>
</head>
<body>
  <header id="home" class="header">
    <div class="container header-inner">
      <a class="brand" href="#home">{$company}</a>
      <nav class="nav" aria-label="Navigasi utama">
        <a href="#tentang">Tentang</a>
        <a href="#layanan">Layanan</a>
        <a href="#kontak">Kontak</a>
      </nav>
    </div>
  </header>

  <main class="container">
    <section class="hero">
      <div class="hero-card">
        <h1>{$company}</h1>
        <p><strong>{$industry}</strong></p>
        <p>{$tagline}</p>
        <a class="btn" href="#kontak">{$cta}</a>
      </div>
    </section>

    <section id="tentang" class="grid">
      <div class="card col-6">
        <h2>Tentang Kami</h2>
        <p>{$overview}</p>
      </div>
      <div class="card col-6">
        <h2>Visi</h2>
        <p>{$vision}</p>
        <h2>Misi</h2>
        <ul>{$mission}</ul>
      </div>
      <div class="card col-6">
        <h2>Target Market</h2>
        <p>{$target}</p>
      </div>
      <div class="card col-6">
        <h2>Keunggulan Kami</h2>
        <p>{$uvp}</p>
      </div>
    </section>

    <section id="layanan" class="grid">
      <div class="card col-12">
        <h2>Layanan Utama</h2>
        <ul>{$services}</ul>
      </div>
      <div class="card col-6">
        <h2>Pencapaian</h2>
        <ul>{$achievements}</ul>
      </div>
      <div class="card col-6">
        <h2>Portfolio</h2>
        <ul>{$portfolio}</ul>
      </div>
      <div class="card col-12">
        <h2>Tim</h2>
        <p>{$team}</p>
      </div>
    </section>
  </main>

  <footer id="kontak" class="footer">
    <div class="container footer-top">
      <div>
        <h3>{$company}</h3>
        <p>Partner tepercaya untuk kebutuhan {$industry} dengan eksekusi yang terukur.</p>
      </div>
      <div>
        <h3>Navigasi</h3>
        <p><a href="#home">Home</a></p>
        <p><a href="#tentang">Tentang</a></p>
        <p><a href="#layanan">Layanan</a></p>
        <p><a href="#kontak">Kontak</a></p>
      </div>
      <div>
        <h3>Kontak</h3>
        <p>Email: {$email}</p>
        <p>Telepon: {$phone}</p>
        <p>Alamat: {$address}</p>
        <p>Sosial: {$social}</p>
        <div class="social"><span>IG</span><span>IN</span><span>YT</span></div>
      </div>
    </div>
    <div class="container footer-bottom">© 2026 {$company}. All rights reserved.</div>
  </footer>
</body>
</html>
HTML;
    }

    private function pickFallbackTheme(): array
    {
        $themes = [
            [
                'primary' => '#1456D9',
                'text' => '#111827',
                'muted' => '#4b5563',
                'bg' => '#f3f4f6',
                'surface' => '#ffffff',
                'border' => '#e5e7eb',
                'footerBg' => '#0b1220',
                'footerText' => '#94a3b8',
                'footerBorder' => '#1e293b',
                'footerLink' => '#e2e8f0',
                'heroA' => '#ffffff',
                'heroB' => '#eff6ff',
            ],
            [
                'primary' => '#0f766e',
                'text' => '#102a2a',
                'muted' => '#3f5757',
                'bg' => '#ecfeff',
                'surface' => '#f8ffff',
                'border' => '#cbe9e8',
                'footerBg' => '#022c2b',
                'footerText' => '#a7f3d0',
                'footerBorder' => '#134e4a',
                'footerLink' => '#d1fae5',
                'heroA' => '#f0fdfa',
                'heroB' => '#ccfbf1',
            ],
            [
                'primary' => '#a16207',
                'text' => '#2b2110',
                'muted' => '#66563a',
                'bg' => '#fffbeb',
                'surface' => '#fffef8',
                'border' => '#f3e4c1',
                'footerBg' => '#2b2110',
                'footerText' => '#f5deb3',
                'footerBorder' => '#7c5a1e',
                'footerLink' => '#fde68a',
                'heroA' => '#fffbeb',
                'heroB' => '#fef3c7',
            ],
        ];

        return $themes[array_rand($themes)];
    }

    private function e(string $text): string
    {
        return htmlspecialchars(trim($text), ENT_QUOTES, 'UTF-8');
    }

    private function normalizeColor(string $color): string
    {
        $c = trim($color);
        if ($c === '' || !preg_match('/^#[0-9a-fA-F]{6}$/', $c)) {
            return '#1456D9';
        }

        return $c;
    }

    private function listFromText(string $text): string
    {
        $rows = preg_split('/\r\n|\r|\n|,|;/', (string) $text);
        $rows = array_values(array_filter(array_map(fn ($r) => trim($r), $rows), fn ($r) => $r !== ''));

        if (count($rows) === 0) {
            $rows = ['Komitmen kualitas layanan', 'Respon cepat dan terstruktur', 'Fokus pada hasil bisnis klien'];
        }

        return implode('', array_map(fn ($r) => '<li>' . $this->e($r) . '</li>', array_slice($rows, 0, 6)));
    }

    private function callGeminiHtmlWithFallback(
        string $prompt,
        string $apiKey,
        string $preferredModel,
        string $fallbackModel = ''
    ): string
    {
        $models = array_values(array_unique(array_filter([
            $preferredModel,
            trim($fallbackModel) !== '' ? trim($fallbackModel) : null,
        ])));

        $last = null;

        foreach ($models as $idx => $model) {
            if ($idx > 0) {
                Log::warning('Gemini fallback model used', [
                    'from' => $preferredModel,
                    'to' => $model,
                ]);
            }

            $maxAttempts = (int) env('GEMINI_RETRYABLE_ATTEMPTS', 2);
            $maxAttempts = max(1, min($maxAttempts, 3));
            $baseDelayMs = (int) env('GEMINI_RETRY_DELAY_MS', 1800);
            $baseDelayMs = max(500, min($baseDelayMs, 6000));

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    return $this->callGeminiHtml($prompt, $apiKey, $model);
                } catch (ValidationException $e) {
                    $last = $e;
                    $msg = $this->extractValidationMessage($e);

                    Log::warning('Gemini generate failed', [
                        'model' => $model,
                        'attempt' => $attempt,
                        'message' => $msg,
                    ]);

                    if (str_contains(strtolower($msg), 'limit: 0, model:')) {
                        throw ValidationException::withMessages([
                            'ai' => [
                                "Model {$model} belum punya kuota di project API key ini (limit: 0). " .
                                "Pakai model lain yang tersedia atau set billing/kuota di Google AI Studio."
                            ],
                        ]);
                    }

                    $shouldRetrySameModel = $attempt < $maxAttempts
                        && $this->isRetryableAiError($msg)
                        && $this->isTemporaryHighDemandError($msg);

                    if ($shouldRetrySameModel) {
                        usleep($baseDelayMs * $attempt * 1000);
                        continue;
                    }

                    if ($idx === count($models) - 1 || !$this->isRetryableAiError($msg)) {
                        throw $e;
                    }

                    break;
                }
            }
        }

        if ($last instanceof ValidationException) {
            throw $last;
        }

        throw ValidationException::withMessages([
            'ai' => ['Generate gagal. Coba lagi sebentar.'],
        ]);
    }

    private function extractValidationMessage(ValidationException $e): string
    {
        $errors = $e->errors();
        if (isset($errors['ai'][0])) {
            return (string) $errors['ai'][0];
        }

        return (string) $e->getMessage();
    }

    private function shouldServeFallbackHtml(string $message): bool
    {
        if (!$this->isFallbackEnabled()) {
            return false;
        }

        $text = strtolower($message);

        return $this->isRetryableAiError($message)
            || str_contains($text, 'quota exceeded')
            || str_contains($text, 'exceeded your current quota')
            || str_contains($text, 'retry in')
            || str_contains($text, 'high demand')
            || str_contains($text, 'try again later');
    }

    private function isFallbackEnabled(): bool
    {
        $value = strtolower(trim((string) env('AI_FALLBACK_ENABLED', 'true')));

        return !in_array($value, ['0', 'false', 'off', 'no'], true);
    }

    private function isRetryableAiError(string $message): bool
    {
        $text = strtolower($message);

        return str_contains($text, 'timeout')
            || str_contains($text, 'terputus')
            || str_contains($text, 'too many requests')
            || str_contains($text, 'resource exhausted')
            || str_contains($text, 'high demand')
            || str_contains($text, 'try again later')
            || str_contains($text, 'http 429')
            || str_contains($text, 'http 500')
            || str_contains($text, 'http 503');
    }

    private function isTemporaryHighDemandError(string $message): bool
    {
        $text = strtolower($message);

        return str_contains($text, 'high demand')
            || str_contains($text, 'spikes in demand')
            || str_contains($text, 'try again later')
            || str_contains($text, 'resource exhausted')
            || str_contains($text, 'too many requests')
            || str_contains($text, 'http 429')
            || str_contains($text, 'http 503');
    }

    private function callGeminiHtml(string $prompt, string $apiKey, string $model): string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        $timeoutSeconds = (int) env('GEMINI_TURN_TIMEOUT_SECONDS', 40);
        $timeoutSeconds = max(12, min($timeoutSeconds, 90));
        $maxOutputTokens = (int) env('GEMINI_MAX_OUTPUT_TOKENS', 3600);
        $maxOutputTokens = max(1200, min($maxOutputTokens, 8192));

        try {
            /** @var Response $resp */
            $resp = Http::connectTimeout(15)
                ->timeout($timeoutSeconds)
                ->retry(0, 0)
                ->acceptJson()
                ->asJson()
                ->post($url . '?key=' . $apiKey, [
                    'contents' => [
                        ['role' => 'user', 'parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.45,
                        'maxOutputTokens' => $maxOutputTokens,
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

        $full = $this->extractTextFromGeminiResponse($resp);
        $full = str_replace('<!-- END -->', '', $full);
        $full = trim($full);

        if ($full === '') {
            throw ValidationException::withMessages([
                'ai' => ['AI tidak mengembalikan HTML.'],
            ]);
        }

        if (!$this->looksLikeCompleteHtml($full)) {
            $bestEffort = $full;

            if ($this->shouldAttemptIncompleteRecovery($bestEffort)) {
                $continued = $this->continueIncompleteHtml($bestEffort, $apiKey, $model, $url);
                if ($this->looksLikeCompleteHtml($continued)) {
                    return $continued;
                }

                if (strlen($continued) > strlen($bestEffort)) {
                    $bestEffort = $continued;
                }
            }

            if ($this->shouldAttemptIncompleteRecovery($bestEffort)) {
                $repaired = $this->repairIncompleteHtml($bestEffort, $apiKey, $url);
                if ($this->looksLikeCompleteHtml($repaired)) {
                    return $repaired;
                }

                if (strlen($repaired) > strlen($bestEffort)) {
                    $bestEffort = $repaired;
                }
            }

            $finalized = $this->finalizeHtmlBestEffort($bestEffort);
            if ($this->looksLikeCompleteHtml($finalized)) {
                return $finalized;
            }

            $wrapped = $this->forceHtmlWrapper($finalized !== '' ? $finalized : $bestEffort);
            if ($this->looksLikeCompleteHtml($wrapped)) {
                return $wrapped;
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
Lengkapi HTML berikut karena output sebelumnya terpotong.

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
                ->timeout(35)
                ->retry(0, 0)
                ->acceptJson()
                ->asJson()
                ->post($url . '?key=' . $apiKey, [
                    'contents' => [
                        ['role' => 'user', 'parts' => [['text' => $repairPrompt]]],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'maxOutputTokens' => 2800,
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

    private function continueIncompleteHtml(string $partialHtml, string $apiKey, string $model, string $url): string
    {
        $full = trim($partialHtml);
        $maxTurns = 2;

        for ($i = 0; $i < $maxTurns; $i++) {
            if ($this->looksLikeCompleteHtml($full)) {
                return $full;
            }

            $prompt = <<<PROMPT
Lanjutkan HTML ini tepat dari karakter terakhir.
- Jangan ulangi dari awal.
- Hanya kirim sisa yang belum ada sampai penutup lengkap.
- Akhiri dengan `</body></html><!-- END -->`.

HTML SAAT INI:
{$full}
PROMPT;

            try {
                /** @var Response $resp */
                $resp = Http::connectTimeout(15)
                    ->timeout(30)
                    ->retry(0, 0)
                    ->acceptJson()
                    ->asJson()
                    ->post($url . '?key=' . $apiKey, [
                        'contents' => [
                            ['role' => 'user', 'parts' => [['text' => $prompt]]],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.2,
                            'maxOutputTokens' => 2000,
                        ],
                    ]);
            } catch (ConnectionException $e) {
                return $full;
            }

            if (!$resp instanceof Response || $resp->failed()) {
                return $full;
            }

            $tail = trim(str_replace('<!-- END -->', '', $this->extractTextFromGeminiResponse($resp)));
            if ($tail === '') {
                return $full;
            }

            if (preg_match('/<!doctype html>/i', $tail) === 1) {
                $full = $tail;
            } else {
                $full .= $tail;
            }
        }

        return trim($full);
    }

    private function shouldAttemptIncompleteRecovery(string $html): bool
    {
        $trimmed = trim(str_replace('<!-- END -->', '', $html));
        if ($trimmed === '') {
            return false;
        }

        $minChars = (int) env('GEMINI_RECOVERY_MIN_CHARS', 1800);
        $minChars = max(600, min($minChars, 6000));

        if (strlen($trimmed) < $minChars) {
            return false;
        }

        $signals = 0;
        $signals += preg_match('/<!doctype html>/i', $trimmed) === 1 ? 1 : 0;
        $signals += preg_match('/<html\b/i', $trimmed) === 1 ? 1 : 0;
        $signals += preg_match('/<body\b/i', $trimmed) === 1 ? 1 : 0;
        $signals += preg_match('/<style\b/i', $trimmed) === 1 ? 1 : 0;
        $signals += preg_match('/<(main|section|header|footer)\b/i', $trimmed) === 1 ? 1 : 0;

        return $signals >= 2;
    }

    private function finalizeHtmlBestEffort(string $html): string
    {
        $out = trim(str_replace('<!-- END -->', '', $html));
        if ($out === '') {
            return $out;
        }

        if (preg_match('/<body\b/i', $out) === 1 && preg_match('/<\/body>/i', $out) !== 1) {
            $out .= "\n</body>";
        }

        if (preg_match('/<html\b/i', $out) === 1 && preg_match('/<\/html>/i', $out) !== 1) {
            $out .= "\n</html>";
        }

        return trim($out);
    }

    private function forceHtmlWrapper(string $html): string
    {
        $out = trim(str_replace('<!-- END -->', '', $html));
        if ($out === '') {
            return $out;
        }

        if (preg_match('/<!doctype html>/i', $out) !== 1) {
            $out = "<!doctype html>\n" . $out;
        }

        if (preg_match('/<html\b/i', $out) !== 1) {
            $out = "<html lang=\"id\">\n" . $out;
        }

        if (preg_match('/<body\b/i', $out) !== 1) {
            if (preg_match('/<head\b[\s\S]*?<\/head>/i', $out) === 1) {
                $out = preg_replace('/<\/head>/i', "</head>\n<body>", $out, 1) ?? $out;
            } else {
                $out = preg_replace('/<html[^>]*>/i', "$0\n<body>", $out, 1) ?? $out;
            }
        }

        if (preg_match('/<\/body>/i', $out) !== 1) {
            $out .= "\n</body>";
        }

        if (preg_match('/<\/html>/i', $out) !== 1) {
            $out .= "\n</html>";
        }

        return trim($out);
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
Kamu adalah senior web developer + direct response copywriter. Buat 1 file landing page HTML yang modern, rapi, dan fokus konversi penjualan produk.

ATURAN OUTPUT:
- Output HARUS HANYA HTML lengkap mulai dari `<!doctype html>`, tanpa markdown atau penjelasan.
- Semua CSS wajib di dalam `<style>`, tanpa CDN/framework eksternal.
- Layout wajib boxed seperti sales page premium: background luar abu-abu, 1 kolom utama putih di tengah, seluruh section berada di kolom ini.
- Di desktop/tablet gunakan `max-width` sekitar 760px-860px dan center; di mobile tetap nyaman penuh dengan padding aman.
- Wajib ada fondasi CSS yang lengkap: `*{box-sizing:border-box}`, variabel warna di `:root`, style global `body`, heading, paragraph, link, button, input, textarea, card, dan layout grid/flex yang tidak overflow horizontal.
- Semua elemen form dan tombol harus fully styled, bukan style default browser.
- Gunakan token tombol `--btn-bg`, `--btn-text`, `--btn-hover-bg`, `--btn-hover-text`, dan pastikan kontras tombol aman pada state normal, hover, dan focus.
- CTA harus konsisten, terasa premium, dan center secara visual.
- Untuk section fitur/benefit/card, jangan gunakan `<img>`; gunakan icon saja (inline SVG, emoji, atau karakter) di badge yang rapi dan konsisten.
- Jika butuh visual hero, prioritaskan ilustrasi CSS/SVG inline, bukan gambar eksternal.
- FAQ wajib pakai struktur ini: `<details class="faq-item"><summary>...</summary><div class="faq-answer">...</div></details>` dengan state terbuka yang jelas, rapi, dan aman di mobile.
- Nuansa visual harus seperti landing page sales yang meyakinkan: headline kuat, offer jelas, CTA berulang, trust section, ritme konten rapat, dan copy yang menjual.
- Jangan meniru brand atau menyalin referensi mentah; buat desain dan copy orisinal dengan rasa yang setara.
- Jangan buat navbar, top menu, atau hamburger menu.
- Jangan membuat komponen melebar 100vw.
- Wajib responsive di desktop dan mobile.

SECTION WAJIB:
1) Hero dengan label promo, headline penawaran, subheadline, CTA utama, mini trust badge.
2) Problem -> Solution yang membahas pain point audiens dan solusi produk.
3) Siapa yang cocok untuk produk ini, minimal 3 persona.
4) Benefit list, minimal 4 poin.
5) Detail produk/program.
6) Paket/harga + promo/urgency + bonus bila data tersedia.
7) Testimoni atau social proof, minimal 3 testimoni realistis.
8) FAQ, minimal 5 pertanyaan.
9) Form lead/order bernuansa jualan dengan field nama, no whatsapp, kebutuhan, dan tombol CTA "{$cta}".
10) Footer berisi kontak dan disclaimer ringkas.

ARAH COPY:
- Bahasa Indonesia dengan tone: {$tone}.
- Gunakan pola copy sales page: label promo di hero, narasi masalah audiens, bagian siapa yang cocok, bonus eksklusif, harga coret vs harga promo bila relevan, FAQ, dan CTA penutup.
- Copy harus terasa menjual produk, bukan company profile.
- Sisipkan urgency yang natural seperti kuota, periode promo, atau bonus terbatas tanpa terkesan menakut-nakuti.
- Jika contact tersedia, tampilkan di footer.

CHECKLIST AKHIR:
- Tidak ada input, button, atau textarea yang tampil default browser.
- Semua CTA terlihat center dan terbaca jelas.
- Fitur/benefit hanya memakai icon, bukan `<img>`.
- FAQ tampil rapi dan berfungsi di mobile maupun desktop.
- Tidak ada teks yang keluar container atau terpotong di mobile.
- Struktur section lengkap, spacing konsisten, dan akhiri output dengan string persis `<!-- END -->`.

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

Buat hasil yang siap pakai, masuk akal, tanpa placeholder seperti "lorem ipsum", dan jangan berhenti sebelum menulis `<!-- END -->`.
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
