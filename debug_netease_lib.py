import sys
import json
import os
from pathlib import Path

# 1. 设置路径，引入 Netease_url-main 库
current_dir = Path(os.getcwd())
lib_path = current_dir / "网易云解析" / "Netease_url-main"
if not lib_path.exists():
    print(f"Error: Library path not found at {lib_path}")
    sys.exit(1)

sys.path.insert(0, str(lib_path))

try:
    from music_api import playlist_detail
except ImportError as e:
    print(f"Error importing music_api: {e}")
    sys.exit(1)

# 2. 读取本地 Cookie
cookie_file = current_dir / "wyy" / "cookie"
cookie_str = ""
if cookie_file.exists():
    cookie_str = cookie_file.read_text(encoding='utf-8').strip()
    print("Loaded local wyy/cookie.")
else:
    print("Warning: wyy/cookie not found, using empty cookie.")

# 将 Cookie 字符串转换为字典 (库要求的格式)
cookies = {}
if cookie_str:
    for item in cookie_str.split(';'):
        if '=' in item:
            k, v = item.split('=', 1)
            cookies[k.strip()] = v.strip()

# 3. 执行解析
# 使用你在抓包文件里出现的 ID: 5472305020
playlist_id = 5472305020 

print(f"\n=== Parsing Playlist ID: {playlist_id} ===")
try:
    # 调用库函数
    result = playlist_detail(playlist_id, cookies)
    
    # 4. 输出结果摘要
    print(f"解析成功!")
    print(f"歌单名称: {result.get('name')}")
    print(f"创建者: {result.get('creator')}")
    print(f"封面图: {result.get('coverImgUrl')}")
    print(f"歌曲数量: {len(result.get('tracks', []))}")
    
    print("\n--- 前 3 首歌曲详情 ---")
    for i, track in enumerate(result.get('tracks', [])[:3]):
        print(f"[{i+1}] {track.get('name')} - {track.get('artists')} (ID: {track.get('id')})")
        print(f"    Album: {track.get('album')}")
        print(f"    Pic: {track.get('picUrl')}")

    print("\n--- 原始返回数据结构 (部分) ---")
    # 打印部分 JSON 供检查结构
    json_str = json.dumps(result, ensure_ascii=False)
    print(json_str[:1000] + "...")

except Exception as e:
    print(f"解析失败: {e}")
    import traceback
    traceback.print_exc()
