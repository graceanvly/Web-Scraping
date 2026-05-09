'use strict';

/**
 * Shared Chrome launch settings for headless EC2/Linux (Crashpad / crashpad_handler errors).
 * @see https://pptr.dev/troubleshooting
 */

const fs = require('fs');
const path = require('path');
const os = require('os');

function truthyEnv(name) {
    return /^(1|true|yes|on)$/i.test(String(process.env[name] || '').trim());
}

function createChromeProfileDir() {
    const override = process.env.SCRAPER_CHROME_USER_DATA_DIR;
    const base = override && override.trim() ? override.trim() : os.tmpdir();
    const dir = path.join(base, `puppeteer-profile-${process.pid}-${Date.now()}`);
    fs.mkdirSync(dir, { recursive: true });
    return dir;
}

/**
 * @returns {{ launchOptions: import('puppeteer').PuppeteerLaunchOptions, profileDir: string }}
 */
function buildLaunchConfig() {
    const profileDir = createChromeProfileDir();

    const args = [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
        '--disable-software-rasterizer',
        '--disable-breakpad',
        '--disable-crash-reporter',
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-extensions',
        '--disable-blink-features=AutomationControlled',
        '--window-size=1280,900',
    ];

    if (truthyEnv('SCRAPER_CHROME_SINGLE_PROCESS')) {
        args.push('--single-process', '--no-zygote');
    }

    const env = {
        ...process.env,
        CHROME_CRASHPAD_DISABLED: '1',
    };

    const launchOptions = {
        headless: 'new',
        args,
        env,
        userDataDir: profileDir,
        // Avoid WebSocket to browser; can reduce crashpad/socket issues on some hosts
        pipe: true,
    };

    return { launchOptions, profileDir };
}

function removeChromeProfile(profileDir) {
    if (!profileDir || typeof profileDir !== 'string') {
        return;
    }
    try {
        fs.rmSync(profileDir, { recursive: true, force: true });
    } catch {
        // ignore
    }
}

module.exports = { buildLaunchConfig, removeChromeProfile, truthyEnv };
