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
        $data = $request->validate([
            'company_name' => 'required|string|max:100',
            'product'      => 'required|string|max:150',
            'audience'     => 'required|string|max:150',
            'tone'         => 'required|string|in:profesional,santai,formal',
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

        $contents = [
            ['role' => 'user', 'parts' => [['text' => $prompt]]],
        ];

        for ($i = 0; $i < $maxTurns; $i++) {
            /** @var Response $resp */
            $resp = Http::timeout(90)
                ->retry(3, 800)
                ->acceptJson()
                ->asJson()
                ->post($url . '?key=' . $apiKey, [
                    'contents' => $contents,
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 6000,
                    ],
                ]);

            if ($resp->failed()) {
                $msg = data_get($resp->json(), 'error.message') ?? ('HTTP ' . $resp->status());
                throw ValidationException::withMessages(['ai' => ["Gagal generate: {$msg}"]]);
            }

            $chunk = (string) data_get($resp->json(), 'candidates.0.content.parts.0.text', '');
            $full .= $chunk;

            if (str_contains($full, '<!-- END -->')) {
                break;
            }

            $contents[] = ['role' => 'model', 'parts' => [['text' => $chunk]]];
            $contents[] = ['role' => 'user', 'parts' => [[
                'text' => 'Lanjutkan output HTML tepat setelah bagian terakhir. Jangan ulangi dari awal. Output HARUS hanya kelanjutan HTML sampai selesai, dan akhiri dengan <!-- END -->.'
            ]]];
        }

        $full = str_replace('<!-- END -->', '', $full);
        $full = trim($full);

        if ($full === '') {
            throw ValidationException::withMessages([
                'ai' => ['AI tidak mengembalikan HTML.'],
            ]);
        }

        return $full;
    }


    private function buildPrompt(array $d): string
    {
        $company = $d['company_name'];
        $product = $d['product'];
        $aud     = $d['audience'];
        $tone    = $d['tone'];
        $cta     = $d['cta'];
        $contact = $d['contact'] ?? '';
        $color   = $d['brand_color'] ?? '';

        return <<<PROMPT
Kamu adalah senior web developer. Buat 1 file landing page HTML yang rapi dan modern.

ATURAN OUTPUT:
- Output HARUS HANYA HTML (mulai dari <!doctype html>), tanpa markdown, tanpa penjelasan.
- Semua CSS ditulis di <style> (tidak boleh link CDN).
- Gunakan layout modern: hero + benefit + social proof/testimoni + FAQ + footer.
- Tampilkan CTA button dengan teks: "{$cta}".
- Bahasa Indonesia, tone: {$tone}.
- Jika ada contact, tampilkan di bagian footer.
- Wajib Responsive disemua perangkat (dekstop ataupun mobile)
- Tampilan dimobile wajib ada hamburger menu (dengan animasi terbuka dan tertutup yang smooth)
- WAJIB akhiri output dengan string persis: <!-- END -->
- Jangan berhenti sebelum menulis <!-- END -->.


DATA:
- Nama perusahaan: {$company}
- Produk/jasa: {$product}
- Target audiens: {$aud}
- Warna brand (opsional): {$color}
- Contact (opsional): {$contact}

Buat konten yang masuk akal dan tidak ada placeholder seperti "lorem ipsum".
PROMPT;
    }
}
