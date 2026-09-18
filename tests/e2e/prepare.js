import { execFileSync } from 'node:child_process';
import { root, testEnvironment } from './environment.js';

execFileSync('php', ['tests/e2e/setup.php'], {
    cwd: root,
    env: { ...process.env, ...testEnvironment },
    stdio: 'inherit',
});
