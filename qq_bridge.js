// Node bridge for QQ parse: prints JSON to stdout.
// Usage: node qq_bridge.js --url <qq_song_url>
// Cookie: pass via env QQ_COOKIE (raw "k=v; ..." string)

const QQMusic = require('./qqmusic_flac-main/qqmusic_flac-main/qqapi');

function parseArgs(argv) {
  const out = {};
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--url') {
      out.url = argv[i + 1] || '';
      i++;
    }
  }
  return out;
}

async function main() {
  const args = parseArgs(process.argv);
  const url = (args.url || '').trim();
  if (!url) {
    process.stderr.write('missing --url\n');
    process.exit(2);
  }

  const cookie = (process.env.QQ_COOKIE || '').trim();
  if (!cookie) {
    process.stderr.write('missing QQ_COOKIE env\n');
    process.exit(2);
  }

  const qq = new QQMusic();
  qq.setCookies(cookie);

  const songmid = qq.ids(url);
  if (!songmid) {
    process.stderr.write('invalid url: cannot extract songmid\n');
    process.exit(2);
  }

  // Request qualities that QQ Music app displays.
  // 标准音质/HQ高品质/SQ无损/臻品母带/臻品全景声/臻品音质/OGG高品质/OGG标准/AAC高品质/AAC标准
  const tiers = {
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

  const music_url = {};
  for (const key of Object.keys(tiers)) {
    try {
      const r = await qq.getMusicUrl(songmid, key);
      if (r && r.url) {
        music_url[key] = { url: r.url, bitrate: r.bitrate };
      }
    } catch (e) {
      // ignore individual tier failures
    }
  }

  // Minimal info; full metadata can be fetched later if needed.
  const out = {
    music_info: { mid: songmid },
    music_url,
  };
  process.stdout.write(JSON.stringify(out));
}

main().catch((e) => {
  process.stderr.write((e && e.stack) ? e.stack : String(e));
  process.exit(1);
});
