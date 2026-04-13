<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LandingGenerateControllerTest extends TestCase
{
    public function test_landing_generation_uses_fallback_when_gemini_hits_rate_limit(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Config::set('services.gemini.model', 'gemini-2.0-flash');

        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Resource exhausted, please retry in 31s.',
                ],
            ], 429),
        ]);

        $response = $this->postJson('/api/generate', $this->landingPayload());

        $response->assertOk()
            ->assertJsonPath('cached', false)
            ->assertJsonPath('source', 'fallback')
            ->assertJsonPath('origin', 'fallback');

        $this->assertStringContainsString('<!doctype html>', (string) $response->json('html'));
        $this->assertStringContainsString('Belajar AI untuk UMKM', (string) $response->json('html'));
        $this->assertStringContainsString('fallback landing page', strtolower((string) $response->json('notice')));
    }

    public function test_company_profile_generation_uses_fallback_when_server_is_busy(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Config::set('services.gemini.model', 'gemini-2.0-flash');

        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Model overloaded because of high demand. Please try again later.',
                ],
            ], 503),
        ]);

        $response = $this->postJson('/api/generate-company-profile', $this->companyPayload());

        $response->assertOk()
            ->assertJsonPath('cached', false)
            ->assertJsonPath('source', 'fallback')
            ->assertJsonPath('origin', 'fallback');

        $this->assertStringContainsString('<!doctype html>', (string) $response->json('html'));
        $this->assertStringContainsString('PT Maju Bersama Teknologi', (string) $response->json('html'));
        $this->assertStringContainsString('fallback company profile', strtolower((string) $response->json('notice')));
    }

    public function test_successful_generation_is_served_from_cache_on_second_request(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Config::set('services.gemini.model', 'gemini-2.0-flash');

        $html = $this->completeHtmlDocument('Belajar AI untuk UMKM');

        Http::fake([
            '*' => Http::response($this->geminiResponse($html), 200),
        ]);

        $first = $this->postJson('/api/generate', $this->landingPayload());
        $second = $this->postJson('/api/generate', $this->landingPayload());

        $first->assertOk()
            ->assertJsonPath('cached', false)
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('origin', 'ai');

        $second->assertOk()
            ->assertJsonPath('cached', true)
            ->assertJsonPath('source', 'cache')
            ->assertJsonPath('origin', 'ai');

        $expectedHtml = str_replace('<!-- END -->', '', $html);

        $this->assertSame($expectedHtml, $first->json('html'));
        $this->assertSame($expectedHtml, $second->json('html'));
        Http::assertSentCount(1);
    }

    public function test_company_profile_salvage_does_not_inject_landing_page_sections(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Config::set('services.gemini.model', 'gemini-2.0-flash');

        $partial = '<section class="card"><h2>Tentang Kami</h2><p>Kami membantu UMKM dan startup bertumbuh lewat solusi digital yang relevan dan terukur untuk operasional, branding, dan automasi bisnis.</p></section>';

        Http::fake([
            '*' => Http::response($this->geminiResponse($partial), 200),
        ]);

        $response = $this->postJson('/api/generate-company-profile', $this->companyPayload());

        $response->assertOk();
        $html = (string) $response->json('html');

        $this->assertStringContainsString('<!doctype html>', $html);
        $this->assertStringNotContainsString('Masalah yang Diselesaikan', $html);
        $this->assertStringNotContainsString('Harga & Promo', $html);
        $this->assertStringNotContainsString('Form Pemesanan', $html);
    }

    public function test_company_profile_css_fragment_returns_full_fallback_html(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Config::set('services.gemini.model', 'gemini-2.0-flash');

        $partial = ".vision-mission-card ul {\n  list-style: none;\n  padding-left: 0;\n}\n\n.vision-mission-card ul li {\n  margin-bottom: 10px;\n}\n</body>\n</html>";

        Http::fake([
            '*' => Http::response($this->geminiResponse($partial), 200),
        ]);

        $response = $this->postJson('/api/generate-company-profile', $this->companyPayload());

        $response->assertOk()
            ->assertJsonPath('source', 'fallback')
            ->assertJsonPath('origin', 'fallback');

        $html = (string) $response->json('html');

        $this->assertStringContainsString('<!doctype html>', $html);
        $this->assertStringContainsString('<title>PT Maju Bersama Teknologi - Company Profile</title>', $html);
        $this->assertStringContainsString('PT Maju Bersama Teknologi', $html);
        $this->assertStringNotContainsString('.vision-mission-card ul {', $html);
    }

    public function test_company_profile_header_only_html_is_not_accepted_as_complete_result(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Config::set('services.gemini.model', 'gemini-2.0-flash');

        $headerOnlyHtml = <<<HTML
<!doctype html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>PT Maju Bersama Teknologi - Company Profile</title>
  <style>
    body { margin: 0; font-family: Arial, sans-serif; }
    header { padding: 24px; }
  </style>
</head>
<body>
  <header>
    <div class="logo">PT Maju Bersama Teknologi</div>
    <nav>
      <ul>
        <li><a href="#beranda">Beranda</a></li>
        <li><a href="#layanan">Layanan</a></li>
      </ul>
    </nav>
  </header>
</body>
</html>
HTML;

        $completedHtml = $this->completeCompanyProfileHtml('PT Maju Bersama Teknologi');

        Http::fake([
            '*' => Http::sequence()
                ->push($this->geminiResponse($headerOnlyHtml), 200)
                ->push($this->geminiResponse($completedHtml), 200)
                ->push($this->geminiResponse($completedHtml), 200)
                ->push($this->geminiResponse($completedHtml), 200),
        ]);

        $response = $this->postJson('/api/generate-company-profile', $this->companyPayload());

        $response->assertOk()
            ->assertJsonPath('cached', false)
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('origin', 'ai');

        $html = (string) $response->json('html');

        $this->assertStringContainsString('<!doctype html>', $html);
        $this->assertStringContainsString('Tentang Kami', $html);
        $this->assertStringContainsString('Layanan', $html);
        $this->assertStringContainsString('Hubungi Tim Kami', $html);
    }

    public function test_company_profile_uses_html_repair_flow_before_fallback(): void
    {
        Config::set('services.gemini.key', 'test-key');
        Config::set('services.gemini.model', 'gemini-2.0-flash');

        $headerOnlyHtml = <<<HTML
<!doctype html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>PT Maju Bersama Teknologi - Company Profile</title>
  <style>
    body { margin: 0; font-family: Arial, sans-serif; }
    header { padding: 24px; }
  </style>
</head>
<body>
  <header>
    <div class="logo">PT Maju Bersama Teknologi</div>
    <nav>
      <ul>
        <li><a href="#beranda">Beranda</a></li>
        <li><a href="#layanan">Layanan</a></li>
      </ul>
    </nav>
  </header>
</body>
</html>
HTML;

        $completedHtml = $this->completeCompanyProfileHtml('PT Maju Bersama Teknologi');

        $requestCount = 0;

        Http::fake(function () use (&$requestCount, $headerOnlyHtml, $completedHtml) {
            $requestCount++;

            return Http::response(
                $this->geminiResponse($requestCount === 1 ? $headerOnlyHtml : $completedHtml),
                200
            );
        });

        $response = $this->postJson('/api/generate-company-profile', $this->companyPayload());

        $response->assertOk()
            ->assertJsonPath('cached', false)
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('origin', 'ai');

        $this->assertStringContainsString('Tentang Kami', (string) $response->json('html'));
        $this->assertGreaterThanOrEqual(2, $requestCount);
    }

    private function landingPayload(): array
    {
        return [
            'company_name' => 'Belajar AI untuk UMKM',
            'product' => 'Workshop Prompting Bisnis',
            'audience' => 'Pemilik UMKM pemula',
            'tone' => 'profesional',
            'main_offer' => 'Naikkan produktivitas tim tanpa ribet',
            'price_note' => 'Promo Rp199.000 minggu ini',
            'bonus' => 'Template prompt penjualan',
            'urgency' => 'Kuota batch tinggal 10 peserta',
            'cta' => 'Daftar Sekarang',
            'contact' => 'WhatsApp 08123456789',
            'brand_color' => '#1456D9',
        ];
    }

    private function companyPayload(): array
    {
        return [
            'company_name' => 'PT Maju Bersama Teknologi',
            'industry' => 'Konsultan IT',
            'tagline' => 'Solusi digital yang bertumbuh bersama bisnis',
            'company_overview' => 'Kami membantu bisnis meningkatkan efisiensi dan kualitas layanan melalui solusi digital yang terukur dan mudah diimplementasikan.',
            'vision' => 'Menjadi mitra transformasi digital terpercaya di Indonesia.',
            'mission' => 'Memberikan solusi strategis, implementasi cepat, dan pendampingan berkelanjutan.',
            'services' => 'Konsultasi IT, pengembangan website, automasi proses bisnis',
            'target_market' => 'UMKM dan perusahaan menengah',
            'unique_value' => 'Tim responsif, strategi praktis, dan eksekusi rapi.',
            'achievements' => '100+ klien aktif, 20 proyek enterprise',
            'portfolio' => 'Website corporate, dashboard operasional, sistem internal',
            'team_info' => 'Tim multidisiplin berisi developer, designer, dan project manager.',
            'contact_email' => 'hello@majubersama.test',
            'contact_phone' => '08123456789',
            'address' => 'Bandung, Indonesia',
            'social_links' => 'LinkedIn dan Instagram',
            'cta' => 'Hubungi Tim Kami',
            'tone' => 'profesional',
            'brand_color' => '#1456D9',
        ];
    }

    private function geminiResponse(string $text): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => $text],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function completeHtmlDocument(string $title): string
    {
        return <<<HTML
<!doctype html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{$title}</title>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: Arial, sans-serif; background: #f8fafc; color: #0f172a; }
    .wrap { max-width: 860px; margin: 0 auto; padding: 32px 20px; }
  </style>
</head>
<body>
  <main class="wrap">
    <h1>{$title}</h1>
    <p>Dokumen ini sengaja dibuat cukup panjang supaya lolos validasi HTML lengkap dan meniru hasil generate yang siap dipreview pada aplikasi generator website.</p>
    <p>Konten tambahan ini membantu memastikan body punya teks yang cukup, struktur head dan body tertutup rapi, serta hasilnya bisa langsung dikembalikan sebagai respons AI yang valid.</p>
  </main>
</body>
</html><!-- END -->
HTML;
    }

    private function completeCompanyProfileHtml(string $company): string
    {
        return <<<HTML
<!doctype html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{$company} - Company Profile</title>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: Arial, sans-serif; background: #f8fafc; color: #0f172a; }
    .page { max-width: 960px; margin: 0 auto; padding: 32px 20px 64px; }
    .card { background: #fff; border: 1px solid #dbe4f0; border-radius: 20px; padding: 24px; margin-bottom: 18px; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
  </style>
</head>
<body>
  <main class="page">
    <header class="card">
      <h1>{$company}</h1>
      <p>Solusi digital yang bertumbuh bersama bisnis modern.</p>
    </header>
    <section class="card" id="beranda">
      <h2>Hero</h2>
      <p>Kami membantu bisnis membangun sistem, website, dan automasi yang rapi, relevan, dan mudah dipakai tim.</p>
    </section>
    <section class="card" id="tentang">
      <h2>Tentang Kami</h2>
      <p>{$company} adalah partner teknologi untuk UMKM dan perusahaan menengah yang ingin bertumbuh dengan implementasi digital yang lebih terukur.</p>
    </section>
    <section class="card">
      <h2>Visi & Misi</h2>
      <p>Visi kami adalah menjadi partner transformasi digital yang terpercaya. Misi kami adalah memberi strategi yang jelas, eksekusi cepat, dan pendampingan berkelanjutan.</p>
    </section>
    <section class="card" id="layanan">
      <h2>Layanan</h2>
      <div class="grid">
        <div><h3>Website Corporate</h3><p>Website profesional yang cepat, ringan, dan siap dipakai presentasi bisnis.</p></div>
        <div><h3>Automasi Proses</h3><p>Menyederhanakan alur kerja supaya tim lebih efisien dan minim proses manual.</p></div>
        <div><h3>Dashboard Internal</h3><p>Memudahkan monitoring operasional dengan data yang rapi dan mudah dibaca.</p></div>
      </div>
    </section>
    <section class="card">
      <h2>Keunggulan</h2>
      <p>Kami menggabungkan strategi, desain, dan development dalam satu tim yang responsif dan fokus pada hasil bisnis.</p>
    </section>
    <section class="card">
      <h2>Portfolio</h2>
      <p>Project kami mencakup website corporate, dashboard operasional, dan sistem internal untuk tim penjualan dan layanan.</p>
    </section>
    <section class="card">
      <h2>Tim</h2>
      <p>Tim kami terdiri dari developer, designer, dan project manager yang bekerja kolaboratif agar implementasi tetap rapi dan tepat waktu.</p>
    </section>
    <section class="card">
      <h2>Pencapaian</h2>
      <p>Kami telah menangani puluhan implementasi digital untuk bisnis jasa, distribusi, edukasi, dan operasional internal perusahaan.</p>
    </section>
    <section class="card">
      <h2>Hubungi Tim Kami</h2>
      <p>Diskusikan kebutuhan digital perusahaan Anda bersama tim kami untuk solusi yang relevan dan bertahap.</p>
    </section>
    <footer class="card" id="kontak">
      <h2>Kontak</h2>
      <p>Email: hello@majubersama.test | Telepon: 08123456789 | Bandung, Indonesia</p>
    </footer>
  </main>
</body>
</html><!-- END -->
HTML;
    }
}
