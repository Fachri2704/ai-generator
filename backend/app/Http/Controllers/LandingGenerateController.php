<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class LandingGenerateController extends Controller
{
    private const GENERATE_CACHE_VERSION = 'v16';
    private const MODEL_COOLDOWN_CACHE_PREFIX = 'gemini_model_cooldown:';

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
            'force_refresh'=> 'nullable|boolean',
        ]);
        $forceRefresh = (bool) ($data['force_refresh'] ?? false);
        unset($data['force_refresh']);

        $apiKey = (string) config('services.gemini.key');
        $model  = (string) config('services.gemini.model', 'gemini-2.0-flash');
        $fallbackModel = (string) config('services.gemini.fallback_model', '');

        if (trim($apiKey) === '') {
            throw ValidationException::withMessages([
                'ai' => ['GEMINI_API_KEY belum di-set di .env / config/services.php.'],
            ]);
        }

        $prompt = $this->buildPrompt($data);
        $cacheKey = $this->makeGenerateCacheKey('landing', $data, $model);

        if (!$forceRefresh && ($cachedResponse = $this->getCachedGenerateResponse($cacheKey))) {
            return response()->json($cachedResponse);
        }

        $origin = 'ai';
        $notice = null;

        try {
            $html = $this->callGeminiHtmlWithFallback($prompt, $apiKey, $model, $fallbackModel, true, 'landing');
        } catch (ValidationException $e) {
            $message = $this->extractValidationMessage($e);
            if ($this->shouldUseLandingFallback($message)) {
                Log::warning('Landing fallback used', [
                    'message' => $message,
                    'company' => $data['company_name'] ?? null,
                ]);
                $origin = 'fallback';
                $notice = $this->buildFallbackNotice('landing page', $message);
                $html = $this->buildLandingFallbackHtml($data);
            } else {
                throw $e;
            }
        } catch (\Throwable $e) {
            Log::error('Generate landing failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            throw ValidationException::withMessages([
                'ai' => ['Koneksi ke layanan AI terputus. Coba lagi sebentar.'],
            ]);
        }

        $this->putGenerateCacheResponse($cacheKey, $html, $origin);

        return response()->json($this->makeGenerateResponse(
            html: $html,
            cached: false,
            source: $origin,
            origin: $origin,
            notice: $notice
        ));
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
            'force_refresh'      => 'nullable|boolean',
        ]);
        $forceRefresh = (bool) ($data['force_refresh'] ?? false);
        unset($data['force_refresh']);

        $apiKey = (string) config('services.gemini.key');
        $model  = (string) config('services.gemini.model', 'gemini-2.0-flash');
        $fallbackModel = (string) config('services.gemini.fallback_model', '');

        if (trim($apiKey) === '') {
            throw ValidationException::withMessages([
                'ai' => ['GEMINI_API_KEY belum di-set di .env / config/services.php.'],
            ]);
        }

        $cacheKey = $this->makeGenerateCacheKey('company', $data, $model);

        if (!$forceRefresh && ($cachedResponse = $this->getCachedGenerateResponse($cacheKey))) {
            return response()->json($cachedResponse);
        }

        $origin = 'ai';
        $notice = null;

        $prompt = $this->buildCompanyProfilePrompt($data);

        try {
            $html = $this->callGeminiHtmlWithFallback($prompt, $apiKey, $model, $fallbackModel, false, 'company');
        } catch (ValidationException $e) {
            $message = $this->extractValidationMessage($e);
            if ($this->shouldUseCompanyProfileFallback($message)) {
                Log::warning('Company profile fallback used', [
                    'message' => $message,
                    'company' => $data['company_name'] ?? null,
                ]);
                $origin = 'fallback';
                $notice = $this->buildFallbackNotice('company profile', $message);
                $html = $this->buildCompanyProfileFallbackHtml($data);
            } else {
                throw $e;
            }
        } catch (\Throwable $e) {
            Log::error('Generate company profile failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            throw ValidationException::withMessages([
                'ai' => ['Koneksi ke layanan AI terputus. Coba lagi sebentar.'],
            ]);
        }

        $this->putGenerateCacheResponse($cacheKey, $html, $origin);

        return response()->json($this->makeGenerateResponse(
            html: $html,
            cached: false,
            source: $origin,
            origin: $origin,
            notice: $notice
        ));
    }

    private function applyExecutionLimits(): void
    {
        $seconds = (int) env('GEMINI_MAX_EXECUTION_SECONDS', 240);
        $seconds = max(60, min($seconds, 600));
        @ini_set('max_execution_time', (string) $seconds);
        @set_time_limit($seconds);
    }

    private function makeGenerateCacheKey(string $type, array $data, string $model): string
    {
        ksort($data);

        return 'gemini_generate:' . self::GENERATE_CACHE_VERSION . ':' . $type . ':' . $model . ':' . sha1(json_encode($data));
    }

    private function getCachedGenerateResponse(string $cacheKey): ?array
    {
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && trim($cached) !== '') {
            return $this->makeGenerateResponse(
                html: $cached,
                cached: true,
                source: 'cache',
                origin: 'ai',
                notice: $this->buildCacheNotice('ai')
            );
        }

        if (!is_array($cached) || !isset($cached['html'])) {
            return null;
        }

        $html = trim((string) ($cached['html'] ?? ''));
        if ($html === '') {
            return null;
        }

        $origin = (string) ($cached['origin'] ?? 'ai');

        return $this->makeGenerateResponse(
            html: $html,
            cached: true,
            source: 'cache',
            origin: $origin,
            notice: $this->buildCacheNotice($origin)
        );
    }

    private function putGenerateCacheResponse(string $cacheKey, string $html, string $origin): void
    {
        Cache::put($cacheKey, [
            'html' => $html,
            'origin' => $origin,
            'created_at' => now()->toIso8601String(),
        ], now()->addHours(12));
    }

    private function makeGenerateResponse(
        string $html,
        bool $cached,
        string $source,
        string $origin,
        ?string $notice = null
    ): array {
        return [
            'html' => $html,
            'cached' => $cached,
            'source' => $source,
            'origin' => $origin,
            'notice' => $notice,
        ];
    }

    private function buildCacheNotice(string $origin): string
    {
        if ($origin === 'fallback') {
            return 'Menampilkan hasil fallback yang sudah tersimpan agar aplikasi tetap stabil saat Gemini sedang bermasalah.';
        }

        return 'Menampilkan hasil generate yang sudah tersimpan agar lebih hemat kuota API.';
    }

    private function buildFallbackNotice(string $documentType, string $message): string
    {
        return sprintf(
            'Menggunakan template fallback %s karena %s.',
            $documentType,
            $this->summarizeFallbackReason($message)
        );
    }

    private function buildLandingFallbackHtml(array $d): string
    {
        $seed = (string) (($d['company_name'] ?? '') . '|' . ($d['product'] ?? ''));
        $variant = $this->pickFallbackVariant($seed, 3);
        $theme = $this->pickFallbackTheme();

        return match ($variant) {
            1 => $this->renderLandingFallbackTemplateScalevStyle($d, $theme),
            2 => $this->renderLandingFallbackTemplateEditorialStyle($d, $theme),
            default => $this->renderLandingFallbackTemplateStoryStyle($d, $theme),
        };

        $theme = $this->pickFallbackTheme();
        $company = $this->e($d['company_name'] ?? 'Brand Anda');
        $product = $this->e($d['product'] ?? 'Produk unggulan');
        $audience = $this->e($d['audience'] ?? 'Audiens yang tepat');
        $offer = $this->e($d['main_offer'] ?? "Solusi praktis untuk {$audience}");
        $price = $this->e($d['price_note'] ?? 'Penawaran spesial tersedia hari ini.');
        $bonus = $this->e($d['bonus'] ?? 'Bonus panduan dan pendampingan singkat.');
        $urgency = $this->e($d['urgency'] ?? 'Slot promo terbatas untuk batch saat ini.');
        $cta = $this->e($d['cta'] ?? 'Daftar Sekarang');
        $contact = $this->e($d['contact'] ?? 'Hubungi kami untuk info lebih lanjut.');
        $brandColor = $this->normalizeColor($d['brand_color'] ?? $theme['primary']);

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
      --accent: #facc15;
      --text: #0f172a;
      --muted: #475569;
      --bg: #f8fafc;
      --surface: #ffffff;
      --border: #e2e8f0;
      --btn-bg: {$brandColor};
      --btn-text: #ffffff;
      --btn-hover-bg: #0f172a;
      --btn-hover-text: #ffffff;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Arial, Helvetica, sans-serif;
      background: linear-gradient(180deg, #eef4ff 0%, var(--bg) 100%);
      color: var(--text);
      line-height: 1.6;
    }
    .page {
      width: min(820px, calc(100% - 32px));
      margin: 0 auto;
      padding: 28px 0 52px;
    }
    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 22px;
      padding: 24px;
      box-shadow: 0 18px 36px rgba(15, 23, 42, 0.08);
      margin-bottom: 18px;
    }
    .promo {
      display: inline-block;
      padding: 8px 14px;
      border-radius: 999px;
      background: #fef08a;
      color: #854d0e;
      font-weight: 700;
      font-size: 13px;
      margin-bottom: 14px;
    }
    h1, h2, h3 { margin: 0 0 12px; line-height: 1.2; }
    h1 { font-size: clamp(2rem, 4vw, 3.2rem); }
    h2 { font-size: 1.4rem; }
    p { margin: 0 0 12px; color: var(--muted); }
    .cta-wrap { text-align: center; margin-top: 22px; }
    .button {
      display: inline-block;
      text-decoration: none;
      background: var(--btn-bg);
      color: var(--btn-text);
      padding: 14px 24px;
      border-radius: 14px;
      font-weight: 700;
      box-shadow: 0 10px 24px rgba(20, 86, 217, 0.22);
    }
    .button:hover { background: var(--btn-hover-bg); color: var(--btn-hover-text); }
    .grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }
    .feature {
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 18px;
      background: #f8fbff;
    }
    .price-box {
      border: 1px solid rgba(20, 86, 217, 0.16);
      background: linear-gradient(180deg, #ffffff 0%, #eff6ff 100%);
    }
    .price-note { font-size: 1.1rem; font-weight: 700; color: var(--primary); }
    .form-box {
      display: grid;
      gap: 12px;
    }
    label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; }
    input, textarea, button {
      width: 100%;
      font: inherit;
      border-radius: 12px;
    }
    input, textarea {
      border: 1px solid var(--border);
      padding: 12px 14px;
      background: #fff;
      color: var(--text);
    }
    textarea { min-height: 120px; resize: vertical; }
    button {
      border: 0;
      background: var(--btn-bg);
      color: var(--btn-text);
      padding: 14px;
      font-weight: 700;
    }
    .faq-item {
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 14px 16px;
      background: #fff;
      margin-bottom: 10px;
    }
    footer {
      text-align: center;
      color: var(--muted);
      font-size: 14px;
      padding-top: 8px;
    }
    @media (max-width: 720px) {
      .page { width: min(100% - 20px, 820px); }
      .card { padding: 18px; border-radius: 18px; }
      .grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <main class="page">
    <section class="card">
      <span class="promo">PROMO TERBATAS</span>
      <h1>{$offer}</h1>
      <p><strong>{$company}</strong> menghadirkan {$product} untuk {$audience} dengan pendekatan yang lebih mudah dipahami, praktis, dan terarah.</p>
      <p>{$urgency}</p>
      <div class="cta-wrap">
        <a class="button" href="#form-order">{$cta}</a>
      </div>
    </section>

    <section class="card">
      <h2>Kenapa Penawaran Ini Menarik?</h2>
      <div class="grid">
        <div class="feature">
          <h3>Lebih Tepat Sasaran</h3>
          <p>Dirancang khusus untuk {$audience} dengan pesan yang fokus ke manfaat utama.</p>
        </div>
        <div class="feature">
          <h3>Lebih Mudah Dipahami</h3>
          <p>Konten disusun singkat, jelas, dan membantu calon pelanggan mengambil keputusan lebih cepat.</p>
        </div>
        <div class="feature">
          <h3>Nilai Tambah Nyata</h3>
          <p>{$bonus}</p>
        </div>
        <div class="feature">
          <h3>Siap Dipakai</h3>
          <p>Struktur halaman sudah lengkap untuk promosi, penjelasan offer, dan CTA penutup.</p>
        </div>
      </div>
    </section>

    <section class="card price-box">
      <h2>Detail Penawaran</h2>
      <p>{$product}</p>
      <p class="price-note">{$price}</p>
      <p>{$urgency}</p>
      <div class="cta-wrap">
        <a class="button" href="#form-order">{$cta}</a>
      </div>
    </section>

    <section class="card">
      <h2>FAQ Singkat</h2>
      <div class="faq-item"><strong>Apakah produk ini cocok untuk saya?</strong><p>Jika kamu termasuk {$audience}, halaman ini memang dirancang untuk kebutuhanmu.</p></div>
      <div class="faq-item"><strong>Apa manfaat utamanya?</strong><p>Fokus utama halaman ini adalah menyampaikan manfaat inti dari {$product} secara singkat namun meyakinkan.</p></div>
      <div class="faq-item"><strong>Apakah ada bonus?</strong><p>{$bonus}</p></div>
      <div class="faq-item"><strong>Apakah promo ini terbatas?</strong><p>{$urgency}</p></div>
      <div class="faq-item"><strong>Bagaimana cara menghubungi tim?</strong><p>{$contact}</p></div>
    </section>

    <section id="form-order" class="card">
      <h2>Ambil Penawaran Sekarang</h2>
      <p>Isi form singkat berikut agar tim {$company} bisa menghubungi kamu lebih cepat.</p>
      <div class="form-box">
        <div>
          <label>Nama</label>
          <input type="text" placeholder="Nama lengkap" />
        </div>
        <div>
          <label>No. WhatsApp</label>
          <input type="text" placeholder="08xxxxxxxxxx" />
        </div>
        <div>
          <label>Kebutuhan</label>
          <textarea placeholder="Ceritakan kebutuhan kamu secara singkat"></textarea>
        </div>
        <button type="button">{$cta}</button>
      </div>
    </section>

    <footer>
      <p>{$company} &bull; {$contact}</p>
      <p>&copy; 2026 {$company}. All rights reserved.</p>
    </footer>
  </main>
</body>
</html>
HTML;
    }

    private function buildCompanyProfileFallbackHtml(array $d): string
    {
        $seed = (string) (($d['company_name'] ?? '') . '|' . ($d['industry'] ?? ''));
        $variant = $this->pickFallbackVariant($seed, 3);
        $theme = $this->pickFallbackTheme();

        return match ($variant) {
            1 => $this->renderCompanyFallbackTemplateNexoraClassic($d, $theme),
            2 => $this->renderCompanyFallbackTemplateNexoraStudio($d, $theme),
            default => $this->renderCompanyFallbackTemplateNexoraEnterprise($d, $theme),
        };

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
      --accent: #f8fafc;
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
    .container { width:min(1120px, 92vw); margin:0 auto; }
    .header { position:sticky; top:0; z-index:20; backdrop-filter: blur(12px); background:rgba(255,255,255,.94); border-bottom:1px solid var(--border); }
    .header-inner { display:flex; justify-content:space-between; align-items:center; padding:14px 0; gap:16px; }
    .brand { font-weight:800; color:var(--primary); text-decoration:none; font-size:1.15rem; letter-spacing:-.02em; }
    .nav { display:flex; gap:18px; flex-wrap:wrap; justify-content:flex-end; }
    .nav a { color:var(--text); text-decoration:none; font-weight:600; font-size:.95rem; }
    .hero { padding:56px 0 18px; }
    .hero-card {
      background:linear-gradient(135deg, var(--hero-grad-a), var(--hero-grad-b));
      border:1px solid var(--border);
      border-radius:26px;
      padding:30px;
      box-shadow:0 22px 44px rgba(15,23,42,.10);
    }
    .hero-grid {
      display:grid;
      grid-template-columns:1.4fr .8fr;
      gap:20px;
      align-items:stretch;
    }
    .eyebrow {
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:8px 12px;
      border-radius:999px;
      background:#fff;
      border:1px solid rgba(255,255,255,.65);
      color:var(--primary);
      font-size:.82rem;
      font-weight:800;
      margin-bottom:14px;
    }
    h1,h2,h3 { margin:0 0 10px; line-height:1.3; }
    h1 { font-size:clamp(2rem, 4vw, 3.35rem); letter-spacing:-.03em; }
    h2 { font-size:clamp(1.35rem, 3vw, 2rem); margin-top:14px; letter-spacing:-.02em; }
    h3 { font-size:1.05rem; }
    p { margin:0 0 10px; color:var(--muted); }
    .hero-copy p { font-size:1.02rem; max-width:62ch; }
    .btn {
      display:inline-block;
      margin-top:14px;
      background:var(--primary);
      color:#fff;
      text-decoration:none;
      border-radius:14px;
      padding:12px 18px;
      font-weight:800;
      box-shadow:0 14px 30px rgba(15,23,42,.14);
    }
    .hero-panel {
      background:rgba(255,255,255,.88);
      border:1px solid var(--border);
      border-radius:22px;
      padding:22px;
      display:grid;
      gap:14px;
      align-content:start;
    }
    .kicker {
      font-size:.84rem;
      font-weight:800;
      text-transform:uppercase;
      letter-spacing:.08em;
      color:var(--primary);
    }
    .stats {
      display:grid;
      grid-template-columns:repeat(3, minmax(0, 1fr));
      gap:12px;
      margin-top:18px;
    }
    .stat {
      background:#fff;
      border:1px solid var(--border);
      border-radius:18px;
      padding:16px;
    }
    .stat strong {
      display:block;
      font-size:1.2rem;
      color:var(--text);
      margin-bottom:4px;
    }
    .grid { display:grid; gap:16px; grid-template-columns:repeat(12, 1fr); margin:22px 0; }
    .card {
      background:var(--surface);
      border:1px solid var(--border);
      border-radius:20px;
      padding:22px;
      box-shadow:0 10px 26px rgba(15,23,42,.06);
    }
    .col-3 { grid-column:span 3; } .col-4 { grid-column:span 4; } .col-5 { grid-column:span 5; }
    .col-6 { grid-column:span 6; } .col-7 { grid-column:span 7; } .col-8 { grid-column:span 8; } .col-12 { grid-column:span 12; }
    ul { margin:0; padding-left:18px; color:var(--muted); }
    li { margin:6px 0; }
    .section-head { display:flex; justify-content:space-between; gap:12px; align-items:end; margin-bottom:12px; }
    .section-head p { max-width:52ch; }
    .chip-row { display:flex; flex-wrap:wrap; gap:10px; margin-top:14px; }
    .chip {
      display:inline-flex;
      align-items:center;
      padding:8px 12px;
      border-radius:999px;
      background:var(--accent);
      border:1px solid var(--border);
      color:var(--text);
      font-size:.88rem;
      font-weight:700;
    }
    .service-list, .portfolio-list, .achievement-list {
      display:grid;
      gap:12px;
      margin-top:12px;
      padding:0;
      list-style:none;
    }
    .service-list li, .portfolio-list li, .achievement-list li {
      margin:0;
      padding:14px 16px;
      border:1px solid var(--border);
      border-radius:16px;
      background:linear-gradient(180deg, rgba(255,255,255,.95), rgba(248,250,252,.92));
    }
    .timeline {
      display:grid;
      gap:12px;
      margin-top:12px;
    }
    .timeline-item {
      padding:14px 16px;
      border-left:3px solid var(--primary);
      background:#fff;
      border-radius:0 14px 14px 0;
      border:1px solid var(--border);
      border-left-color:var(--primary);
    }
    .contact-grid {
      display:grid;
      grid-template-columns:1.05fr .95fr;
      gap:18px;
      margin:22px 0 8px;
    }
    .contact-list { display:grid; gap:12px; }
    .contact-item {
      padding:14px 16px;
      border:1px solid var(--border);
      border-radius:16px;
      background:#fff;
    }
    .contact-item strong {
      display:block;
      margin-bottom:4px;
      color:var(--text);
    }
    .form-box { display:grid; gap:12px; }
    label { display:block; font-size:.92rem; font-weight:700; color:var(--text); margin-bottom:6px; }
    input, textarea, button {
      width:100%;
      font:inherit;
      border-radius:14px;
    }
    input, textarea {
      border:1px solid var(--border);
      padding:12px 14px;
      background:#fff;
      color:var(--text);
    }
    textarea { min-height:140px; resize:vertical; }
    button {
      border:0;
      padding:13px 16px;
      background:var(--primary);
      color:#fff;
      font-weight:800;
      box-shadow:0 14px 26px rgba(15,23,42,.14);
    }
    .cta-card {
      background:linear-gradient(135deg, rgba(255,255,255,.98), rgba(239,246,255,.94));
    }
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
    @media (max-width: 980px) {
      .hero-grid, .contact-grid { grid-template-columns:1fr; }
      .stats { grid-template-columns:1fr; }
      .col-8, .col-7, .col-6, .col-5, .col-4, .col-3 { grid-column:span 12; }
      .footer-top { grid-template-columns:1fr; }
      .nav { gap:12px; }
    }
    @media (max-width: 640px) {
      .container { width:min(100% - 20px, 1120px); }
      .header-inner { flex-direction:column; align-items:flex-start; }
      .nav { justify-content:flex-start; }
      .hero-card, .card { padding:18px; border-radius:18px; }
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
        <a href="#portfolio">Portfolio</a>
        <a href="#tim">Tim</a>
        <a href="#kontak">Kontak</a>
      </nav>
    </div>
  </header>

  <main class="container">
    <section class="hero">
      <div class="hero-card">
        <div class="hero-grid">
          <div class="hero-copy">
            <span class="eyebrow">Company Profile</span>
            <h1>{$tagline}</h1>
            <p><strong>{$company}</strong> adalah mitra {$industry} yang membantu bisnis membangun fondasi digital yang lebih rapi, terukur, dan siap berkembang dalam jangka panjang.</p>
            <div class="chip-row">
              <span class="chip">Fokus Industri: {$industry}</span>
              <span class="chip">Target: {$target}</span>
            </div>
            <a class="btn" href="#kontak">{$cta}</a>
          </div>
          <aside class="hero-panel">
            <div>
              <div class="kicker">Ringkasan</div>
              <p>{$overview}</p>
            </div>
            <div>
              <div class="kicker">Unique Value</div>
              <p>{$uvp}</p>
            </div>
          </aside>
        </div>
        <div class="stats">
          <div class="stat">
            <strong>01</strong>
            <span>Strategi digital yang disusun sesuai kebutuhan bisnis.</span>
          </div>
          <div class="stat">
            <strong>02</strong>
            <span>Eksekusi yang cepat, rapi, dan mudah dipresentasikan ke klien.</span>
          </div>
          <div class="stat">
            <strong>03</strong>
            <span>Pendampingan yang membuat proses implementasi lebih jelas.</span>
          </div>
        </div>
      </div>
    </section>

    <section id="tentang" class="grid">
      <div class="card col-7">
        <div class="section-head">
          <div>
            <h2>Tentang Kami</h2>
            <p>Profil singkat perusahaan dan arah pengembangan layanan.</p>
          </div>
        </div>
        <p>{$overview}</p>
      </div>
      <div class="card col-5">
        <div class="section-head">
          <div>
            <h2>Target Market</h2>
            <p>Segmen bisnis yang menjadi fokus utama layanan kami.</p>
          </div>
        </div>
        <p>{$target}</p>
      </div>
      <div class="card col-6">
        <h2>Visi</h2>
        <p>{$vision}</p>
      </div>
      <div class="card col-6">
        <h2>Misi</h2>
        <ul>{$mission}</ul>
      </div>
    </section>

    <section id="layanan" class="grid">
      <div class="card col-7">
        <div class="section-head">
          <div>
            <h2>Layanan Utama</h2>
            <p>Layanan inti yang dirancang untuk membantu bisnis bertumbuh secara terukur.</p>
          </div>
        </div>
        <ul class="service-list">{$services}</ul>
      </div>
      <div class="card col-5">
        <div class="section-head">
          <div>
            <h2>Keunggulan Kami</h2>
            <p>Nilai tambah yang membuat kolaborasi lebih efektif.</p>
          </div>
        </div>
        <div class="timeline">
          <div class="timeline-item">Pendekatan konsultatif yang menyesuaikan kebutuhan bisnis.</div>
          <div class="timeline-item">Tim yang fokus pada hasil, bukan hanya deliverable teknis.</div>
          <div class="timeline-item">Komunikasi transparan dengan alur kerja yang mudah dipahami.</div>
        </div>
        <p style="margin-top:14px">{$uvp}</p>
      </div>
      <div id="portfolio" class="card col-6">
        <div class="section-head">
          <div>
            <h2>Portfolio</h2>
            <p>Contoh fokus proyek dan implementasi yang pernah ditangani.</p>
          </div>
        </div>
        <ul class="portfolio-list">{$portfolio}</ul>
      </div>
      <div class="card col-6">
        <div class="section-head">
          <div>
            <h2>Pencapaian</h2>
            <p>Indikator yang menunjukkan konsistensi kinerja perusahaan.</p>
          </div>
        </div>
        <ul class="achievement-list">{$achievements}</ul>
      </div>
      <div id="tim" class="card col-12">
        <h2>Tim</h2>
        <p>{$team}</p>
      </div>
    </section>

    <section class="grid">
      <div class="card cta-card col-12">
        <div class="section-head">
          <div>
            <h2>Siap Berkolaborasi dengan {$company}</h2>
            <p>Kami membuka peluang kerja sama untuk bisnis yang ingin memperkuat identitas digital, meningkatkan efisiensi operasional, dan menyiapkan sistem yang lebih scalable.</p>
          </div>
        </div>
        <a class="btn" href="#kontak">{$cta}</a>
      </div>
    </section>

    <section id="kontak" class="contact-grid">
      <div class="card">
        <div class="section-head">
          <div>
            <h2>Kontak Perusahaan</h2>
            <p>Hubungi kami untuk diskusi kebutuhan bisnis, konsultasi awal, atau permintaan penawaran kerja sama.</p>
          </div>
        </div>
        <div class="contact-list">
          <div class="contact-item"><strong>Email</strong><span>{$email}</span></div>
          <div class="contact-item"><strong>Telepon / WhatsApp</strong><span>{$phone}</span></div>
          <div class="contact-item"><strong>Alamat</strong><span>{$address}</span></div>
          <div class="contact-item"><strong>Media Sosial / Link</strong><span>{$social}</span></div>
        </div>
      </div>
      <div class="card">
        <div class="section-head">
          <div>
            <h2>Kirim Pesan</h2>
            <p>Form singkat untuk memulai komunikasi dengan tim kami.</p>
          </div>
        </div>
        <form class="form-box">
          <div>
            <label>Nama</label>
            <input type="text" placeholder="Nama lengkap" />
          </div>
          <div>
            <label>Email</label>
            <input type="email" placeholder="nama@perusahaan.com" />
          </div>
          <div>
            <label>Pesan</label>
            <textarea placeholder="Ceritakan kebutuhan bisnis Anda"></textarea>
          </div>
          <button type="button">{$cta}</button>
        </form>
      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="container footer-top">
      <div>
        <h3>{$company}</h3>
        <p>{$company} adalah partner tepercaya untuk kebutuhan {$industry} dengan strategi yang relevan, eksekusi yang terukur, dan pendekatan kolaboratif.</p>
      </div>
      <div>
        <h3>Navigasi</h3>
        <p><a href="#home">Home</a></p>
        <p><a href="#tentang">Tentang</a></p>
        <p><a href="#layanan">Layanan</a></p>
        <p><a href="#portfolio">Portfolio</a></p>
        <p><a href="#tim">Tim</a></p>
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
    <div class="container footer-bottom">&copy; 2026 {$company}. All rights reserved.</div>
  </footer>
</body>
</html>
HTML;
    }

    private function pickFallbackVariant(string $seed, int $count): int
    {
        $normalizedSeed = trim($seed) !== '' ? $seed : 'fallback-template';
        $hash = (int) sprintf('%u', crc32($normalizedSeed));

        return ($hash % max(1, $count)) + 1;
    }

    private function textItems(string $text, array $fallback, int $limit = 6): array
    {
        $rows = preg_split('/\r\n|\r|\n|,|;/', (string) $text);
        $rows = array_values(array_filter(array_map(fn ($r) => trim($r), $rows ?: []), fn ($r) => $r !== ''));

        if ($rows === []) {
            $rows = $fallback;
        }

        return array_slice($rows, 0, $limit);
    }

    private function htmlListItems(array $items): string
    {
        return implode('', array_map(fn ($item) => '<li>' . $this->e($item) . '</li>', $items));
    }

    private function htmlCardItems(array $items, string $className): string
    {
        return implode('', array_map(fn ($item) => '<article class="' . $className . '"><p>' . $this->e($item) . '</p></article>', $items));
    }

    private function buildLandingFallbackPayload(array $d, array $theme): array
    {
        $company = $this->e($d['company_name'] ?? 'Brand Anda');
        $product = $this->e($d['product'] ?? 'Produk unggulan');
        $audience = $this->e($d['audience'] ?? 'Audiens yang tepat');
        $offer = $this->e($d['main_offer'] ?? "Solusi praktis untuk {$audience}");
        $price = $this->e($d['price_note'] ?? 'Penawaran spesial tersedia hari ini.');
        $bonus = $this->e($d['bonus'] ?? 'Bonus panduan dan pendampingan singkat.');
        $urgency = $this->e($d['urgency'] ?? 'Slot promo terbatas untuk batch saat ini.');
        $cta = $this->e($d['cta'] ?? 'Daftar Sekarang');
        $contact = $this->e($d['contact'] ?? 'Hubungi kami untuk info lebih lanjut.');
        $brandColor = $this->normalizeColor($d['brand_color'] ?? $theme['primary']);

        return compact('company', 'product', 'audience', 'offer', 'price', 'bonus', 'urgency', 'cta', 'contact', 'brandColor');
    }

    private function renderLandingFallbackTemplateScalevStyle(array $d, array $theme): string
    {
        extract($this->buildLandingFallbackPayload($d, $theme));
        $personas = $this->htmlCardItems(
            [
                "Pemilik bisnis yang ingin menjual {$product} dengan halaman yang lebih rapi.",
                "Tim marketing yang perlu materi promosi cepat untuk target {$audience}.",
                "Kreator atau admin brand yang ingin CTA lebih jelas dan konversi lebih tinggi.",
            ],
            'persona-card'
        );
        $benefits = $this->htmlCardItems(
            [
                "Pesan utama langsung fokus ke manfaat {$product}.",
                "Struktur halaman disusun agar pengunjung cepat paham lalu bertindak.",
                "Konten mudah dipakai ulang untuk campaign, iklan, dan WhatsApp blast.",
                "Tambahan bonus membuat penawaran terasa lebih bernilai.",
            ],
            'benefit-card'
        );
        $testimonials = $this->htmlCardItems(
            [
                "{$company} bantu kami menyusun penawaran lebih jelas dan closing jadi lebih cepat.",
                "Landing page ini enak dibaca, simple, dan langsung menonjolkan inti manfaat produk.",
                "Calon pelanggan lebih mudah paham offer karena isi halamannya rapi dan meyakinkan.",
            ],
            'quote-card'
        );
        $faqs = <<<HTML
<details class="faq-item"><summary>Apakah penawaran ini cocok untuk {$audience}?</summary><p>Ya. Struktur fallback ini dibuat agar pesan utamanya tetap relevan untuk {$audience} dan siap dipresentasikan.</p></details>
<details class="faq-item"><summary>Apa yang didapat pelanggan?</summary><p>Pelanggan akan mendapatkan {$product}, dengan fokus manfaat yang jelas dan penjelasan yang lebih mudah dipahami.</p></details>
<details class="faq-item"><summary>Apakah ada bonus tambahan?</summary><p>{$bonus}</p></details>
<details class="faq-item"><summary>Apakah promo ini terbatas?</summary><p>{$urgency}</p></details>
<details class="faq-item"><summary>Bagaimana cara menghubungi tim?</summary><p>{$contact}</p></details>
HTML;

        return <<<HTML
<!doctype html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{$company} - {$product}</title>
  <style>
    :root{--primary:{$brandColor};--accent:#facc15;--bg:#fbf6df;--panel:#103c2f;--surface:#fffdf7;--text:#112031;--muted:#506176;--border:rgba(17,32,49,.10);}
    *{box-sizing:border-box} body{margin:0;font-family:Arial,Helvetica,sans-serif;background:radial-gradient(circle at top,#fff7cc 0,#fbf6df 40%,#f5f0d7 100%);color:var(--text);line-height:1.6}
    .page{width:min(980px,calc(100% - 28px));margin:24px auto 56px}.card{background:var(--surface);border:1px solid var(--border);border-radius:26px;padding:24px;box-shadow:0 22px 44px rgba(16,24,40,.08);margin-bottom:18px}
    .hero{background:var(--panel);color:#fff;border-color:rgba(255,255,255,.08)} .hero p,.hero li{color:#d7e6df}.eyebrow{display:inline-flex;padding:8px 14px;border-radius:999px;background:#f6e27a;color:#3f3510;font-size:12px;font-weight:800;margin-bottom:14px}
    h1,h2,h3{margin:0 0 12px;line-height:1.15} h1{font-size:clamp(2.1rem,5vw,3.6rem);max-width:12ch} h2{font-size:clamp(1.4rem,3vw,2rem)} p{margin:0 0 12px;color:var(--muted)}
    .hero-grid,.grid,.quote-grid,.persona-grid{display:grid;gap:16px}.hero-grid{grid-template-columns:1.2fr .8fr;align-items:start}.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.quote-grid,.persona-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
    .side-card,.benefit-card,.persona-card,.quote-card,.price-card{border:1px solid var(--border);border-radius:20px;padding:18px;background:#fff}.benefit-card,.persona-card,.quote-card p{margin:0}
    .metric{display:block;font-size:1.7rem;font-weight:800;color:#fff}.btn,.btn-secondary{display:inline-block;padding:14px 20px;border-radius:14px;text-decoration:none;font-weight:800}
    .btn{background:var(--accent);color:#2a2410}.btn-secondary{background:#fff;color:var(--primary);border:1px solid rgba(255,255,255,.24)} .cta-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px}
    .price-wrap{display:grid;grid-template-columns:1fr auto;gap:18px;align-items:center}.strike{font-size:1rem;color:#8b98a8;text-decoration:line-through}.price{font-size:2rem;font-weight:900;color:var(--primary)}
    .form-box{display:grid;gap:12px} label{display:block;font-size:.92rem;font-weight:700;margin-bottom:6px} input,textarea,button{width:100%;font:inherit;border-radius:14px} input,textarea{border:1px solid var(--border);padding:12px 14px;background:#fff;color:var(--text)} textarea{min-height:128px;resize:vertical}
    button{border:0;background:var(--primary);color:#fff;padding:14px;font-weight:800}.faq-item{border:1px solid var(--border);border-radius:16px;padding:0 16px;background:#fff;margin-bottom:10px} .faq-item summary{cursor:pointer;list-style:none;padding:16px 0;font-weight:700}
    .note{font-size:.9rem;color:#6d7c8c}.footer{text-align:center;color:#68778a;font-size:.92rem;padding-top:8px}
    @media (max-width:820px){.hero-grid,.price-wrap,.grid,.quote-grid,.persona-grid{grid-template-columns:1fr}.page{width:min(100% - 20px,980px)}.card{padding:18px;border-radius:20px}}
  </style>
</head>
<body>
  <main class="page">
    <section class="card hero">
      <div class="hero-grid">
        <div>
          <span class="eyebrow">Promo Spesial</span>
          <h1>{$offer}</h1>
          <p><strong>{$company}</strong> menghadirkan {$product} untuk {$audience} dengan halaman promosi yang rapi, boxed, dan siap dipakai untuk presentasi maupun campaign.</p>
          <p>{$urgency}</p>
          <div class="cta-row">
            <a class="btn" href="#form-order">{$cta}</a>
            <a class="btn-secondary" href="#harga">Lihat Detail</a>
          </div>
        </div>
        <aside class="side-card">
          <h3>Kenapa menarik?</h3>
          <p>Penawaran utama langsung fokus ke manfaat, bonus, dan CTA sehingga pengunjung tidak bingung menentukan langkah berikutnya.</p>
          <span class="metric">1000+</span>
          <p>Struktur promosi boxed seperti reference sales page dengan ritme konten yang padat.</p>
        </aside>
      </div>
    </section>
    <section class="card"><h2>Kenapa Penawaran Ini Relevan?</h2><div class="grid">{$benefits}</div></section>
    <section class="card"><h2>Siapa yang Cocok?</h2><div class="persona-grid">{$personas}</div></section>
    <section id="harga" class="card"><div class="price-wrap"><div><h2>Promo Hari Ini</h2><p>{$product}</p><p>{$bonus}</p></div><div class="price-card"><div class="strike">Harga Normal Menyesuaikan Paket</div><div class="price">{$price}</div><p class="note">{$urgency}</p></div></div></section>
    <section class="card"><h2>Testimoni Ringkas</h2><div class="quote-grid">{$testimonials}</div></section>
    <section class="card"><h2>FAQ</h2>{$faqs}</section>
    <section id="form-order" class="card"><h2>Ambil Penawaran Sekarang</h2><p>Isi form singkat berikut agar tim {$company} bisa menghubungi kamu lebih cepat.</p><div class="form-box"><div><label>Nama</label><input type="text" placeholder="Nama lengkap" /></div><div><label>No. WhatsApp</label><input type="text" placeholder="08xxxxxxxxxx" /></div><div><label>Kebutuhan</label><textarea placeholder="Ceritakan kebutuhan kamu secara singkat"></textarea></div><button type="button">{$cta}</button></div><p class="note">Produk/layanan diproses secara digital dan tim akan menindaklanjuti melalui kontak yang tersedia.</p></section>
    <footer class="footer"><p>{$company} &bull; {$contact}</p><p>&copy; 2026 {$company}. All rights reserved.</p></footer>
  </main>
</body>
</html>
HTML;
    }

    private function renderLandingFallbackTemplateEditorialStyle(array $d, array $theme): string
    {
        return $this->renderLandingFallbackTemplateScalevStyle($d, $theme);
    }

    private function renderLandingFallbackTemplateStoryStyle(array $d, array $theme): string
    {
        return $this->renderLandingFallbackTemplateScalevStyle($d, $theme);
    }

    private function buildCompanyFallbackPayload(array $d, array $theme): array
    {
        $company = $this->e($d['company_name'] ?? 'Perusahaan Anda');
        $industry = $this->e($d['industry'] ?? 'Industri');
        $tagline = $this->e($d['tagline'] ?? 'Mitra tepercaya untuk pertumbuhan bisnis Anda.');
        $overview = $this->e($d['company_overview'] ?? 'Kami membantu bisnis berkembang dengan solusi yang terukur.');
        $vision = $this->e($d['vision'] ?? 'Menjadi perusahaan terpercaya di bidang layanan kami.');
        $target = $this->e($d['target_market'] ?? 'Perusahaan, UMKM, dan organisasi yang ingin bertumbuh lebih cepat.');
        $uvp = $this->e($d['unique_value'] ?? 'Pendekatan strategis, eksekusi cepat, dan komunikasi yang transparan.');
        $team = $this->e($d['team_info'] ?? 'Tim berpengalaman lintas disiplin untuk memastikan hasil yang konsisten.');
        $email = $this->e($d['contact_email'] ?? 'hello@company.test');
        $phone = $this->e($d['contact_phone'] ?? '08xxxxxxxxxx');
        $address = $this->e($d['address'] ?? 'Indonesia');
        $social = $this->e($d['social_links'] ?? 'LinkedIn, Instagram, Website');
        $cta = $this->e($d['cta'] ?? 'Hubungi Kami');
        $brandColor = $this->normalizeColor($d['brand_color'] ?? $theme['primary']);
        $mission = $this->textItems((string) ($d['mission'] ?? ''), [
            'Memberikan solusi digital yang relevan dan mudah diimplementasikan.',
            'Mendampingi bisnis agar proses transformasi lebih terarah.',
            'Menjaga kualitas eksekusi dengan komunikasi yang transparan.',
        ]);
        $services = $this->textItems((string) ($d['services'] ?? ''), [
            'Website Development',
            'Custom System Development',
            'UI/UX Design',
            'Maintenance & IT Support',
        ]);
        $achievements = $this->textItems((string) ($d['achievements'] ?? ''), [
            'Proyek lintas industri selesai tepat waktu.',
            'Kolaborasi jangka panjang dengan klien bisnis.',
            'Standar kerja yang rapi dan mudah dipresentasikan.',
        ]);
        $portfolio = $this->textItems((string) ($d['portfolio'] ?? ''), [
            'Website corporate untuk brand yang sedang bertumbuh.',
            'Sistem internal untuk efisiensi operasional tim.',
            'Produk digital dan dashboard untuk kebutuhan monitoring bisnis.',
        ]);

        return compact('company', 'industry', 'tagline', 'overview', 'vision', 'target', 'uvp', 'team', 'email', 'phone', 'address', 'social', 'cta', 'brandColor', 'mission', 'services', 'achievements', 'portfolio');
    }

    private function renderCompanyFallbackTemplateNexoraClassic(array $d, array $theme): string
    {
        extract($this->buildCompanyFallbackPayload($d, $theme));
        $servicesHtml = $this->htmlCardItems($services, 'service-card');
        $portfolioHtml = $this->htmlCardItems($portfolio, 'project-card');
        $achievementHtml = $this->htmlCardItems($achievements, 'stat-card');
        $missionHtml = $this->htmlListItems($mission);

        return <<<HTML
<!doctype html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{$company} - Company Profile</title>
  <style>
    :root{--primary:{$brandColor};--bg:#f5f7fb;--surface:#ffffff;--text:#0f172a;--muted:#5d6b80;--border:rgba(15,23,42,.10);--dark:#0b1220}
    *{box-sizing:border-box} html{scroll-behavior:smooth} body{margin:0;font-family:Arial,Helvetica,sans-serif;background:linear-gradient(180deg,#f5f7fb 0,#fff 100%);color:var(--text);line-height:1.65}
    .container{width:min(1120px,92vw);margin:0 auto}.header{position:sticky;top:0;z-index:20;background:rgba(255,255,255,.94);backdrop-filter:blur(12px);border-bottom:1px solid var(--border)}
    .header-inner{display:flex;justify-content:space-between;align-items:center;padding:15px 0;gap:16px}.brand{font-size:1.18rem;font-weight:800;color:var(--primary);text-decoration:none}.nav{display:flex;gap:18px;flex-wrap:wrap}
    .nav a{color:var(--text);text-decoration:none;font-weight:600}.hero{padding:54px 0 24px}.hero-card{background:linear-gradient(135deg,#0f172a 0,{$brandColor} 100%);border-radius:30px;padding:32px;color:#fff;box-shadow:0 28px 56px rgba(15,23,42,.12)}
    .hero-grid{display:grid;grid-template-columns:1.2fr .8fr;gap:22px;align-items:center}.eyebrow{display:inline-flex;padding:8px 12px;border-radius:999px;background:rgba(255,255,255,.14);font-size:12px;font-weight:800;margin-bottom:12px}
    h1,h2,h3{margin:0 0 12px;line-height:1.14} h1{font-size:clamp(2rem,4.4vw,3.6rem)} h2{font-size:clamp(1.35rem,3vw,2rem)} p{margin:0 0 12px;color:var(--muted)} .hero-card p{color:#d9e6ff}
    .btn{display:inline-block;padding:14px 20px;border-radius:14px;background:#fff;color:var(--primary);font-weight:800;text-decoration:none}
    .glass{padding:22px;border-radius:24px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18)}
    .stats,.grid,.contact-grid{display:grid;gap:16px}.stats{grid-template-columns:repeat(4,minmax(0,1fr));margin-top:22px}.grid{grid-template-columns:repeat(12,minmax(0,1fr));margin:22px 0}.contact-grid{grid-template-columns:1fr 1fr;margin:24px 0 10px}
    .card{background:var(--surface);border:1px solid var(--border);border-radius:22px;padding:22px;box-shadow:0 12px 26px rgba(15,23,42,.05)} .col-4{grid-column:span 4}.col-5{grid-column:span 5}.col-6{grid-column:span 6}.col-7{grid-column:span 7}.col-12{grid-column:span 12}
    .service-card,.project-card,.stat-card{border:1px solid var(--border);border-radius:18px;padding:18px;background:linear-gradient(180deg,#fff,#f8fbff)} .service-card p,.project-card p,.stat-card p{margin:0}
    .mission-list{padding-left:18px}.mission-list li{margin:6px 0;color:var(--muted)} .stack{display:flex;flex-wrap:wrap;gap:10px}.stack span{padding:9px 12px;border-radius:999px;background:#eef4ff;border:1px solid rgba(59,130,246,.12);font-weight:700;color:var(--text);font-size:.9rem}
    label{display:block;font-size:.92rem;font-weight:700;margin-bottom:6px} input,textarea,button{width:100%;font:inherit;border-radius:14px} input,textarea{border:1px solid var(--border);padding:12px 14px} textarea{min-height:136px;resize:vertical}
    button{border:0;background:var(--primary);color:#fff;padding:14px;font-weight:800}.footer{background:var(--dark);color:#9fb0c7;margin-top:30px}.footer a{color:#e5eefb;text-decoration:none}.footer-grid{display:grid;grid-template-columns:2fr 1fr 1fr;gap:20px;padding:28px 0}.footer-bottom{border-top:1px solid rgba(255,255,255,.10);padding:14px 0 24px;font-size:.92rem}
    @media (max-width:960px){.hero-grid,.contact-grid,.stats{grid-template-columns:1fr}.col-7,.col-6,.col-5,.col-4{grid-column:span 12}.footer-grid{grid-template-columns:1fr}}
    @media (max-width:640px){.container{width:min(100% - 20px,1120px)}.header-inner{flex-direction:column;align-items:flex-start}.hero-card,.card{padding:18px;border-radius:20px}}
  </style>
</head>
<body>
  <header class="header" id="home"><div class="container header-inner"><a class="brand" href="#home">{$company}</a><nav class="nav"><a href="#tentang">Tentang</a><a href="#layanan">Layanan</a><a href="#project">Project</a><a href="#tim">Tim</a><a href="#kontak">Kontak</a></nav></div></header>
  <main class="container">
    <section class="hero"><div class="hero-card"><div class="hero-grid"><div><h1>{$tagline}</h1><p>{$company} bergerak di bidang {$industry} dan hadir untuk membantu bisnis membangun solusi digital yang efektif, profesional, dan scalable.</p><a class="btn" href="#kontak">{$cta}</a></div><aside class="glass"><h3>Tentang Singkat</h3><p>{$overview}</p><p><strong style="color:#fff">Target Market</strong><br />{$target}</p></aside></div><div class="stats"><div class="glass"><strong>150+</strong><p>Project dan inisiatif digital</p></div><div class="glass"><strong>100+</strong><p>Kolaborasi bisnis aktif</p></div><div class="glass"><strong>5+</strong><p>Tahun pengalaman tim inti</p></div><div class="glass"><strong>24/7</strong><p>Komitmen support dan evaluasi</p></div></div></div></section>
    <section id="tentang" class="grid"><div class="card col-7"><h2>Tentang Kami</h2><p>{$overview}</p></div><div class="card col-5"><h2>Visi</h2><p>{$vision}</p><h3>Misi</h3><ul class="mission-list">{$missionHtml}</ul></div></section>
    <section id="layanan" class="grid"><div class="card col-7"><h2>Layanan Utama</h2><div class="grid" style="grid-template-columns:repeat(2,minmax(0,1fr));margin:0">{$servicesHtml}</div></div><div class="card col-5"><h2>Keunggulan</h2><p>{$uvp}</p><div class="stack"><span>React</span><span>Laravel</span><span>UI/UX</span><span>Cloud Deploy</span><span>Automation</span></div></div></section>
    <section id="project" class="grid"><div class="card col-12"><h2>Project Highlight</h2><div class="grid" style="grid-template-columns:repeat(3,minmax(0,1fr));margin:0">{$portfolioHtml}</div></div></section>
    <section id="tim" class="grid"><div class="card col-6"><h2>Leadership & Team</h2><p>{$team}</p></div><div class="card col-6"><h2>Pencapaian</h2><div class="grid" style="grid-template-columns:repeat(3,minmax(0,1fr));margin:0">{$achievementHtml}</div></div></section>
    <section id="kontak" class="contact-grid"><div class="card"><h2>Kontak Kami</h2><p>Email: {$email}</p><p>Telepon: {$phone}</p><p>Alamat: {$address}</p><p>Sosial: {$social}</p></div><div class="card"><h2>Kirim Pesan</h2><div><label>Nama</label><input type="text" placeholder="Nama lengkap" /></div><div><label>Email</label><input type="email" placeholder="nama@perusahaan.com" /></div><div><label>Pesan</label><textarea placeholder="Ceritakan kebutuhan bisnis Anda"></textarea></div><div style="margin-top:12px"><button type="button">{$cta}</button></div></div></section>
  </main>
  <footer class="footer"><div class="container footer-grid"><div><h3 style="color:#fff;margin:0 0 10px">{$company}</h3><p>{$company} adalah mitra {$industry} yang membantu bisnis bertumbuh melalui solusi digital yang relevan, rapi, dan siap dieksekusi.</p></div><div><h3 style="color:#fff;margin:0 0 10px">Navigasi</h3><p><a href="#home">Home</a></p><p><a href="#tentang">Tentang</a></p><p><a href="#layanan">Layanan</a></p><p><a href="#project">Project</a></p></div><div><h3 style="color:#fff;margin:0 0 10px">Kontak</h3><p>{$email}</p><p>{$phone}</p><p>{$address}</p></div></div><div class="container footer-bottom">&copy; 2026 {$company}. All rights reserved.</div></footer>
</body>
</html>
HTML;
    }

    private function renderCompanyFallbackTemplateNexoraStudio(array $d, array $theme): string
    {
        return $this->renderCompanyFallbackTemplateNexoraClassic($d, $theme);
    }

    private function renderCompanyFallbackTemplateNexoraEnterprise(array $d, array $theme): string
    {
        return $this->renderCompanyFallbackTemplateNexoraClassic($d, $theme);
    }

    private function pickFallbackTheme(): array
    {
        $themes = $this->companyThemeOptions();

        return $themes[array_rand($themes)];
    }

    private function companyThemeOptions(): array
    {
        return [
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

    private function compactPromptText(?string $text, int $maxLength = 220): string
    {
        $value = trim((string) $text);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(40, $maxLength - 3))) . '...';
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

    private function summarizeFallbackReason(string $message): string
    {
        $text = strtolower($message);

        if (
            str_contains($text, 'output ai belum selesai') ||
            str_contains($text, 'output ai company profile belum selesai') ||
            str_contains($text, 'output ai kepotong') ||
            str_contains($text, 'ai tidak mengembalikan html')
        ) {
            return 'output AI sebelumnya kepotong atau belum lengkap';
        }

        if (
            str_contains($text, 'too many requests') ||
            str_contains($text, 'resource exhausted') ||
            str_contains($text, 'retry in') ||
            str_contains($text, 'quota') ||
            str_contains($text, 'cooldown')
        ) {
            return 'kuota atau rate limit Gemini sedang penuh';
        }

        if (
            str_contains($text, 'high demand') ||
            str_contains($text, 'temporarily unavailable') ||
            str_contains($text, 'overloaded') ||
            str_contains($text, 'http 503')
        ) {
            return 'server Gemini sedang padat';
        }

        if (str_contains($text, 'timeout') || str_contains($text, 'terputus')) {
            return 'koneksi ke Gemini sedang timeout atau terputus';
        }

        return 'layanan AI sedang bermasalah sementara';
    }

    private function callGeminiHtmlWithFallback(
        string $prompt,
        string $apiKey,
        string $preferredModel,
        string $fallbackModel = '',
        bool $ensureLandingStructure = false,
        string $documentType = 'landing'
    ): string
    {
        $models = array_values(array_unique(array_filter([
            $preferredModel,
            trim($fallbackModel) !== '' ? trim($fallbackModel) : null,
        ])));

        $last = null;
        $cooldowns = [];
        $maxAttempts = max(1, min((int) env('GEMINI_MODEL_ATTEMPTS', 2), 3));

        foreach ($models as $idx => $model) {
            $cooldownSeconds = $this->getModelCooldownSeconds($model);
            if ($cooldownSeconds !== null) {
                $cooldowns[$model] = $cooldownSeconds;
                Log::warning('Gemini model skipped because cooldown is active', [
                    'model' => $model,
                    'retry_in_seconds' => $cooldownSeconds,
                ]);
                continue;
            }

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    if ($idx > 0 && $attempt === 1) {
                        Log::warning('Gemini fallback model used', [
                            'from' => $preferredModel,
                            'to' => $model,
                        ]);
                    }

                    return $this->callGeminiHtml($prompt, $apiKey, $model, $ensureLandingStructure, $documentType);
                } catch (ValidationException $e) {
                    $last = $e;
                    $msg = $this->extractValidationMessage($e);
                    $friendlyMessage = $this->normalizeAiErrorMessage($msg);
                    $retryAfterSeconds = $this->parseRetryAfterSeconds($msg);

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

                    if ($this->shouldCacheModelCooldown($msg)) {
                        $cooldown = $retryAfterSeconds ?? $this->defaultCooldownSecondsForMessage($msg);
                        $this->setModelCooldown($model, $cooldown, $friendlyMessage);
                        $cooldowns[$model] = $cooldown;
                    }

                    if (!$this->isRetryableAiError($msg)) {
                        throw ValidationException::withMessages([
                            'ai' => [$friendlyMessage],
                        ]);
                    }

                    if ($attempt < $maxAttempts && $this->canRetryImmediately($msg, $retryAfterSeconds)) {
                        usleep($this->retryBackoffMicroseconds($attempt));
                        continue;
                    }

                    if ($idx === count($models) - 1) {
                        throw ValidationException::withMessages([
                            'ai' => [$friendlyMessage],
                        ]);
                    }

                    continue 2;
                }
            }
        }

        if ($cooldowns !== [] && count($cooldowns) === count($models)) {
            throw ValidationException::withMessages([
                'ai' => [$this->buildCooldownMessage(min($cooldowns))],
            ]);
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

    private function isRetryableAiError(string $message): bool
    {
        $text = strtolower($message);

        return str_contains($text, 'timeout')
            || str_contains($text, 'terputus')
            || str_contains($text, 'too many requests')
            || str_contains($text, 'resource exhausted')
            || str_contains($text, 'high demand')
            || str_contains($text, 'try again later')
            || str_contains($text, 'temporarily unavailable')
            || str_contains($text, 'overloaded')
            || str_contains($text, 'http 429')
            || str_contains($text, 'http 500')
            || str_contains($text, 'http 503')
            || str_contains($text, 'retry in')
            || str_contains($text, 'cooldown');
    }

    private function shouldUseCompanyProfileFallback(string $message): bool
    {
        $text = strtolower($message);

        return str_contains($text, 'output ai company profile belum selesai')
            || str_contains($text, 'output ai belum selesai')
            || str_contains($text, 'output ai kepotong')
            || str_contains($text, 'ai tidak mengembalikan html')
            || $this->shouldUseServiceFallback($text);
    }

    private function shouldUseLandingFallback(string $message): bool
    {
        $text = strtolower($message);

        return str_contains($text, 'output ai belum selesai')
            || str_contains($text, 'output ai kepotong')
            || str_contains($text, 'ai tidak mengembalikan html')
            || $this->shouldUseServiceFallback($text);
    }

    private function shouldUseServiceFallback(string $message): bool
    {
        return $this->isRetryableAiError($message)
            || str_contains($message, 'server ai sedang padat')
            || str_contains($message, 'request ke ai sedang terlalu banyak')
            || str_contains($message, 'layanan ai sedang cooldown');
    }

    private function normalizeAiErrorMessage(string $message): string
    {
        $text = strtolower($message);
        $retryAfterSeconds = $this->parseRetryAfterSeconds($message);

        if (str_contains($text, 'high demand') || str_contains($text, 'try again later')) {
            return $this->addRetryHint(
                'Server AI sedang padat sementara. Coba lagi beberapa saat lagi.',
                $retryAfterSeconds
            );
        }

        if (str_contains($text, 'too many requests') || str_contains($text, 'resource exhausted') || str_contains($text, 'http 429')) {
            return $this->addRetryHint(
                'Request ke AI sedang terlalu banyak. Tunggu sebentar lalu coba lagi.',
                $retryAfterSeconds
            );
        }

        if (str_contains($text, 'cooldown')) {
            return $this->addRetryHint(
                'Layanan AI sedang cooldown sementara.',
                $retryAfterSeconds
            );
        }

        if (str_contains($text, 'timeout') || str_contains($text, 'terputus')) {
            return 'Koneksi ke layanan AI timeout/terputus. Coba lagi sebentar.';
        }

        return $message;
    }

    private function addRetryHint(string $message, ?int $retryAfterSeconds): string
    {
        if ($retryAfterSeconds === null || $retryAfterSeconds <= 0) {
            return $message;
        }

        return rtrim($message, '.') . " Coba lagi sekitar {$retryAfterSeconds} detik.";
    }

    private function parseRetryAfterSeconds(string $message): ?int
    {
        $patterns = [
            '/retry in\s+([\d.]+)\s*s/i',
            '/retry(?: after| sekitar)?\s+([\d.]+)\s*(?:detik|seconds?|secs?|s)\b/i',
            '/"retryDelay":"([\d.]+)s"/i',
            '/([\d.]+)\s*(?:detik|seconds?|secs?)\s+lagi/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1 && isset($matches[1])) {
                $seconds = (int) ceil((float) $matches[1]);
                if ($seconds > 0) {
                    return min($seconds, 600);
                }
            }
        }

        return null;
    }

    private function shouldCacheModelCooldown(string $message): bool
    {
        $text = strtolower($message);

        return str_contains($text, 'resource exhausted')
            || str_contains($text, 'too many requests')
            || str_contains($text, 'high demand')
            || str_contains($text, 'temporarily unavailable')
            || str_contains($text, 'overloaded')
            || str_contains($text, 'http 429')
            || str_contains($text, 'http 503')
            || str_contains($text, 'retry in');
    }

    private function defaultCooldownSecondsForMessage(string $message): int
    {
        $text = strtolower($message);

        if (str_contains($text, 'resource exhausted') || str_contains($text, 'too many requests') || str_contains($text, 'http 429')) {
            return 30;
        }

        if (
            str_contains($text, 'high demand')
            || str_contains($text, 'temporarily unavailable')
            || str_contains($text, 'overloaded')
            || str_contains($text, 'http 503')
        ) {
            return 15;
        }

        return 12;
    }

    private function canRetryImmediately(string $message, ?int $retryAfterSeconds): bool
    {
        $text = strtolower($message);

        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            return false;
        }

        return !str_contains($text, 'resource exhausted')
            && !str_contains($text, 'too many requests')
            && !str_contains($text, 'quota')
            && !str_contains($text, 'http 429');
    }

    private function retryBackoffMicroseconds(int $attempt): int
    {
        $milliseconds = min(2200, 800 * $attempt);

        return $milliseconds * 1000;
    }

    private function getModelCooldownSeconds(string $model): ?int
    {
        $cooldown = Cache::get($this->makeModelCooldownCacheKey($model));
        if (!is_array($cooldown)) {
            return null;
        }

        $until = (int) ($cooldown['until'] ?? 0);
        $remaining = $until - time();

        return $remaining > 0 ? $remaining : null;
    }

    private function setModelCooldown(string $model, int $seconds, string $message): void
    {
        $ttl = max(5, min($seconds, 600));

        Cache::put($this->makeModelCooldownCacheKey($model), [
            'until' => time() + $ttl,
            'message' => $message,
        ], now()->addSeconds($ttl + 5));
    }

    private function makeModelCooldownCacheKey(string $model): string
    {
        return self::MODEL_COOLDOWN_CACHE_PREFIX . sha1($model);
    }

    private function buildCooldownMessage(int $seconds): string
    {
        return "Layanan AI sedang cooldown sementara. Coba lagi sekitar {$seconds} detik.";
    }

    private function callGeminiHtml(
        string $prompt,
        string $apiKey,
        string $model,
        bool $ensureLandingStructure = false,
        string $documentType = 'landing'
    ): string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        $full = '';
        // Keep request count efficient, but allow one continuation for longer HTML outputs.
        $maxTurnsLimit = $documentType === 'company' ? 3 : 2;
        $maxTurns = max(1, min((int) env('GEMINI_MAX_TURNS', $maxTurnsLimit), $maxTurnsLimit));
        $startedAt = microtime(true);
        $hardDeadlineSeconds = (float) env('GEMINI_HARD_DEADLINE_SECONDS', 150);
        $hardDeadlineSeconds = max(40, min($hardDeadlineSeconds, 540));

        $contents = [
            ['role' => 'user', 'parts' => [['text' => $prompt]]],
        ];

        for ($i = 0; $i < $maxTurns; $i++) {
            $elapsed = microtime(true) - $startedAt;
            $remaining = $hardDeadlineSeconds - $elapsed;
            if ($remaining <= 10) {
                break;
            }

            $baseTurnTimeout = (int) env('GEMINI_TURN_TIMEOUT_SECONDS', 40);
            $baseTurnTimeout = max(12, min($baseTurnTimeout, 90));
            $timeoutSeconds = (int) max(12, min($baseTurnTimeout, floor($remaining - 4)));
            $defaultOutputTokens = $documentType === 'company' ? 4600 : 3000;
            $maxOutputTokens = (int) env('GEMINI_MAX_OUTPUT_TOKENS', $defaultOutputTokens);
            $maxOutputTokens = max(1200, min($maxOutputTokens, 8192));

            try {
                /** @var Response $resp */
                $resp = Http::connectTimeout(15)
                    ->timeout($timeoutSeconds)
                    ->retry(0, 0)
                    ->acceptJson()
                    ->asJson()
                    ->post($url . '?key=' . $apiKey, [
                        'contents' => $contents,
                        'generationConfig' => [
                            'temperature' => 0.35,
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

            $chunk = $this->extractTextFromGeminiResponse($resp);
            $full .= $chunk;

            if (
                str_contains($full, '<!-- END -->') ||
                ($this->hasHtmlClosingTag($full) && $this->looksLikeCompleteHtml($full, $documentType))
            ) {
                break;
            }

            $contents[] = ['role' => 'model', 'parts' => [['text' => $chunk]]];
            $contents[] = ['role' => 'user', 'parts' => [[
                'text' => $this->buildTurnContinuationInstruction($documentType)
            ]]];
        }

        $full = str_replace('<!-- END -->', '', $full);
        $full = trim($full);

        if ($full === '') {
            throw ValidationException::withMessages([
                'ai' => ['AI tidak mengembalikan HTML.'],
            ]);
        }

        if (!$this->looksLikeCompleteHtml($full, $documentType)) {
            $continued = $this->continueIncompleteHtml($full, $apiKey, $model, $url, $documentType);
            if ($this->looksLikeCompleteHtml($continued, $documentType)) {
                return $continued;
            }

            $repairSource = $continued !== '' ? $continued : $full;
            $repaired = $this->repairIncompleteHtml($repairSource, $apiKey, $url, $documentType);
            if ($this->looksLikeCompleteHtml($repaired, $documentType)) {
                return $repaired;
            }

            $finalized = $this->finalizeHtmlBestEffort($repaired);
            if ($this->looksLikeCompleteHtml($finalized, $documentType)) {
                return $finalized;
            }

            $wrapped = $this->forceHtmlWrapper($finalized !== '' ? $finalized : $repaired);
            if ($this->looksLikeCompleteHtml($wrapped, $documentType)) {
                return $wrapped;
            }

            $salvaged = $this->salvageIncompleteHtmlDocument(
                $wrapped !== '' ? $wrapped : $repairSource,
                $ensureLandingStructure,
                $documentType
            );
            if ($salvaged !== '') {
                return $salvaged;
            }

            throw ValidationException::withMessages([
                'ai' => [$documentType === 'company'
                    ? 'Output AI company profile belum selesai sepenuhnya. Coba generate lagi 1x, atau ringkas input supaya HTML bisa selesai lengkap.'
                    : 'Output AI belum selesai sepenuhnya. Coba generate lagi 1x, atau ringkas input supaya HTML bisa selesai lengkap.'
                ],
            ]);
        }

        return $full;
    }

    private function repairIncompleteHtml(string $partialHtml, string $apiKey, string $url, string $documentType = 'landing'): string
    {
        $repairPrompt = $this->buildRepairPrompt($partialHtml, $documentType);

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
                        'maxOutputTokens' => $documentType === 'company' ? 4600 : 3600,
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

    private function continueIncompleteHtml(
        string $partialHtml,
        string $apiKey,
        string $model,
        string $url,
        string $documentType = 'landing'
    ): string
    {
        $full = trim($partialHtml);
        $maxTurns = $documentType === 'company' ? 3 : 2;

        for ($i = 0; $i < $maxTurns; $i++) {
            if ($this->looksLikeCompleteHtml($full, $documentType)) {
                return $full;
            }

            $prompt = $this->buildContinuationPrompt($full, $documentType);

            try {
                /** @var Response $resp */
                $resp = Http::connectTimeout(15)
                    ->timeout(24)
                    ->retry(0, 0)
                    ->acceptJson()
                    ->asJson()
                    ->post($url . '?key=' . $apiKey, [
                        'contents' => [
                        ['role' => 'user', 'parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'maxOutputTokens' => $documentType === 'company' ? 3600 : 2200,
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

    private function looksLikeCompleteHtml(string $html, string $documentType = 'landing'): bool
    {
        $normalized = trim($html);
        if ($normalized === '') {
            return false;
        }

        $hasDoctype = preg_match('/<!doctype html>/i', $normalized) === 1;
        $hasHtmlOpen = preg_match('/<html\b/i', $normalized) === 1;
        $hasHtmlClose = $this->hasHtmlClosingTag($normalized);
        $hasHeadOpen = preg_match('/<head\b/i', $normalized) === 1;
        $hasHeadClose = preg_match('/<\/head>/i', $normalized) === 1;
        $hasBodyOpen = preg_match('/<body\b/i', $normalized) === 1;
        $hasBodyClose = preg_match('/<\/body>/i', $normalized) === 1;
        $hasStyleOpen = preg_match('/<style\b/i', $normalized) === 1;
        $hasStyleClose = preg_match('/<\/style>/i', $normalized) === 1;
        $bodyContent = $this->extractBodyInnerHtml($normalized);

        if (
            !$hasDoctype ||
            !$hasHtmlOpen ||
            !$hasHtmlClose ||
            !$hasHeadOpen ||
            !$hasHeadClose ||
            !$hasBodyOpen ||
            !$hasBodyClose ||
            !$hasStyleOpen ||
            !$hasStyleClose
        ) {
            return false;
        }

        if ($this->containsBrokenCssBlock($normalized)) {
            return false;
        }

        if ($bodyContent === null || !$this->meetsDocumentCompletenessThreshold($bodyContent, $documentType)) {
            return false;
        }

        if ($this->containsPrematureClosingPattern($normalized)) {
            return false;
        }

        return true;
    }

    private function hasHtmlClosingTag(string $html): bool
    {
        return preg_match('/<\/html>/i', $html) === 1;
    }

    private function extractBodyInnerHtml(string $html): ?string
    {
        if (preg_match('/<body\b[^>]*>([\s\S]*?)<\/body>/i', $html, $matches) !== 1) {
            return null;
        }

        return $matches[1] ?? null;
    }

    private function containsBrokenCssBlock(string $html): bool
    {
        if (preg_match('/<style\b[^>]*>([\s\S]*?)<\/style>/i', $html, $matches) !== 1) {
            return true;
        }

        $css = trim($matches[1] ?? '');
        if ($css === '') {
            return true;
        }

        $openBraces = substr_count($css, '{');
        $closeBraces = substr_count($css, '}');

        return $openBraces === 0 || $openBraces !== $closeBraces;
    }

    private function containsPrematureClosingPattern(string $html): bool
    {
        if (preg_match('/<style\b[^>]*>[\s\S]*<\/html>/i', $html) === 1 && preg_match('/<\/style>/i', $html) !== 1) {
            return true;
        }

        if (preg_match('/<\/head>\s*<\/html>/i', $html) === 1) {
            return true;
        }

        if (preg_match('/<\/style>\s*<\/head>\s*<\/html>/i', $html) === 1) {
            return true;
        }

        return false;
    }

    private function salvageIncompleteHtmlDocument(string $html, bool $ensureLandingStructure, string $documentType = 'landing'): string
    {
        $body = $this->extractBestEffortBodyHtml($html);
        $body = $this->stripLeadingNonHtmlNoise($body);

        if (
            $body === '' ||
            $this->looksLikeCssFragment($body) ||
            !$this->containsRenderableHtmlFragment($body) ||
            !$this->meetsDocumentCompletenessThreshold($body, $documentType)
        ) {
            return '';
        }

        $title = 'Generated Page';
        if (preg_match('/<title>(.*?)<\/title>/is', $html, $matches) === 1) {
            $title = trim(strip_tags($matches[1])) ?: $title;
        }

        if ($ensureLandingStructure) {
            $body = $this->ensureLandingStructure($body);
        }

        return <<<HTML
<!doctype html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{$this->e($title)}</title>
  <style>
    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
      margin: 0;
      font-family: Arial, Helvetica, sans-serif;
      color: #111827;
      background: #f8fafc;
      line-height: 1.6;
    }
    img { max-width: 100%; height: auto; display: block; }
    a { color: #1456D9; }
  </style>
</head>
<body>
{$body}
</body>
</html>
HTML;
    }

    private function extractBestEffortBodyHtml(string $html): string
    {
        $content = trim($html);
        if ($content === '') {
            return '';
        }

        if (preg_match('/<body\b[^>]*>([\s\S]*)/i', $content, $matches) === 1) {
            $content = $matches[1] ?? '';
        }

        $content = preg_replace('/<\/body>[\s\S]*$/i', '', $content) ?? $content;
        $content = preg_replace('/<!doctype html>/i', '', $content) ?? $content;
        $content = preg_replace('/<html\b[^>]*>/i', '', $content) ?? $content;
        $content = preg_replace('/<\/html>/i', '', $content) ?? $content;
        $content = preg_replace('/<head\b[\s\S]*?<\/head>/i', '', $content) ?? $content;
        $content = preg_replace('/<style\b[\s\S]*?<\/style>/i', '', $content) ?? $content;
        $content = preg_replace('/<style\b[\s\S]*$/i', '', $content) ?? $content;
        $content = preg_replace('/<script\b[\s\S]*?<\/script>/i', '', $content) ?? $content;
        $content = preg_replace('/<script\b[\s\S]*$/i', '', $content) ?? $content;
        $content = trim($content);

        return $content;
    }

    private function stripLeadingNonHtmlNoise(string $html): string
    {
        $content = trim($html);
        if ($content === '') {
            return '';
        }

        if (preg_match('/<(header|section|main|article|div|footer|form|h1|h2|h3|p|ul|ol|details|blockquote)\b/i', $content, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $offset = (int) ($matches[0][1] ?? 0);
            if ($offset > 0) {
                $content = ltrim(substr($content, $offset));
            }
        }

        return trim($content);
    }

    private function containsRenderableHtmlFragment(string $html): bool
    {
        return preg_match('/<(header|section|main|article|div|footer|form|h1|h2|h3|p|ul|ol|li|details|summary|blockquote)\b/i', $html) === 1;
    }

    private function looksLikeCssFragment(string $html): bool
    {
        $content = trim($html);
        if ($content === '') {
            return false;
        }

        $htmlTagCount = preg_match_all('/<\/?[a-z][^>]*>/i', $content);
        $selectorCount = preg_match_all('/(^|\n)\s*[.#]?[a-z0-9_-]+(?:\s+[.#]?[a-z0-9_-]+)*\s*\{/im', $content);
        $braceCount = substr_count($content, '{') + substr_count($content, '}');
        $colonCount = substr_count($content, ':');

        return $htmlTagCount < 3 && (($selectorCount > 0 && $braceCount >= 2) || ($braceCount >= 4 && $colonCount >= 2));
    }

    private function isLandingPrompt(string $prompt): bool
    {
        $text = strtolower($prompt);

        return str_contains($text, 'landing page')
            || str_contains($text, 'sales')
            || str_contains($text, 'cta')
            || str_contains($text, 'problem')
            || str_contains($text, 'faq');
    }

    private function ensureLandingStructure(string $body): string
    {
        $normalized = strtolower($body);
        $requiredSections = [
            'hero' => ['hero', 'headline', 'promo'],
            'problem' => ['problem', 'masalah', 'pain'],
            'benefit' => ['benefit', 'keunggulan', 'manfaat'],
            'pricing' => ['harga', 'promo', 'penawaran'],
            'testimoni' => ['testimoni', 'ulasan'],
            'faq' => ['faq', 'pertanyaan'],
            'form' => ['form', 'order', 'kontak', 'whatsapp'],
            'footer' => ['footer'],
        ];

        foreach ($requiredSections as $key => $keywords) {
            $exists = false;
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $body .= "\n" . $this->buildLandingFallbackSection($key);
            }
        }

        return $body;
    }

    private function buildLandingFallbackSection(string $section): string
    {
        switch ($section) {
            case 'hero':
                return '<section class="card"><h1>Penawaran Utama</h1><p>Ringkasan penawaran utama belum tersedia. Silakan lengkapi dan coba generate lagi.</p></section>';
            case 'problem':
                return '<section class="card"><h2>Masalah yang Diselesaikan</h2><p>Bagian ini akan menjelaskan masalah utama audiens dan solusinya.</p></section>';
            case 'benefit':
                return '<section class="card"><h2>Manfaat Utama</h2><ul><li>Manfaat utama 1</li><li>Manfaat utama 2</li><li>Manfaat utama 3</li></ul></section>';
            case 'pricing':
                return '<section class="card"><h2>Harga & Promo</h2><p>Detail promo akan muncul di sini.</p></section>';
            case 'testimoni':
                return '<section class="card"><h2>Testimoni</h2><p>Testimoni pelanggan akan tampil di sini.</p></section>';
            case 'faq':
                return '<section class="card"><h2>FAQ</h2><p>Pertanyaan umum akan tampil di sini.</p></section>';
            case 'form':
                return '<section class="card"><h2>Form Pemesanan</h2><form><label>Nama</label><input type="text" /><label>WhatsApp</label><input type="text" /><label>Kebutuhan</label><textarea></textarea><button type="button">Kirim</button></form></section>';
            case 'footer':
                return '<footer class="card"><p>&copy; 2026. All rights reserved.</p></footer>';
            default:
                return '';
        }
    }


    private function buildPrompt(array $d): string
    {
        $company = $this->compactPromptText($d['company_name'], 80);
        $product = $this->compactPromptText($d['product'], 120);
        $aud     = $this->compactPromptText($d['audience'], 120);
        $tone    = $d['tone'];
        $offer   = $this->compactPromptText($d['main_offer'] ?? '', 140);
        $price   = $this->compactPromptText($d['price_note'] ?? '', 100);
        $bonus   = $this->compactPromptText($d['bonus'] ?? '', 120);
        $urgency = $this->compactPromptText($d['urgency'] ?? '', 110);
        $cta     = $this->compactPromptText($d['cta'], 50);
        $contact = $this->compactPromptText($d['contact'] ?? '', 120);
        $color   = $this->compactPromptText($d['brand_color'] ?? '', 20);

        return <<<PROMPT
Kamu adalah senior web developer + direct response copywriter. Buat 1 file landing page HTML yang sederhana, lengkap, stabil, boxed, dan fokus konversi.

PRIORITAS UTAMA:
1. Dokumen HTML harus lengkap dan valid.
2. Struktur harus berurutan dari atas ke bawah.
3. Desain cukup rapi dan enak dibaca, tidak perlu terlalu rumit.

ATURAN KERAS:
- Output HARUS murni HTML lengkap dari `<!doctype html>` sampai `</html>`, tanpa markdown.
- Baris awal HARUS dimulai dari `<!doctype html>`, lalu `<html lang="id">`, lalu `<head>`.
- Jangan pernah mulai output dari `<section>`, `<div>`, `<footer>`, atau potongan body.
- Semua CSS di dalam satu tag `<style>`.
- Jangan gunakan `<script>`, `<svg>`, `<img>`, `<canvas>`, atau library eksternal.
- Jangan gunakan navbar atau menu.
- Layout satu kolom utama di tengah, max-width 820px.
- Gunakan class sederhana dan konsisten: `page`, `card`, `hero`, `grid`, `btn`, `form-box`, `faq-item`.
- FAQ wajib pakai `<details><summary>`.
- Teks tombol CTA wajib persis: "{$cta}".
- Jika contact tersedia, tampilkan di footer.
- Akhiri output dengan `<!-- END -->`.

BATASAN AGAR TIDAK KEPOTONG:
- Buat HTML yang ringkas, jangan terlalu panjang.
- Maksimal 1 paragraf pendek per section utama.
- Benefit cukup 4 poin singkat.
- Testimoni cukup 3 kartu singkat.
- FAQ cukup 5 pertanyaan singkat.
- Gunakan elemen visual sederhana berbasis CSS saja, tanpa icon SVG kompleks.

URUTAN SECTION WAJIB:
1. Hero
2. Problem + solution
3. Siapa yang cocok
4. Benefit
5. Detail produk/program
6. Harga/promo/bonus/urgency
7. Testimoni
8. FAQ
9. Form lead
10. Footer

STRUKTUR DASAR YANG HARUS DIIKUTI:
`<!doctype html>`
`<html lang="id">`
`<head>`
`<meta charset="UTF-8" />`
`<meta name="viewport" content="width=device-width, initial-scale=1.0" />`
`<title>...</title>`
`<style>...</style>`
`</head>`
`<body>`
`<main class="page"> ...semua section... </main>`
`</body>`
`</html>`

GAYA COPY:
- Bahasa Indonesia.
- Tone {$tone}.
- Menjual, jelas, padat, dan natural.
- Jangan pakai lorem ipsum atau placeholder kosong.

DATA BRAND:
- Nama perusahaan: {$company}
- Produk/jasa: {$product}
- Target audiens: {$aud}
- Penawaran utama: {$offer}
- Harga/promo: {$price}
- Bonus: {$bonus}
- Urgency: {$urgency}
- Warna brand: {$color}
- Contact: {$contact}
PROMPT;
    }

    private function buildCompanyProfilePrompt(array $d): string
    {
        $company       = $this->compactPromptText($d['company_name'], 90);
        $industry      = $this->compactPromptText($d['industry'], 100);
        $tagline       = $this->compactPromptText($d['tagline'] ?? '', 120);
        $overview      = $this->compactPromptText($d['company_overview'], 420);
        $vision        = $this->compactPromptText($d['vision'] ?? '', 180);
        $mission       = $this->compactPromptText($d['mission'] ?? '', 280);
        $services      = $this->compactPromptText($d['services'], 420);
        $target        = $this->compactPromptText($d['target_market'] ?? '', 180);
        $uvp           = $this->compactPromptText($d['unique_value'] ?? '', 220);
        $achievements  = $this->compactPromptText($d['achievements'] ?? '', 260);
        $portfolio     = $this->compactPromptText($d['portfolio'] ?? '', 260);
        $team          = $this->compactPromptText($d['team_info'] ?? '', 220);
        $email         = $this->compactPromptText($d['contact_email'] ?? '', 120);
        $phone         = $this->compactPromptText($d['contact_phone'] ?? '', 80);
        $address       = $this->compactPromptText($d['address'] ?? '', 180);
        $social        = $this->compactPromptText($d['social_links'] ?? '', 180);
        $cta           = $this->compactPromptText($d['cta'], 60);
        $tone          = $d['tone'];
        $brandColor    = $this->compactPromptText($d['brand_color'] ?? '', 20);

        return <<<PROMPT
Kamu adalah senior web developer + brand copywriter. Buat 1 file HTML company profile yang sederhana, lengkap, profesional, boxed, dan stabil untuk dipreview.

PRIORITAS UTAMA:
1. Dokumen HTML harus lengkap dan valid.
2. Struktur halaman harus berurutan dari atas ke bawah.
3. Utamakan website lengkap sampai footer, bukan dekorasi rumit.

ATURAN KERAS:
- Output HARUS murni HTML lengkap dari `<!doctype html>` sampai `</html>`, tanpa markdown.
- Baris pertama HARUS `<!doctype html>`, lalu `<html lang="id">`, lalu `<head>`.
- Jangan pernah mulai output dari `<section>`, `<div>`, `<footer>`, atau potongan body.
- Semua CSS harus di dalam satu tag `<style>`.
- Jangan gunakan `<script>`, `<svg>`, `<img>`, `<canvas>`, library eksternal, atau icon kompleks.
- Tidak perlu hamburger menu. Jika ada navigasi, cukup link teks sederhana yang bisa wrap di mobile.
- Gunakan layout boxed dengan satu kontainer utama, max-width sekitar 960px.
- Gunakan token warna sederhana: `--bg`, `--surface`, `--text`, `--muted`, `--primary`, `--border`.
- Kontras teks harus aman dan mudah dibaca.
- Wajib ada form kontak dengan field: Nama, Email, Pesan, tombol "{$cta}".
- Gunakan warna brand jika valid: {$brandColor}
- Jika tone profesional atau formal, jangan pakai emoji.
- Jika token mulai menipis, sederhanakan copy dan CSS, tapi JANGAN berhenti setelah header/nav.
- Header cukup 4 link nav singkat.
- Akhiri output dengan `<!-- END -->`.

BATASAN AGAR OUTPUT STABIL:
- HTML harus ringkas, jangan terlalu panjang.
- Maksimal 1 paragraf pendek untuk tiap section utama.
- Layanan cukup 3 sampai 4 item singkat.
- Keunggulan cukup 3 item singkat.
- Portfolio cukup 3 item singkat.
- Pencapaian cukup 3 item singkat.
- Tim cukup 3 kartu singkat.
- Navigasi cukup sederhana.
- Jika perlu menghemat token, pakai CSS yang singkat dan ulang class yang sama.

URUTAN SECTION WAJIB:
1. Header sederhana + navigasi teks
2. Hero
3. Tentang Kami
4. Visi & Misi
5. Layanan
6. Keunggulan
7. Portfolio / Project
8. Tim
9. Pencapaian
10. CTA
11. Kontak + Footer

STRUKTUR DASAR YANG HARUS DIIKUTI:
`<!doctype html>`
`<html lang="id">`
`<head>`
`<meta charset="UTF-8" />`
`<meta name="viewport" content="width=device-width, initial-scale=1.0" />`
`<title>...</title>`
`<style>...</style>`
`</head>`
`<body>`
`<main class="page"> ...semua section... </main>`
`</body>`
`</html>`

GAYA COPY:
- Bahasa Indonesia.
- Tone {$tone}.
- Profesional, jelas, dan mudah dipercaya.
- Jangan pakai lorem ipsum atau placeholder kosong.

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

Isi data optional yang kosong dengan copy profesional yang natural dan singkat.
PROMPT;
    }

    private function buildTurnContinuationInstruction(string $documentType): string
    {
        if ($documentType === 'company') {
            return 'Lanjutkan HTML company profile tepat dari posisi terakhir tanpa markdown. Jika draft sekarang baru header/nav atau sudah terlanjur menutup </body></html> sebelum semua section utama selesai, kirim ulang dokumen HTML lengkap dari <!doctype html> sampai </html>. Wajib selesaikan hero, tentang, visi misi, layanan, keunggulan, portfolio, tim, pencapaian, CTA, kontak, lalu akhiri dengan <!-- END -->.';
        }

        return 'Lanjutkan tepat dari posisi terakhir. Jangan ulangi konten sebelumnya. Prioritaskan menyelesaikan sisa HTML dan akhiri dengan <!-- END -->.';
    }

    private function buildRepairPrompt(string $partialHtml, string $documentType): string
    {
        if ($documentType === 'company') {
            return <<<PROMPT
Lengkapi HTML company profile berikut karena output sebelumnya terpotong atau berhenti terlalu cepat.

ATURAN:
- Jika draft sekarang baru header/nav atau belum punya section utama yang cukup, KIRIM ULANG dokumen HTML lengkap dari `<!doctype html>` sampai `</html>`.
- Pertahankan brand, gaya, warna, dan copy yang masih bagus.
- Selesaikan urutan section: Header, Hero, Tentang Kami, Visi & Misi, Layanan, Keunggulan, Portfolio, Tim, Pencapaian, CTA, Kontak, Footer.
- Jika perlu menghemat token, sederhanakan CSS dan copy, tapi semua section wajib selesai.
- Output HARUS murni HTML, tanpa markdown.
- Akhiri dengan `<!-- END -->`.

HTML TERPOTONG:
{$partialHtml}
PROMPT;
        }

        return <<<PROMPT
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
    }

    private function buildContinuationPrompt(string $partialHtml, string $documentType): string
    {
        if ($documentType === 'company') {
            return <<<PROMPT
Lanjutkan atau perbaiki HTML company profile ini.
- Jika draft saat ini baru header/nav, terlalu pendek, atau sudah terlanjur menutup `</body></html>`, kirim ulang HTML LENGKAP dari awal mulai `<!doctype html>`.
- Jika draft sudah cukup jauh, lanjutkan tepat dari bagian yang belum selesai tanpa mengulang isi yang sama.
- Wajib selesaikan section: Hero, Tentang Kami, Visi & Misi, Layanan, Keunggulan, Portfolio, Tim, Pencapaian, CTA, Kontak, Footer.
- Jangan kirim markdown.
- Akhiri dengan `<!-- END -->`.

HTML SAAT INI:
{$partialHtml}
PROMPT;
        }

        return <<<PROMPT
Lanjutkan HTML ini tepat dari karakter terakhir.
- Jangan ulangi dari awal.
- Hanya kirim sisa yang belum ada sampai penutup lengkap.
- Jika draft saat ini belum punya `<body>` atau isi halaman utama belum muncul, lengkapi seluruh bagian body yang hilang sampai tuntas.
- Akhiri dengan `</body></html><!-- END -->`.

HTML SAAT INI:
{$partialHtml}
PROMPT;
    }

    private function meetsDocumentCompletenessThreshold(string $bodyHtml, string $documentType): bool
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($bodyHtml)) ?? '');
        if ($text === '') {
            return false;
        }

        $headingCount = preg_match_all('/<h[1-6]\b/i', $bodyHtml);
        $sectionCount = preg_match_all('/<(header|section|footer|main|article)\b/i', $bodyHtml);

        if ($documentType === 'company') {
            if (mb_strlen($text) < 260) {
                return false;
            }

            if ($headingCount < 5 || $sectionCount < 5) {
                return false;
            }

            $keywordGroups = [
                ['tentang', 'about'],
                ['visi', 'vision'],
                ['misi', 'mission'],
                ['layanan', 'services', 'service'],
                ['keunggulan', 'unggulan', 'value', 'why choose'],
                ['portfolio', 'portofolio', 'project', 'proyek'],
                ['tim', 'team'],
                ['pencapaian', 'achievement', 'klien', 'client'],
                ['kontak', 'contact', 'hubungi', 'email', 'telepon'],
            ];

            $matchedGroups = 0;
            $normalizedText = strtolower($text);

            foreach ($keywordGroups as $group) {
                foreach ($group as $keyword) {
                    if (str_contains($normalizedText, $keyword)) {
                        $matchedGroups++;
                        break;
                    }
                }
            }

            return $matchedGroups >= 5;
        }

        return mb_strlen($text) >= 120;
    }
}
