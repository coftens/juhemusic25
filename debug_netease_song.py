import sys
import json
import os
import re
from pathlib import Path

# 1. 设置路径，引入 Netease_url-main 库
current_dir = Path(os.getcwd())
lib_path = current_dir / "网易云解析" / "Netease_url-main"
if not lib_path.exists():
    print(f"Error: Library path not found at {lib_path}")
    sys.exit(1)

sys.path.insert(0, str(lib_path))

try:
    from music_api import url_v1, name_v1, lyric_v1
except ImportError as e:
    print(f"Error importing music_api: {e}")
    sys.exit(1)

# 2. 读取本地 Cookie
cookie_file = current_dir / "wyy" / "cookie"
cookies = {}
if cookie_file.exists():
    cookie_str = cookie_file.read_text(encoding='utf-8').strip()
    for item in cookie_str.split(';'):
        if '=' in item:
            k, v = item.split('=', 1)
            cookies[k.strip()] = v.strip()
    print("Loaded local wyy/cookie.")

# 3. 提取 ID 逻辑 (模仿库里的行为)
def extract_id(url):
    index = url.find('id=') + 3
    if index > 2:
        return url[index:].split('&')[0]
    return url

# 4. 执行解析
song_url = "https://music.163.com/song?id=2115519354"
song_id = int(extract_id(song_url))

print(f"\n=== Parsing Song ID: {song_id} ===")
try:
    # A. 获取基本信息
    detail = name_v1(song_id)
    if detail and 'songs' in detail and detail['songs']:
        s = detail['songs'][0]
        print(f"歌曲: {s.get('name')}")
        print(f"歌手: {'/'.join([a['name'] for a in s.get('ar', [])])}")
        print(f"专辑: {s.get('al', {}).get('name')}")
    
    # B. 尝试不同音质
    qualities = ['standard', 'exhigh', 'lossless', 'hires', 'jymaster']
    print("\n--- 音质地址测试 ---")
    for q in qualities:
        res = url_v1(song_id, q, cookies)
        if res and res.get('data'):
            item = res['data'][0]
            u = item.get('url')
            size = item.get('size')
            level = item.get('level')
            print(f"[{q:8}] URL: {'YES' if u else 'NO':3} | Level: {level:8} | Size: {size/1024/1024:5.2f}MB")
            if u:
                print(f"         Link: {u[:80]}...")
        else:
            print(f"[{q:8}] FAILED")

    # C. 获取歌词
    lrc_res = lyric_v1(song_id, cookies)
    lrc = lrc_res.get('lrc', {}).get('lyric', '')
    preview = lrc[:100].replace('\n', ' ')
    print(f"\n歌词预览: {preview}...")

except Exception as e:
    print(f"解析失败: {e}")
    import traceback
    traceback.print_exc()
