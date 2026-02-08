import requests
import json

def test_qq_parse(url):
    print(f"Testing QQ Parse for URL: {url}")
    target = "http://127.0.0.1:8002/parse"
    try:
        resp = requests.get(target, params={"url": url, "quality": "128"}, timeout=15)
        print(f"Status Code: {resp.status_code}")
        
        if resp.status_code == 200:
            data = resp.json()
            if data.get('code') == 200:
                d = data.get('data', {})
                print(f"Success! Best URL: {d.get('best', {}).get('url')}")
            else:
                print("API Error:", data)
        else:
            print("Server returned error:", resp.text)
            
    except Exception as e:
        print(f"Connection failed: {e}")

if __name__ == "__main__":
    # Test with the QQ song URL from your logs
    test_qq_parse("https://y.qq.com/n/ryqq/songDetail/004GEXe13HYtsE")
