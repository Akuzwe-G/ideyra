import { defineConfig } from '@playwright/test';
import { existsSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';

const initializeDatabase = !process.env.IDEYRA_BROWSER_DB;
if (existsSync(resolve('storage/app/browser-config-unused.php'))) throw new Error('Browser tests refuse cached configuration.');
const database = process.env.IDEYRA_BROWSER_DB || resolve('storage/app/browser-' + Date.now() + '.sqlite');
process.env.IDEYRA_BROWSER_DB = database;
process.env.IDEYRA_BROWSER_PASSWORD ||= randomBytes(24).toString('hex') + 'Aa1!';
if (initializeDatabase) writeFileSync(database, '');
const isolatedEnvironment = {
    ...process.env,
    APP_ENV: 'local',
    APP_CONFIG_CACHE: resolve('storage/app/browser-config-unused.php'),
    APP_URL: 'http://127.0.0.1:8011',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: database,
    DB_URL: '',
    SESSION_DRIVER: 'database',
    CACHE_STORE: 'database',
    MAIL_MAILER: 'array',
};
if (initializeDatabase) execFileSync('php', ['artisan', 'migrate', '--force', '--no-interaction'], { env: isolatedEnvironment });
if (initializeDatabase) process.env.IDEYRA_BROWSER_FIXTURES = execFileSync('php', ['tests/Browser/seed.php'], { env: isolatedEnvironment }).toString();

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
