const puppeteer = require('puppeteer');

const url = process.argv[2];
const delay = parseInt(process.argv[3] || '3000', 10);
const maxInteractions = parseInt(process.argv[4] || '8', 10);

if (!url) {
    process.stderr.write('Usage: node collect-interactions.cjs <url> [delay_ms] [max_interactions]\n');
    process.exit(1);
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function stableGoto(page, targetUrl) {
    await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 45000 });
    await sleep(1500);
}

function trimHtml(html) {
    const limit = 300000;
    if (!html || html.length <= limit) {
        return html || '';
    }
    return html.slice(0, limit);
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

        await stableGoto(page, url);
        if (delay > 0) {
            await sleep(delay);
        }

        const snapshots = [];
        const seen = new Set();

        async function capture(label, interactionType) {
            await sleep(900);
            const text = await page.evaluate(() => (document.body ? document.body.innerText : '').replace(/\s+/g, ' ').trim());
            if (!text || text.length < 40) {
                return;
            }
            const key = text.slice(0, 20000);
            if (seen.has(key)) {
                return;
            }
            seen.add(key);
            snapshots.push({
                label,
                interaction_type: interactionType,
                url: page.url(),
                text,
                html: trimHtml(await page.content()),
            });
        }

        await capture('Initial rendered page', 'initial');

        const candidates = await page.evaluate((limit) => {
            const positive = /(bid|bids|rfp|rfq|rfi|ifb|itb|solicitation|opportunit|procure|open|current|detail|view|more|expand|show|document|attachment|addend|proposal|quote|tender|scope|description|date|contact|question|schedule|specification)/i;
            const negative = /(login|sign in|register|cart|facebook|twitter|linkedin|share|privacy|terms|accessibility|language|translate|print|email|subscribe|close|cancel|delete|remove|reset|home)/i;

            const textOf = (el) => [
                el.innerText,
                el.textContent,
                el.getAttribute('aria-label'),
                el.getAttribute('title'),
                el.getAttribute('data-html'),
                el.getAttribute('data-original-title'),
                el.getAttribute('href'),
            ].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim();

            const visible = (el) => {
                const style = window.getComputedStyle(el);
                const rect = el.getBoundingClientRect();
                return style &&
                    style.visibility !== 'hidden' &&
                    style.display !== 'none' &&
                    rect.width > 0 &&
                    rect.height > 0 &&
                    !el.disabled &&
                    el.getAttribute('aria-disabled') !== 'true';
            };

            const items = [];
            const controls = Array.from(document.querySelectorAll(
                'button, [role="button"], [role="tab"], [aria-expanded], summary, a[href^="#"], a[href^="javascript:"], .accordion, .tab, .dropdown'
            ));

            for (const el of controls) {
                if (!visible(el)) {
                    continue;
                }
                const text = textOf(el);
                if (!text || text.length < 2 || text.length > 500) {
                    continue;
                }
                if (!positive.test(text) && !/(accordion|tab|dropdown)/i.test(el.className || '')) {
                    continue;
                }
                if (negative.test(text) && !positive.test(text)) {
                    continue;
                }

                const id = `codex_interaction_${items.length}`;
                el.setAttribute('data-codex-interaction-id', id);
                items.push({ id, label: text.slice(0, 180), type: 'click' });
                if (items.length >= limit) {
                    break;
                }
            }

            if (items.length < limit) {
                const selects = Array.from(document.querySelectorAll('select'));
                for (const select of selects) {
                    if (!visible(select)) {
                        continue;
                    }
                    const selectText = textOf(select);
                    const options = Array.from(select.options || []);
                    for (const option of options) {
                        const optionText = `${selectText} ${option.textContent || ''} ${option.value || ''}`.replace(/\s+/g, ' ').trim();
                        if (!option.value || option.disabled || !positive.test(optionText)) {
                            continue;
                        }
                        if (negative.test(optionText) && !positive.test(optionText)) {
                            continue;
                        }
                        const id = `codex_select_${items.length}`;
                        select.setAttribute('data-codex-interaction-id', id);
                        items.push({ id, value: option.value, label: optionText.slice(0, 180), type: 'select' });
                        break;
                    }
                    if (items.length >= limit) {
                        break;
                    }
                }
            }

            return items.slice(0, limit);
        }, maxInteractions);

        for (const candidate of candidates) {
            try {
                if (candidate.type === 'select') {
                    await page.select(`[data-codex-interaction-id="${candidate.id}"]`, candidate.value);
                    await page.evaluate((id) => {
                        const el = document.querySelector(`[data-codex-interaction-id="${id}"]`);
                        if (el) {
                            el.dispatchEvent(new Event('input', { bubbles: true }));
                            el.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }, candidate.id);
                } else {
                    const element = await page.$(`[data-codex-interaction-id="${candidate.id}"]`);
                    if (!element) {
                        continue;
                    }
                    await element.evaluate((el) => el.scrollIntoView({ block: 'center', inline: 'center' }));
                    await sleep(250);
                    await element.click({ delay: 50 });
                }

                await page.waitForNetworkIdle({ idleTime: 700, timeout: 6000 }).catch(() => {});
                await capture(candidate.label, candidate.type);
                await page.keyboard.press('Escape').catch(() => {});
            } catch (err) {
                // Keep exploring other controls; many procurement sites attach fragile handlers.
            }
        }

        process.stdout.write(JSON.stringify({ pages: snapshots }));
    } catch (err) {
        process.stderr.write(err.message + '\n');
        process.exit(1);
    } finally {
        if (browser) {
            await browser.close();
        }
    }
})();
