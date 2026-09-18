import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
export const testEnvironment = {
    APP_ENV: 'testing',
    APP_DEBUG: 'false',
    APP_URL: 'http://127.0.0.1:8017',
    APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    APP_MAINTENANCE_DRIVER: 'cache',
    APP_MAINTENANCE_STORE: 'array',
    APP_CONFIG_CACHE: path.join(root, 'storage/framework/testing/config.php'),
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: path.join(root, 'storage/framework/testing/dashboard-e2e.sqlite'),
    DB_URL: '',
    CACHE_STORE: 'array',
    SESSION_DRIVER: 'database',
    SESSION_COOKIE: 'dashboard_e2e_session',
    SESSION_DOMAIN: 'null',
    SESSION_SECURE_COOKIE: 'false',
    MAIL_MAILER: 'array',
    QUEUE_CONNECTION: 'sync',
    BCRYPT_ROUNDS: '4',
    AI_PROXY_URL: 'http://127.0.0.1:1',
    AI_PROXY_KEY: '',
    UMAMI_WEBSITE_ID: '',
};
