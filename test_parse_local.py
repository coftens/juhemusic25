import requests
import json

def test_wyy_playlist(pid):
    print(f"Testing WYY Playlist Parsing for ID: {pid}")
    url = f"http://127.0.0.1:8002/playlist?source=wyy&id={pid}"
    try:
        resp = requests.get(url, timeout=15)
        print(f"Status Code: {resp.status_code}")
        
        if resp.status_code == 200:
            data = resp.json()
            if data.get('code') == 200:
                tracks = data.get('data', {}).get('list', [])
                print(f"Success! Found {len(tracks)} tracks.")
                if tracks:
                    print("First track sample:", tracks[0])
            else:
                print("API Error:", data)
        else:
            print("Server returned error:", resp.text)
            
    except Exception as e:
        print(f"Connection failed: {e}")

if __name__ == "__main__":
    # Test with the ID from your logs
    test_wyy_playlist("5363644086")
