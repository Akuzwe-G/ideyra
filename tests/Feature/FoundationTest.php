<?php

namespace Tests\Feature;

use Tests\TestCase;

class FoundationTest extends TestCase
{
    public function test_landing_page_contains_brand_and_honest_feature_states(): void
    {
        $this->get('/')->assertOk()
            ->assertSee(config('ideyra.name'))
            ->assertSee('Imagine.')->assertSee('Build.')->assertSee('Achieve.')
            ->assertSee('v'.config('ideyra.version'))
            ->assertSee('Get Started')
            ->assertSee('Institution-led access')
            ->assertSee('ideyra-wordmark.png')
            ->assertSee(route('apply'))
            ->assertSee(route('login'));
    }

    public function test_manifest_is_valid_and_does_not_reference_missing_icons(): void
    {
        $manifest = $this->get('/manifest.webmanifest')->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('name', 'Ideyra')->assertJsonPath('start_url', '/')
            ->assertJsonPath('scope', '/')->assertJsonPath('display', 'standalone')
            ->assertJsonPath('theme_color', config('ideyra.brand.navy'))->json();
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
    }

    public function test_worker_exposes_static_assets_and_network_only_navigation(): void
    {
        $response = $this->get('/sw.js')->assertOk()
            ->assertHeader('Content-Type', 'application/javascript')
            ->assertHeader('Service-Worker-Allowed', '/')
            ->assertSee("request.method !== 'GET'", false)
            ->assertSee("request.mode === 'navigate'", false)
            ->assertSee('fetch(request).catch', false)
            ->assertSee('ASSETS.includes(url.pathname)', false)
            ->assertDontSee('cache.put', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        preg_match('/const ASSETS = (.*);/', $response->getContent(), $matches);
        $assets = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($assets, 'Run the production build before this test.');
        foreach ($assets as $asset) {
            $this->assertTrue(str_starts_with($asset, '/build/') || str_starts_with($asset, '/assets/brand/'));
            $this->assertFileExists(public_path(ltrim($asset, '/')));
        }
    }

    public function test_offline_fallback_has_no_user_or_csrf_data(): void
    {
        $this->get('/offline.html')->assertOk()->assertSee('You’re offline.')
            ->assertSee('never stored')->assertDontSee('csrf-token')
            ->assertHeaderMissing('Set-Cookie')
            ->assertDontSee('name="_token"', false);
    }

    public function test_all_layout_previews_render_in_development(): void
    {
        foreach (['auth', 'personal', 'institution', 'components'] as $layout) {
            $this->get('/preview/'.$layout)->assertOk()->assertSee('preview');
        }
        $this->get('/preview/unknown')->assertNotFound();
    }

    public function test_layout_previews_are_not_accessible_in_production(): void
    {
        $this->app->instance('env', 'production');
        foreach (['auth', 'personal', 'institution', 'components'] as $layout) {
            $this->get('/preview/'.$layout)->assertNotFound();
        }
    }

    public function test_product_metadata_loads_independently_of_framework_version(): void
    {
        $this->assertSame('1.0.0', config('ideyra.version'));
        $this->assertSame('Imagine. Build. Achieve.', config('ideyra.tagline'));
        $this->assertNotSame(app()->version(), config('ideyra.version'));
        $this->assertSame(2026, config('ideyra.copyright_year'));
    }
}
