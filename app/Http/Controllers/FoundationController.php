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
            foreach (['any' => 'pwa', 'maskable' => 'pwa-maskable'] as $purpose => $prefix) {
                $path = "/assets/brand/{$prefix}-{$size}x{$size}.png";
                if (is_file(public_path($path))) {
                    $icons[] = ['src' => $path, 'sizes' => "{$size}x{$size}", 'type' => 'image/png', 'purpose' => $purpose];
                }
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
        $brandHashes = [];
        foreach (array_merge(glob(public_path('assets/brand/*.png')), glob(public_path('assets/brand/*.ico'))) as $file) {
            $path = '/assets/brand/'.basename($file);
            $assets[] = $path;
            $brandHashes[$path] = hash_file('sha256', $file);
        }
        $revision = hash('sha256', json_encode([$assets, $brandHashes]).file_get_contents(resource_path('views/pwa/offline.blade.php')).file_get_contents(resource_path('views/pwa/worker.blade.php')).json_encode(config('ideyra')));

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

    public function sitemap(): Response
    {
        return response()->view('pwa.sitemap')
            ->header('Content-Type', 'application/xml');
    }

    public function robots(): Response
    {
        $rules = app()->environment('production')
            ? "User-agent: *\nAllow: /\nDisallow: /dashboard\nDisallow: /login\nDisallow: /register\nDisallow: /preview/\nSitemap: ".rtrim(config('app.url'), '/')."/sitemap.xml\n"
            : "User-agent: *\nDisallow: /\n";

        return response($rules)->header('Content-Type', 'text/plain');
    }
}
