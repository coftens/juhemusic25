import requests
import re
from qishui_updater import get_track_info
import json

def resolve_qishui_link(short_url):
    print(f"正在解析分享链接: {short_url}")
    headers = {
        "User-Agent": "Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1"
    }
    try:
        # 获取重定向后的链接
        response = requests.get(short_url, headers=headers, allow_redirects=True, timeout=10)
        final_url = response.url
        print(f"重定向至: {final_url}")
        
        # 提取 track_id
        track_id_match = re.search(r'track_id=(\d+)', final_url)
        if track_id_match:
            return track_id_match.group(1)
        
        # 备选提取方式 (路径中可能包含 ID)
        path_match = re.search(r'track/(\d+)', final_url)
        if path_match:
            return path_match.group(1)
            
    except Exception as e:
        print(f"解析失败: {e}")
    return None

if __name__ == "__main__":
    short_url = "https://qishui.douyin.com/s/i9BwUXXT/"
    track_id = resolve_qishui_link(short_url)
    
    if track_id:
        print(f"成功提取 Track ID: {track_id}")
        info = get_track_info(track_id)
        if info:
            print("\n=== 解析结果 ===")
            print(f"歌曲名称: {info['name']}")
            print(f"歌手: {info['artist']}")
            print(f"封面: {info['cover']}")
            print(f"播放链接 (音频直链):")
            for quality, data in info['audio_urls'].items():
                if isinstance(data, dict):
                    print(f"  - {quality} (加密): {data['url'][:100]}...")
                    print(f"    Key (spade_a): {data['spade_a']}")
                else:
                    print(f"  - {quality} (直链): {data[:100]}...")
        else:
            print("获取歌曲详情失败")
    else:
        print("未能从链接中解析出 Track ID")
