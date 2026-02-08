# Step 3 - Python Parse Microservice

This step exposes a small HTTP API that wraps:
- Netease (Python library in `网易云解析/Netease_url-main`)
- QQ (Node library in `qqmusic_flac-main/qqmusic_flac-main`)
- Qishui (PHP script in `汽水音乐解析/dymusic.php`)

## Endpoints

### Parse

`GET /parse?url={link}[&quality=lossless|exhigh|standard]`

Returns:
- `best.url`: audio stream url
- `qualities`: available quality urls (when supported)

### Search

`GET /search?keyword={kw}&platform={qq|wyy}[&limit=1..50]`

Returns a list containing `share_url` that can be fed back into `/parse`.

## Configuration (Linux-friendly)

Everything is configured via env vars (or left to defaults):

- `HOST` (default: `0.0.0.0`)
- `PORT` (default: `8002`)
- `DEBUG` (default: `0`)

- `QQ_COOKIE_PATH` (default: auto-detect `qq/cookie` or `music/qq/cookie` relative to repo)
- `WYY_COOKIE_PATH` (default: auto-detect `wyy/cookie` or `music/wyy/cookie` relative to repo)

- `NODE_BIN` (default: `node`)
- `PHP_BIN` (default: `php`)

## Dependencies

System:
- Node.js (for QQ)
- PHP (for Qishui)

Python:
- Install the Netease parser dependencies:
  - use `网易云解析/Netease_url-main/requirements.txt`

## Run

Run the service:

```bash
python server.py
```

## Smoke tests

Example URLs:
- QQ: `https://y.qq.com/n/ryqq_v2/songDetail/003cSLOO35W3yP`
- WYY: `https://music.163.com/song?id=3333988321`
- Qishui: `https://qishui.douyin.com/s/ia2T2aMo/`
