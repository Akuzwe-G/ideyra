import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    workers: 1,
    reporter: 'list',
    outputDir: 'storage/app/browser-results',
    use: {
        baseURL: 'http://127.0.0.1:8010',
        channel: 'chrome',
        headless: true,
    },
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8010 --no-interaction',
        url: 'http://127.0.0.1:8010',
        reuseExistingServer: true,
    },
});
