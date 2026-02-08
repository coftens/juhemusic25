import requests
from pathlib import Path

def test():
    # 1. 读取本地 Cookie
    cookie_path = Path("qq/cookie")
    if not cookie_path.exists():
        print("Error: qq/cookie file missing!")
        return
    cookie_str = cookie_path.read_text().strip()
    
    # 2. 构造请求
    url = "https://y.qq.com/n/ryqq/songDetail/004GEXe13HYtsE"
    payload = {
        "url": url,
        "cookie": cookie_str
    }
    
    print(f"Testing Node server (8003) with local cookie...")
    try:
        # 直接测试 Node 服务
        resp = requests.post("http://127.0.0.1:8003/", json=payload, timeout=15)
        print(f"Node Status: {resp.status_code}")
        data = resp.json()
        
        music_url = data.get("music_url", {})
        if music_url:
            print("Success! Available qualities:", list(music_url.keys()))
            # 打印一个具体的 URL 看看
            first_key = list(music_url.keys())[0]
            print(f"Sample URL ({first_key}): {music_url[first_key].get('url')[:50]}...")
        else:
            print("Failure: music_url is empty even with local cookie.")
            print("Full response from Node:", data)
            
    except Exception as e:
        print(f"Test failed: {e}")

if __name__ == "__main__":
    test()
