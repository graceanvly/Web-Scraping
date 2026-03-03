const puppeteer = require('puppeteer');

const url = process.argv[2];
const delay = parseInt(process.argv[3] || '5000', 10);

if (!url) {
    process.stderr.write('Usage: node render-page.cjs <url> [delay_ms]\n');
    process.exit(1);
}

(async () => {
    let browser;
    try {
        browser = await puppeteer.launch({
            headless: 'new',
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-blink-features=AutomationControlled',
                '--window-size=1280,900',
            ],
        });
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

        try {
            await page.goto(url, { waitUntil: 'networkidle2', timeout: 60000 });
        } catch (navErr) {
            if (navErr.message && navErr.message.includes('timeout')) {
                await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
                await new Promise(r => setTimeout(r, 3000));
            } else {
                throw navErr;
            }
        }

        if (delay > 0) {
            await new Promise(r => setTimeout(r, delay));
        }

        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
        await new Promise(r => setTimeout(r, 1000));

        const html = await page.content();
        process.stdout.write(html);
    } catch (err) {
        process.stderr.write(err.message + '\n');
        process.exit(1);
    } finally {
        if (browser) await browser.close();
    }
})();
