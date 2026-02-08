const http = require('http');
const QQMusic = require('./qqmusic_flac-main/qqmusic_flac-main/qqapi');

const PORT = 8003;
const HOST = '127.0.0.1';

// Tiers to check (parallel fetch optimization)
const TIERS = {
  atmos_51: 'atmos_51',   // 臻品音质 2.0 (5.1声道)
  atmos_2: 'atmos_2',     // 臻品全景声 2.0
  master: 'master',         // 臻品母带 3.0
  flac: 'flac',           // SQ 无损品质
  320: '320',              // HQ 高品质
  ogg_320: 'ogg_320',     // OGG 高品质
  aac_192: 'aac_192',     // AAC 高品质
  ogg_192: 'ogg_192',     // OGG 标准
  128: '128',              // 标准
  aac_96: 'aac_96',       // AAC 标准
};

const server = http.createServer(async (req, res) => {
  if (req.method !== 'POST') {
    res.writeHead(405);
    res.end('Method Not Allowed');
    return;
  }

  let body = '';
  req.on('data', chunk => {
    body += chunk.toString();
  });

  req.on('end', async () => {
    try {
      if (!body) throw new Error('Empty body');
      const data = JSON.parse(body);
      const url = (data.url || '').trim();
      const cookie = (data.cookie || '').trim();

      if (!url) throw new Error('Missing url');
      
      // Start processing
      const qq = new QQMusic();
      if (cookie) {
        qq.setCookies(cookie);
      }

      // 1. Get Song MID
      // Try to extract from URL first (faster)
      // If failed, use library logic? The library logic seems to be:
      // qq.ids(url) -> returns mid string
      
      let songmid = '';
      try {
        // HACK: The original library might rely on 'ids' method which extracts mid from url
        // or fetches it. Let's see qq_bridge.js usage:
        // const songmid = qq.ids(url);
        // We replicate this.
        // Assuming the library is stateless regarding this helper.
        // If qq.ids is not available on instance, we might need to check the library source.
        // But qq_bridge.js used `const qq = new QQMusic(); const songmid = qq.ids(url);`
        // so it should be fine.
        
        // Wait, looking at qq_bridge.js, it does:
        // const qq = new QQMusic();
        // qq.setCookies(cookie);
        // const songmid = qq.ids(url);
        
        // Let's optimize: extracting mid from URL via Regex is faster than library overhead if possible,
        // but let's stick to library for correctness first.
        
        // Actually, let's look at `server.py`'s extract_qq_songmid function.
        // It does regex. Maybe we can pass mid directly? 
        // But existing frontend passes URL. Let's stick to URL.
        
        // To be safe, let's use the library's method as before.
        // But wait, does qq.ids(url) make a network request? 
        // Usually 'ids' suggests parsing.
        
        // Let's assume it's synchronous or fast enough.
        
        // Optimization: Create a fresh instance might be cheap, but we could reuse if possible.
        // But cookies change per user (potentially), so fresh instance is safer.
      } catch (e) {}

      // The library seems to attach `ids` to prototype.
      // Let's proceed.
      
      // However, we can't see library code. 
      // We'll trust qq_bridge.js's usage pattern.
      
      // Re-instantiate to be safe (stateless per request)
      // const qq = new QQMusic(); // moved up
      
      songmid = qq.ids(url);
      
      if (!songmid) {
         throw new Error('Invalid URL: cannot extract songmid');
      }

      // 2. Parallel Fetch Qualities
      // This is the BIG optimization.
      // Instead of for-loop await, we map to promises.
      
      const tasks = Object.keys(TIERS).map(async (key) => {
        try {
          const r = await qq.getMusicUrl(songmid, key);
          if (r && r.url) {
            return { key, data: { url: r.url, bitrate: r.bitrate } };
          }
        } catch (e) {
          // ignore error for this tier
        }
        return null;
      });

      const results = await Promise.all(tasks);
      
      const music_url = {};
      results.forEach(item => {
        if (item) {
          music_url[item.key] = item.data;
        }
      });

      const out = {
        music_info: { mid: songmid },
        music_url,
      };

      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(out));

    } catch (e) {
      res.writeHead(500, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: e.message || String(e) }));
    }
  });
});

server.listen(PORT, HOST, () => {
  console.log(`QQ Bridge Server running at http://${HOST}:${PORT}/`);
});
