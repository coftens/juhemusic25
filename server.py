#!/usr/bin/env python3
import json
import os
import re
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple
import requests
import atexit
import time

from flask import Flask, jsonify, request

@dataclass
class Config:
    host: str = os.getenv("HOST", "0.0.0.0")
    port: int = int(os.getenv("PORT", "8002"))
    debug: bool = os.getenv("DEBUG", "0") == "1"

    node_bin: str = os.getenv("NODE_BIN", "node")
    php_bin: str = os.getenv("PHP_BIN", "php")

    qq_cookie_path: str = os.getenv("QQ_COOKIE_PATH", "")
    wyy_cookie_path: str = os.getenv("WYY_COOKIE_PATH", "")

APP = Flask(__name__)
CFG = Config()

def api_ok(data: Any) -> Tuple[Any, int]:
    return jsonify({"code": 200, "msg": "ok", "data": data}), 200

def api_err(msg: str, code: int = 500) -> Tuple[Any, int]:
    return jsonify({"code": code, "msg": msg, "data": None}), 200

def find_cookie_path(explicit: str, candidates: List[str]) -> Path:
    if explicit:
        p = Path(explicit)
        if p.is_file():
            return p
        raise RuntimeError(f"Cookie file not found: {p}")

    root = Path(__file__).resolve().parent
    for rel in candidates:
        p = (root / rel).resolve()
        if p.is_file():
            return p
    raise RuntimeError(f"Cookie file not found. Tried: {candidates}")

def read_cookie_string(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="ignore").strip()

def parse_cookie_to_dict(cookie_str: str) -> Dict[str, str]:
    out: Dict[str, str] = {}
    for part in cookie_str.split(";"):
        part = part.strip()
        if not part or "=" not in part:
            continue
        k, v = part.split("=", 1)
        k = k.strip()
        v = v.strip()
        if k:
            out[k] = v
    return out

QQ_COOKIE_PATH = find_cookie_path(CFG.qq_cookie_path, ["music/qq/cookie", "qq/cookie"])
WYY_COOKIE_PATH = find_cookie_path(CFG.wyy_cookie_path, ["music/wyy/cookie", "wyy/cookie"])
QQ_COOKIE_STR = read_cookie_string(QQ_COOKIE_PATH)
WYY_COOKIE_STR = read_cookie_string(WYY_COOKIE_PATH)
QQ_COOKIE_KV = parse_cookie_to_dict(QQ_COOKIE_STR)
WYY_COOKIE_KV = parse_cookie_to_dict(WYY_COOKIE_STR)

def detect_platform(url: str) -> str:
    u = url.lower()
    if "music.163.com" in u or "163cn.tv" in u:
        return "wyy"
    if "y.qq.com" in u:
        return "qq"
    if "qishui.douyin.com" in u or "music.douyin.com/qishui" in u or "track_id=" in u:
        return "qishui"
    raise ValueError("Unsupported url")

def extract_wyy_song_id(url: str) -> str:
    m = re.search(r"[?#&]id=(\d+)", url)
    if m:
        return m.group(1)
    m = re.search(r"\b(\d{6,})\b", url)
    if m:
        return m.group(1)
    raise ValueError("Cannot extract Netease song id")

def extract_qq_songmid(url: str) -> str:
    m = re.search(r"/songDetail/([^/?#]+)", url)
    if m:
        return m.group(1)
    m = re.search(r"\bmid=([A-Za-z0-9]+)", url)
    if m:
        return m.group(1)
    raise ValueError("Cannot extract QQ song mid")

import threading

QQ_LOCK = threading.Lock()

def run_node_qq_parse(url: str) -> Dict[str, Any]:
    target = "http://127.0.0.1:8003/"
    payload = {
        "url": url,
        "cookie": QQ_COOKIE_STR
    }
    try:
        # Enforce sequential access to the Node bridge to prevent library state pollution
        with QQ_LOCK:
            resp = requests.post(target, json=payload, timeout=30)
        resp.raise_for_status()
        return resp.json()
    except requests.RequestException as e:
        raise RuntimeError(f"QQ Bridge Server Error: {e}")
# -----------------------------

def run_php_qishui_parse(url: str) -> Dict[str, Any]:
    php_file = Path(__file__).resolve().parent / "汽水音乐解析" / "dymusic.php"
    if not php_file.is_file():
        raise RuntimeError(f"Missing qishui parser: {php_file}")

    # Avoid shell quoting issues by passing the URL via env.
    env = os.environ.copy()
    env["QS_URL"] = url
    code = "$_GET['url']=getenv('QS_URL'); include '汽水音乐解析/dymusic.php';"

    p = subprocess.run(
        [CFG.php_bin, "-r", code],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=env,
        timeout=60,
    )
    if p.returncode != 0:
        stderr = (p.stderr or b"").decode("utf-8", errors="replace")
        stdout = (p.stdout or b"").decode("utf-8", errors="replace")
        raise RuntimeError((stderr or stdout or "php error").strip())
    try:
        stdout = (p.stdout or b"").decode("utf-8", errors="replace")
        return json.loads(stdout)
    except Exception as e:
        raise RuntimeError(f"Qishui parse JSON decode failed: {e}")

def wyy_parse(url: str, quality: str) -> Dict[str, Any]:
    netease_dir = Path(__file__).resolve().parent / "网易云解析" / "Netease_url-main"
    if not netease_dir.is_dir():
        raise RuntimeError("Missing Netease_url-main")
    sys.path.insert(0, str(netease_dir))
    from music_api import url_v1  # type: ignore
    from concurrent.futures import ThreadPoolExecutor, as_completed

    song_id = int(extract_wyy_song_id(url))
    print(f"[WYY Parse] ID: {song_id}, Cookie len: {len(WYY_COOKIE_KV)}")

    # Full list of supported qualities
    priority = ["jymaster", "jyeffect", "sky", "hires", "lossless", "exhigh", "standard"]
    
    qualities: Dict[str, Dict[str, Any]] = {}

    # Helper function for threaded execution
    def fetch_quality(q):
        try:
            resp = url_v1(song_id, q, WYY_COOKIE_KV)
            if isinstance(resp, dict):
                data = resp.get("data")
                if isinstance(data, list) and data:
                    item = data[0]
                    if isinstance(item, dict):
                        u = str(item.get("url") or "")
                        br = item.get("br")
                        size = item.get("size")
                        print(f"  -> Q: {q}, URL: {'YES' if u else 'NO'}, BR: {br}, Size: {size}")
                        if u:
                            return q, {
                                "url": u,
                                "level": str(item.get("level") or q),
                                "type": item.get("type"),
                                "br": br,
                                "size": size,
                            }
        except Exception as e:
            print(f"  -> Q: {q} Error: {e}")
            pass
        return q, None

    # Fetch all qualities in parallel to populate the selector
    with ThreadPoolExecutor(max_workers=7) as executor:
        future_to_quality = {executor.submit(fetch_quality, q): q for q in priority}
        for future in as_completed(future_to_quality):
            q, result = future.result()
            if result:
                qualities[q] = result

    if not qualities:
        # Try one last ditch effort with standard quality and NO cookie (sometimes helps with IP restrictions?)
        # Or maybe the song requires VIP and we don't have it.
        print("[WYY Parse] All qualities failed. Trying standard without cookie...")
        try:
            _, res = fetch_quality("standard") # Retry standard
            if res:
                qualities["standard"] = res
        except:
            pass

    if not qualities:
        raise RuntimeError("WYY parse failed: no playable url")

    # Determine best quality
    # If user requested a specific one and it exists, use it.
    # Otherwise, pick the highest available from priority list.
    best_q = ""
    if quality and quality in qualities:
        best_q = quality
    else:
        for q in priority:
            if q in qualities:
                best_q = q
                break
    
    if not best_q:
        # Fallback to whatever we have
        best_q = list(qualities.keys())[0]

    best = {"quality": best_q, "url": qualities[best_q]["url"]}

    # --- DEDUPLICATION LOGIC ---
    # If multiple tags point to the same file (same size), keep only the highest priority one.
    final_qualities = {}
    seen_sizes = {}
    # Iterate in priority order to keep the best name
    for q in priority:
        if q in qualities:
            sz = qualities[q].get("size")
            if sz and sz > 0:
                if sz not in seen_sizes:
                    seen_sizes[sz] = q
                    final_qualities[q] = qualities[q]
            else:
                # If no size info, keep it anyway
                final_qualities[q] = qualities[q]
    
    # Ensure the 'best' quality is always in the list
    if best_q not in final_qualities:
        final_qualities[best_q] = qualities[best_q]

    print(f"[WYY Parse] Success. Best: {best_q}, Available (Deduplicated): {list(final_qualities.keys())}")

    return {
        "platform": "wyy",
        "id": str(song_id),
        "best": best,
        "qualities": final_qualities,
    }

def qq_parse(url: str, quality: str) -> Dict[str, Any]:
    data = run_node_qq_parse(url)
    music_info = data.get("music_info") or {}
    music_url = data.get("music_url") or {}
    if not isinstance(music_url, dict):
        music_url = {}

    normalized_qualities: Dict[str, Dict[str, Any]] = {}
    for api_key, item in music_url.items():
        if isinstance(item, dict) and item.get("url"):
            normalized_qualities[api_key] = {
                "url": item.get("url"),
                "bitrate": item.get("bitrate"),
            }

    priority = ["atmos_51", "atmos_2", "master", "flac", "320", "ogg_320", "aac_192", "ogg_192", "128", "aac_96"]
    best = {}

    if quality and quality in normalized_qualities:
        best = {"quality": quality, "url": normalized_qualities[quality]["url"]}
    else:
        for q in priority:
            if q in normalized_qualities:
                best = {"quality": q, "url": normalized_qualities[q]["url"]}
                break

    if not best.get("url"):
        raise RuntimeError("QQ parse failed: no playable url")

    print(f"[QQ Parse] URL: {url}")
    print(f"[QQ Parse] Available qualities: {list(normalized_qualities.keys())}")
    print(f"[QQ Parse] Best quality: {best}")

    return {
        "platform": "qq",
        "mid": str(music_info.get("mid") or extract_qq_songmid(url)),
        "best": best,
        "qualities": normalized_qualities,
    }

def qishui_parse(url: str) -> Dict[str, Any]:
    # Handle short links by resolving them first
    if "qishui.douyin.com/s/" in url or "v.douyin.com" in url:
        try:
            print(f"[Qishui] Resolving short link: {url}")
            r = requests.get(url, allow_redirects=True, timeout=10)
            url = r.url
            print(f"[Qishui] Resolved to: {url}")
        except Exception as e:
            print(f"[Qishui] Resolve failed: {e}")

    # Extract ID from URL like https://...track_id=123... or just raw ID
    track_id = ""
    m = re.search(r"track_id=(\d+)", url)
    if m:
        track_id = m.group(1)
    elif url.isdigit():
        track_id = url
    
    if not track_id:
        raise ValueError(f"Invalid Qishui URL/ID: {url}")

    print(f"[Qishui Parse] ID: {track_id}")
    
    # Call the local Qishui API service (Port 8372)
    api_url = f"http://127.0.0.1:8372/track?id={track_id}"
    try:
        resp = requests.get(api_url, timeout=15)
        resp.raise_for_status()
        res = resp.json()
        
        # Transform results
        # Qishui API returns: { "audio_urls": { "Standard": { "play_url": "..." }, "lossless": { "play_url": "..." } } }
        audio_urls = res.get("audio_urls", {})
        
        # Map Qishui quality names to internal standard labels
        quality_map = {
            "spatial": "sky",
            "lossless": "lossless",
            "highest": "exhigh",
            "higher": "standard"
        }
        
        qualities = {}
        main_spade_a = ""
        for q_key, q_data in audio_urls.items():
            # Use raw_url because we decrypt on device
            u = q_data.get("raw_url") or q_data.get("play_url")
            sa = q_data.get("spade_a", "")
            
            # Use our mapped name if exists, otherwise use original
            internal_q = quality_map.get(q_key.lower(), q_key.lower())
            
            if u:
                qualities[internal_q] = {
                    "url": u,
                    "level": internal_q,
                    "spade_a": sa
                }
                if sa and not main_spade_a:
                    main_spade_a = sa
        
        if not qualities:
            raise RuntimeError("No playable URL found in Qishui response")
            
        # Priority for best quality: sky > lossless > exhigh > standard
        priority = ["sky", "lossless", "exhigh", "standard"]
        best_q = ""
        for p in priority:
            if p in qualities:
                best_q = p
                break
        if not best_q:
            best_q = list(qualities.keys())[0]

        best = {
            "quality": best_q, 
            "url": qualities[best_q]["url"],
            "spade_a": qualities[best_q].get("spade_a", "")
        }
        
        return {
            "platform": "qishui",
            "id": track_id,
            "best": best,
            "qualities": qualities,
            "name": res.get("name"),
            "spade_a": main_spade_a
        }
    except Exception as e:
        raise RuntimeError(f"Qishui API failed: {e}")

def qq_search(keyword: str, limit: int) -> List[Dict[str, Any]]:
    import requests
    payload = {
        "search": {
            "module": "music.search.SearchCgiService",
            "method": "DoSearchForQQMusicDesktop",
            "param": {
                "query": keyword,
                "search_type": 0,
                "page_num": 1,
                "num_per_page": limit,
            },
        }
    }
    params = {
        "format": "json",
        "inCharset": "utf8",
        "outCharset": "utf-8",
        "platform": "yqq.json",
        "needNewCode": "0",
        "data": json.dumps(payload, ensure_ascii=False, separators=(",", ":")),
    }
    r = requests.get(
        "https://u.y.qq.com/cgi-bin/musicu.fcg",
        params=params,
        headers={
            "User-Agent": "Mozilla/5.0",
            "Referer": "https://y.qq.com/",
        },
        cookies=QQ_COOKIE_KV,
        timeout=30,
    )
    r.raise_for_status()
    j = r.json()
    items = (((j.get("search") or {}).get("data") or {}).get("body") or {}).get("song")
    lst = (items or {}).get("list") if isinstance(items, dict) else []
    if not isinstance(lst, list):
        lst = []

    out: List[Dict[str, Any]] = []
    for it in lst:
        if not isinstance(it, dict):
            continue
        mid = str(it.get("mid") or "")
        name = str(it.get("name") or "")
        singers = it.get("singer") if isinstance(it.get("singer"), list) else []
        artist = ", ".join(str(s.get("name") or "") for s in singers if isinstance(s, dict) and s.get("name"))
        album = it.get("album") if isinstance(it.get("album"), dict) else {}
        album_mid = str(album.get("mid") or "")
        cover = (
            f"https://y.gtimg.cn/music/photo_new/T002R500x500M000{album_mid}.jpg" if album_mid else ""
        )
        if not mid:
            continue
        out.append(
            {
                "mid": mid,
                "name": name,
                "artist": artist,
                "cover": cover,
                "share_url": f"https://y.qq.com/n/ryqq/songDetail/{mid}",
            }
        )
    return out

def wyy_search(keyword: str, limit: int) -> List[Dict[str, Any]]:
    netease_dir = Path(__file__).resolve().parent / "网易云解析" / "Netease_url-main"
    sys.path.insert(0, str(netease_dir))
    from music_api import search_music  # type: ignore

    res = search_music(keyword, WYY_COOKIE_KV, limit)
    out: List[Dict[str, Any]] = []
    if not isinstance(res, list):
        return out
    for it in res:
        if not isinstance(it, dict):
            continue
        sid = str(it.get("id") or "")
        if not sid:
            continue
        out.append(
            {
                "id": sid,
                "name": it.get("name"),
                "artist": it.get("artists"),
                "cover": it.get("picUrl"),
                "share_url": f"https://music.163.com/song?id={sid}",
            }
        )
    return out

def wyy_playlist_parse(playlist_id: str) -> Dict[str, Any]:
    netease_dir = Path(__file__).resolve().parent / "网易云解析" / "Netease_url-main"
    sys.path.insert(0, str(netease_dir))
    from music_api import playlist_detail  # type: ignore
    
    try:
        pid = int(playlist_id)
        print(f"[WYY Playlist] Requesting ID: {pid}")
        
        # 1. Try with Library (v6 API, requires good cookies)
        res = playlist_detail(pid, WYY_COOKIE_KV)
        tracks_count = len(res.get("tracks", []))
        
        # 2. Fallback: If library returns 0 tracks, try a simple public API call
        if tracks_count == 0:
            print(f"[WYY Playlist] Library returned 0 tracks. Trying public fallback API...")
            import requests
            fallback_url = f"https://music.163.com/api/playlist/detail?id={pid}"
            f_resp = requests.get(fallback_url, headers={
                "User-Agent": "Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1",
                "Referer": "https://music.163.com/"
            }, timeout=10)
            f_json = f_resp.json()
            f_pl = f_json.get("result") or f_json.get("playlist") or {}
            f_tracks = f_pl.get("tracks") or []
            
            if f_tracks:
                print(f"[WYY Playlist] Fallback Success! Found {len(f_tracks)} tracks.")
                # Transform fallback tracks to match expected structure
                processed_tracks = []
                for t in f_tracks:
                    ar_list = t.get("ar") or t.get("artists") or []
                    artist_str = "/".join([a.get("name", "Unknown") for a in ar_list])
                    processed_tracks.append({
                        "id": str(t.get("id")),
                        "name": t.get("name"),
                        "artists": artist_str,
                        "album": (t.get("al") or t.get("album") or {}).get("name"),
                        "picUrl": (t.get("al") or t.get("album") or {}).get("picUrl")
                    })
                res = {
                    "id": pid,
                    "name": f_pl.get("name"),
                    "coverImgUrl": f_pl.get("coverImgUrl"),
                    "tracks": processed_tracks
                }
            else:
                print(f"[WYY Playlist] All attempts failed for ID {pid}. API Response: {f_json}")

        # Transform to internal standard format
        tracks = []
        for t in res.get("tracks", []):
            tracks.append({
                "id": str(t.get("id", "")),
                "title": t.get("name", ""),
                "artist": t.get("artists", ""),
                "album": t.get("album", ""),
                "cover_url": t.get("picUrl", ""),
                "share_url": f"https://music.163.com/song?id={t.get('id', '')}"
            })
            
        print(f"[WYY Playlist] Final Success: returning {len(tracks)} tracks.")
        return {
            "source": "wyy",
            "id": str(res.get("id", "")),
            "title": res.get("name", ""),
            "cover_url": res.get("coverImgUrl", ""),
            "list": tracks
        }
    except Exception as e:
        import traceback
        traceback.print_exc()
        raise RuntimeError(f"Netease playlist parse error: {e}")
        import traceback
        traceback.print_exc()
        raise RuntimeError(f"Netease playlist parse error: {e}")

@APP.route("/playlist", methods=["GET"])
def playlist_route() -> Tuple[Any, int]:
    source = request.args.get("source", "wyy").strip().lower()
    id = request.args.get("id", "").strip()
    
    if not id:
        return api_err("missing id", 400)
        
    try:
        if source == "wyy":
            return api_ok(wyy_playlist_parse(id))
        return api_err("source not supported in python side yet", 400)
    except Exception as e:
        return api_err(str(e), 500)

@APP.route("/health", methods=["GET"])
def health() -> Tuple[Any, int]:
    return api_ok(
        {
            "service": "ok",
            "qq_cookie": str(QQ_COOKIE_PATH),
            "wyy_cookie": str(WYY_COOKIE_PATH),
        }
    )

@APP.route("/parse", methods=["GET"])
def parse_route() -> Tuple[Any, int]:
    url = request.args.get("url", "").strip()
    quality = request.args.get("quality", "lossless").strip().lower()
    if not url:
        return api_err("missing url", 400)
    try:
        platform = detect_platform(url)
        if platform == "wyy":
            return api_ok(wyy_parse(url, quality))
        if platform == "qq":
            return api_ok(qq_parse(url, quality))
        if platform == "qishui":
            return api_ok(qishui_parse(url))
        return api_err("unsupported platform", 400)
    except Exception as e:
        return api_err(str(e), 500)

@APP.route("/search", methods=["GET"])
def search_route() -> Tuple[Any, int]:
    keyword = request.args.get("keyword", "").strip()
    platform = request.args.get("platform", "").strip().lower()
    limit = int(request.args.get("limit", "20"))
    if not keyword:
        return api_err("missing keyword", 400)
    if platform not in ("qq", "wyy"):
        return api_err("platform must be qq or wyy", 400)
    if limit < 1:
        limit = 1
    if limit > 50:
        limit = 50
    try:
        if platform == "qq":
            return api_ok({"platform": "qq", "list": qq_search(keyword, limit)})
        return api_ok({"platform": "wyy", "list": wyy_search(keyword, limit)})
    except Exception as e:
        return api_err(str(e), 500)

if __name__ == "__main__":
    APP.run(host=CFG.host, port=CFG.port, debug=CFG.debug, threaded=True)