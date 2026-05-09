const puppeteer = require('puppeteer');
const { buildLaunchConfig, removeChromeProfile } = require('./puppeteer-launch.cjs');

const url = process.argv[2];
const delay = parseInt(process.argv[3] || '3000', 10);
const navTimeout = parseInt(process.argv[4] || '45000', 10);

if (!url) {
    process.stderr.write('Usage: node render-page.cjs <url> [delay_ms] [nav_timeout_ms]\n');
    process.exit(1);
}

(async () => {
    let browser;
    let profileDir = null;
    let exitCode = 0;
    try {
        const cfg = buildLaunchConfig();
        profileDir = cfg.profileDir;
        browser = await puppeteer.launch(cfg.launchOptions);
        const page = await browser.newPage();

        await page.setUserAgent(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
        );
        await page.setViewport({ width: 1280, height: 900 });

        await page.setExtraHTTPHeaders({
            'Accept-Language': 'en-US,en;q=0.9',
            'Sec-Ch-Ua': '"Google Chrome";v="131", "Chromium";v="131", "Not_A Brand";v="24"',
            'Sec-Ch-Ua-Mobile': '?0',
            'Sec-Ch-Ua-Platform': '"Windows"',
        });

        await page.evaluateOnNewDocument(() => {
            Object.defineProperty(navigator, 'webdriver', { get: () => false });
        });

        let host = '';
        try {
            host = new URL(url).hostname.toLowerCase();
        } catch {
            host = '';
        }
        const isBonfire = host.includes('bonfirehub.com');

        const safeNavMs = Math.min(Math.max(15000, navTimeout), 120000);
        await page.goto(url, { waitUntil: 'domcontentloaded', timeout: safeNavMs });

        if (isBonfire) {
            try {
                await page.waitForFunction(
                    () => {
                        const t = (document.body && document.body.innerText) ? document.body.innerText.replace(/\s+/g, ' ').trim() : '';
                        if (t.length < 800) {
                            return false;
                        }
                        const head = t.slice(0, 120).toLowerCase();
                        if (head.includes('working') && head.includes('loading') && t.length < 2500) {
                            return false;
                        }
                        return true;
                    },
                    { timeout: Math.min(55000, Math.max(5000, navTimeout)) }
                );
            } catch {
                // continue with settle delay
            }
        }

        const maxSettle = isBonfire ? 30000 : 8000;
        const settleMs = Math.min(Math.max(0, delay), maxSettle);
        if (settleMs > 0) {
            await new Promise((r) => setTimeout(r, settleMs));
        }

        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await new Promise((r) => setTimeout(r, 800));

        const html = await page.content();
        process.stdout.write(html);
    } catch (err) {
        process.stderr.write(err.message + '\n');
        exitCode = 1;
    } finally {
        if (browser) {
            await browser.close().catch(() => {});
        }
        removeChromeProfile(profileDir);
    }
    if (exitCode) {
        process.exit(exitCode);
    }
})();
