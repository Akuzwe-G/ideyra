<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    public function test_all_public_pages_have_unique_titles_metadata_and_working_navigation(): void
    {
        config(['app.url' => 'https://ideyra.example']);
        $titles = ['/' => 'Ideyra | Imagine. Build. Achieve.', '/about' => 'About Us | Ideyra', '/features' => 'Features | Ideyra', '/for-students' => 'For Students | Ideyra', '/for-institutions' => 'For Institutions | Ideyra', '/contact' => 'Contact | Ideyra'];
        foreach ($titles as $path => $title) {
            $response = $this->get($path)->assertOk()->assertSee('<title>'.$title.'</title>', false)
                ->assertSee('name="description"', false)->assertSee('property="og:image"', false)
                ->assertSee('https://ideyra.example'.$path)->assertSee('aria-current="page"', false)
                ->assertSee('v'.config('ideyra.version'))->assertDontSee('Official brand asset missing');
            foreach (['home', 'about', 'features', 'for-students', 'for-institutions', 'contact', 'login', 'apply'] as $name) {
                $response->assertSee(route($name));
            }
        }
    }

    public function test_official_assets_and_generated_icons_are_present_and_correctly_sized(): void
    {
        foreach (['ideyra-icon.png', 'ideyra-wordmark.png', 'favicon.ico'] as $name) {
            $this->assertFileExists(public_path('assets/brand/'.$name));
        }
        foreach (['favicon-16x16.png' => 16, 'favicon-32x32.png' => 32, 'apple-touch-icon.png' => 180, 'pwa-192x192.png' => 192, 'pwa-512x512.png' => 512, 'pwa-maskable-192x192.png' => 192, 'pwa-maskable-512x512.png' => 512] as $name => $size) {
            $dimensions = getimagesize(public_path('assets/brand/'.$name));
            $this->assertSame([$size, $size], [$dimensions[0], $dimensions[1]]);
        }
        $this->get('/')->assertSee('/assets/brand/favicon.ico')->assertSee('/assets/brand/apple-touch-icon.png');
        $manifest = $this->get('/manifest.webmanifest')->assertOk()->json();
        $this->assertCount(4, $manifest['icons']);
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));
    }

    public function test_sitemap_has_only_public_pages_and_configured_origin(): void
    {
        config(['app.url' => 'https://ideyra.example']);
        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml')
            ->assertSee('https://ideyra.example/for-students')
            ->assertDontSee('/dashboard')->assertDontSee('/login')->assertDontSee('/register');
    }

    public function test_robots_disallows_non_production_and_private_routes_in_production(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee("Disallow: /\n", false);
        $this->app->instance('env', 'production');
        $this->get('/robots.txt')->assertOk()->assertSee('Allow: /')->assertSee('Disallow: /dashboard');
    }

    public function test_authentication_pages_render_and_are_not_indexable(): void
    {
        $this->get('/login')->assertOk()->assertSee('<title>Log In | Ideyra</title>', false)->assertSee('noindex, nofollow');
        $this->get('/register')->assertNotFound();
        $this->get('/forgot-password')->assertOk()->assertSee('noindex, nofollow');
    }

    public function test_maskable_artwork_stays_within_safe_zone(): void
    {
        $image = imagecreatefrompng(public_path('assets/brand/pwa-maskable-192x192.png'));
        $safe = true;
        for ($y = 0; $y < 192; $y++) {
            for ($x = 0; $x < 192; $x++) {
                if (hypot($x - 95.5, $y - 95.5) > 192 * .4) {
                    $safe = $safe && (imagecolorat($image, $x, $y) & 0xFFFFFF) === 0xFFFFFF;
                }
            }
        }
        $this->assertTrue($safe, 'Maskable artwork must stay inside the central 80% diameter safe circle.');
    }
}
