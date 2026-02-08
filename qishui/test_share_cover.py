import requests
import re
import json

def fetch_share_page(track_id):
    url = f"https://music.douyin.com/qishui/share/track?track_id={track_id}"
    headers = {
        "user-agent": "Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1"
    }
    response = requests.get(url, headers=headers)
    if response.status_code == 200:
        with open("share_page_debug.html", "w", encoding="utf-8") as f:
            f.write(response.text)
        print("Saved share page to share_page_debug.html")
        
        # Try to find cover in ld+json
        match = re.search(r'<script data-react-helmet="true" type="application\/ld\+json">(.*?)<\/script>', response.text, re.S)
        if match:
            ld_data = json.loads(match.group(1))
            print("Cover from ld+json:", ld_data.get("images"))
            
        # Try to find cover in _ROUTER_DATA
        match = re.search(r'_ROUTER_DATA\s*=\s*({.*?});', response.text, re.S)
        if match:
            router_data = json.loads(match.group(1))
            track_page = router_data.get('loaderData', {}).get('track_page', {})
            # Look for cover in track_page
            # Based on dymusic.php, it might be in different places
            print("Router data keys:", track_page.keys())

if __name__ == "__main__":
    fetch_share_page("7164475471241152512")
