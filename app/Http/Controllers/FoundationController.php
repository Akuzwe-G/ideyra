<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

class FoundationController extends Controller
{
    public function manifest(): JsonResponse
    {
        $icons = [];
        foreach ([192, 512] as $size) {
            if (is_file(public_path("brand/icon-{$size}.png"))) {
                $icons[] = ['src' => "/brand/icon-{$size}.png", 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => 'any'];
            }
        }

        return response()->json([
            'id' => '/', 'name' => config('ideyra.name'), 'short_name' => config('ideyra.name'),
            'description' => config('ideyra.tagline'), 'start_url' => '/', 'scope' => '/',
            'display' => 'standalone', 'theme_color' => config('ideyra.brand.navy'),
            'background_color' => config('ideyra.brand.sky'), 'icons' => $icons,
        ])->header('Content-Type', 'application/manifest+json')->header('Cache-Control', 'no-cache');
    }

    public function worker(): Response
    {
        $manifest = public_path('build/manifest.json');
        $assets = [];
        if (is_file($manifest)) {
            foreach (json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR) as $entry) {
                foreach (array_merge([$entry['file']], $entry['css'] ?? [], $entry['assets'] ?? []) as $file) {
                    $assets[] = '/build/'.$file;
                }
            }
        }
        $assets = array_values(array_unique($assets));
        $revision = hash('sha256', json_encode($assets).file_get_contents(resource_path('views/pwa/offline.blade.php')).file_get_contents(resource_path('views/pwa/worker.blade.php')).json_encode(config('ideyra')));

        return response()->view('pwa.worker', compact('assets', 'revision'))
            ->header('Content-Type', 'application/javascript')->header('Cache-Control', 'no-cache, no-store')
            ->header('Service-Worker-Allowed', '/');
    }

    public function offline(): Response
    {
        return response()->view('pwa.offline')->header('Cache-Control', 'public, max-age=0, must-revalidate');
    }

    public function preview(string $layout): View
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        return view('preview', compact('layout'));
    }
}
