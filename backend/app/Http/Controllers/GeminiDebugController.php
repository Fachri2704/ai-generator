<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

class GeminiDebugController extends Controller
{
     public function models()
    {
        $apiKey = (string) config('services.gemini.key');

        /** @var Response $resp */
        $resp = Http::timeout(30)->get(
            'https://generativelanguage.googleapis.com/v1beta/models',
            ['key' => $apiKey]
        );

        return response()->json(
            [
                'status' => $resp->status(),
                'body'   => $resp->json(),
            ],
            $resp->status()
        );
    }
}
