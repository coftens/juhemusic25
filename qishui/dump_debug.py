import requests
import json

COOKIE = "passport_csrf_token=a1e6c33afbab5a4bab83e36dd798892b; passport_csrf_token_default=a1e6c33afbab5a4bab83e36dd798892b; odin_tt=a53a2858a662a9484487717e379732dded2bbb7c5e0c2ce2b303f1373ba19420e283ec0a8fd0298b4a5e8e472be5a923d2eb13d8df87ff774399efab1a8e81b071a56c2d635b1936fdd000060d37e61f; passport_assist_user=CkG68ERwtrwJvZ7l-vBxBl3pCyJcrQZbdVWLgzjHvdt4JmY5X3N9Ufwn4hPuOlqyHbCFXxMqnJVWtVtnZZalZaBM-hpKCjwAAAAAAAAAAAAAUAE4z0ewtLRVuMNm_GG5FlR3l_Bu3Dif7SwwQe5AS8CXnJK9yq9U7LkjyKBt4UKklZcQyJKIDhiJr9ZUIAEiAQNdK5Rp; n_mh=gxsLTxwi84PAttEGgA6UZY20btQQqnZthqdOJLm2rnc; sid_guard=fee57bc91c1f06524c121504f2109dec|1769619649|5184000|Sun,+29-Mar-2026+17:00:49+GMT; uid_tt=177b9da4b66127906d0a4573a31b9a71; uid_tt_ss=177b9da4b66127906d0a4573a31b9a71; sid_tt=fee57bc91c1f06524c121504f2109dec; sessionid=fee57bc91c1f06524c121504f2109dec; sessionid_ss=fee57bc91c1f06524c121504f2109dec; session_tlb_tag=sttt|1|_uV7yRwfBlJMEhUE8hCd7P_________inxH34a7ShVOR04Ezpdwsqw7gJ4tog7UUC5sVjCL4l44; is_staff_user=false; sid_ucp_v1=1.0.0-KGFmOGQ4N2RmN2QyNDZlMjc4NWJmMjNiNzBlMGQ1ZDJkYWMxM2JjMmQKKgi3upCOgY35BRDBgenLBhioyBcgDCiwsaGg-qxqMJG5hJQGOAdA9AdIBBoCbHEiIGZlZTU3YmM5MWMxZjA2NTI0YzEyMTUwNGYyMTA5ZGVj; ssid_ucp_v1=1.0.0-KGFmOGQ4N2RmN2QyNDZlMjc4NWJmMjNiNzBlMGQ1ZDJkYWMxM2JjMmQKKgi3upCOgY35BRDBgenLBhioyBcgDCiwsaGg-qxqMJG5hJQGOAdA9AdIBBoCbHEiIGZlZTU3YmM5MWMxZjA2NTI0YzEyMTUwNGYyMTA5ZGVj; ttwid=1|2nDZQwpBDpSg5LSst1G-qX4naqimKJ251TqY-S4xiNQ|1769870189|6cb474ffa16129d944ec180a2f509731741e96d0e13c1ae5e2bbae2fa695e150"
USER_AGENT = "LunaPC/3.0.0(290101097)"
COMMON_PARAMS = {
    "aid": "386088",
    "app_name": "luna_pc",
    "device_id": "467737575446704",
    "version_name": "3.0.0",
    "device_platform": "windows",
    "os_version": "Windows 11 Home China"
}

def dump_track(track_id):
    url = "https://api.qishui.com/luna/pc/track_v2"
    headers = {
        "cookie": COOKIE,
        "user-agent": USER_AGENT,
        "content-type": "application/json; charset=utf-8",
        "X-Helios": "SicAACJWDNiSHEX4DSBVXo3+TNXAHXt9Af6CkPaMTmSX1Jcg",
        "X-Medusa": "GBR+aez8IZPMw6JSzT62GUzbH3GODwMBg7ZESAAAAQk/GduVkAZWRUCTX0SGSLVDDQ/gYOFKM/adGsI29F3FyR/OAoj+AK7fOY1Pe1po0w3w850g3Y0xvZOEl35RaWIynTM+dvmKmsQLoBG2LPT9eoaLqF8pi6MjvRdIJK8PMnnwDYrreh4OQ85zqzZdCFytOf6cXPH4NImgdUgBceuFfUtCN8ZdI3bRTDD28J8OxDK8vsWjdzimSPNTIe6C2EKel/U+PcqXfkbs/ZWCvHyxmqgrLfu5tHAtnXuEbQf6J53G8I6wdY8JQ5wm8+7o37XUiWC8FCB6y+09/aB9q4LTwNEMOlv50fAQg/bT9RgB6+7jF+7RXZyIuNkXAuJb2uZeBSzfJVvw6VITls5AFSOdNu376GqKGm4T6M8V9HzT2L8cW8smYgNG6HJPjd3iVVcv8fjeJeAGolEPMBbBvbAjJCSQAOY6jo/RGbRvOUsDyZgJ6fEp8ncjXIcK6Nw1GSPOv7AXWILqyt5sBpFDvPlpJTqih5TWbmWSEBc52+OPX2DJKknmz4qBPrRdJ7QvtxA5nrLDBjc3doDJa2iv1FE/7nUQoGJ5njCFw2BYfT9LE3kxDVUtWzmYLtxzkFGpuhGdAuRYSSC2LiCgbGcaqIkDrUpa2yaVZNimFJi3s08+OCUllT5aQQIh/mv02EEXGXi1IV7UCWqTNEdzjZrat6P2rNQbG0DYXvj3sbTJX8+7mS/c6LD5sWZ4UjKiVo4PMRknYHv3syjwX4VuvF49u/+fHYWtv72Y+buTO0iuGDxIiOk6kNElV895F40J6WpZ59nPpg7Qum8ndQHko5xqtdAXIB/l//3/v+/3//8AAA=="
    }
    payload = {
        "track_id": track_id,
        "media_type": "track",
        "queue_type": "daily_mix",
        "scene_name": "track_reco"
    }
    response = requests.post(url, params=COMMON_PARAMS, headers=headers, json=payload)
    if response.status_code == 200:
        with open("debug_track.json", "w", encoding="utf-8") as f:
            json.dump(response.json(), f, ensure_ascii=False, indent=2)
        print("Done. Saved to debug_track.json")

if __name__ == "__main__":
    dump_track("7164475471241152512") # Example track ID
