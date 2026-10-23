import { defineConfig } from '@playwright/test';
import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { execFileSync } from 'node:child_process';

const initializeDatabase = !process.env.IDEYRA_BROWSER_DB;
const database = process.env.IDEYRA_BROWSER_DB || resolve('storage/app/browser-' + Date.now() + '.sqlite');
process.env.IDEYRA_BROWSER_DB = database;
if (initializeDatabase) writeFileSync(database, '');
const isolatedEnvironment = {
    ...process.env,
    APP_ENV: 'local',
    APP_URL: 'http://127.0.0.1:8011',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: database,
    DB_URL: '',
    SESSION_DRIVER: 'database',
    CACHE_STORE: 'database',
    MAIL_MAILER: 'array',
};
if (initializeDatabase) execFileSync('php', ['artisan', 'migrate', '--force', '--no-interaction'], { env: isolatedEnvironment });

export default defineConfig({
    testDir: './tests/Browser',
    workers: 1,
    reporter: 'list',
    outputDir: 'storage/app/browser-results',
    timeout: 60000,
    use: { baseURL: 'http://127.0.0.1:8011', channel: 'chrome', headless: true },
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8011 --no-interaction',
        url: 'http://127.0.0.1:8011',
        reuseExistingServer: false,
        env: isolatedEnvironment,
    },
});
