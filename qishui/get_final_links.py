import requests
import json

api_base = "http://127.0.0.1:8372"

def get_final_links():
    try:
        # 1. 从 Feed 获取第一首歌
        print(f"正在请求: {api_base}/feed?count=1")
        resp = requests.get(f"{api_base}/feed?count=1")
        print(f"状态码: {resp.status_code}")
        feed = resp.json()
        print(f"Feed 数据: {json.dumps(feed, indent=2, ensure_ascii=False)[:200]}...")
        if not feed.get("data"):
            print("Error: No data in feed")
            return
            
        song = feed["data"][0]
        track_id = song["track_id"]
        
        # 2. 获取该歌曲的详情（包含解密后的 play_url）
        track = requests.get(f"{api_base}/track?id={track_id}").json()
        
        print("\n" + "="*50)
        print(f"歌曲: {track['name']}")
        print(f"ID: {track_id}")
        print("="*50)
        
        found_encrypted = False
        for quality, data in track["audio_urls"].items():
            if data.get("is_encrypted"):
                print(f"音质: {quality}")
                print(f"最终解密播放链接 (直接在浏览器打开即可播放):")
                print(f"{data['play_url']}")
                print("-" * 30)
                found_encrypted = True
        
        if not found_encrypted:
            # 如果没有加密链接，展示标准链接
            standard = track["audio_urls"].get("Standard")
            if standard:
                print(f"音质: Standard")
                print(f"播放链接 (无需解密):")
                print(f"{standard['play_url']}")
        
        print("="*50)
        
    except Exception as e:
        print(f"Error: {e}")

if __name__ == "__main__":
    print("正在连接到 API 服务...")
    get_final_links()
