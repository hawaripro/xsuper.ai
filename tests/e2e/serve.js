import { spawn } from 'node:child_process';
import path from 'node:path';
import { root, testEnvironment } from './environment.js';

const server = spawn('php', ['-S', '127.0.0.1:8017', path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], {
    cwd: path.join(root, 'public'),
    env: { ...process.env, ...testEnvironment },
    stdio: 'inherit',
});
for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => server.kill(signal));
}
server.on('error', error => { console.error(error); process.exitCode = 1; });
server.on('exit', code => { process.exitCode = code ?? 1; });
