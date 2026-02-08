import requests
import json
import re

# 配置信息 (从抓包日志中提取)
COOKIE = "passport_csrf_token=a1e6c33afbab5a4bab83e36dd798892b; passport_csrf_token_default=a1e6c33afbab5a4bab83e36dd798892b; odin_tt=a53a2858a662a9484487717e379732dded2bbb7c5e0c2ce2b303f1373ba19420e283ec0a8fd0298b4a5e8e472be5a923d2eb13d8df87ff774399efab1a8e81b071a56c2d635b1936fdd000060d37e61f; passport_assist_user=CkG68ERwtrwJvZ7l-vBxBl3pCyJcrQZbdVWLgzjHvdt4JmY5X3N9Ufwn4hPuOlqyHbCFXxMqnJVWtVtnZZalZaBM-hpKCjwAAAAAAAAAAAAAUAE4z0ewtLRVuMNm_GG5FlR3l_Bu3Dif7SwwQe5AS8CXnJK9yq9U7LkjyKBt4UKklZcQyJKIDhiJr9ZUIAEiAQNdK5Rp; n_mh=gxsLTxwi84PAttEGgA6UZY20btQQqnZthqdOJLm2rnc; sid_guard=fee57bc91c1f06524c121504f2109dec|1769619649|5184000|Sun,+29-Mar-2026+17:00:49+GMT; uid_tt=177b9da4b66127906d0a4573a31b9a71; uid_tt_ss=177b9da4b66127906d0a4573a31b9a71; sid_tt=fee57bc91c1f06524c121504f2109dec; sessionid=fee57bc91c1f06524c121504f2109dec; sessionid_ss=fee57bc91c1f06524c121504f2109dec; session_tlb_tag=sttt|1|_uV7yRwfBlJMEhUE8hCd7P_________inxH34a7ShVOR04Ezpdwsqw7gJ4tog7UUC5sVjCL4l44; is_staff_user=false; sid_ucp_v1=1.0.0-KGFmOGQ4N2RmN2QyNDZlMjc4NWJmMjNiNzBlMGQ1ZDJkYWMxM2JjMmQKKgi3upCOgY35BRDBgenLBhioyBcgDCiwsaGg-qxqMJG5hJQGOAdA9AdIBBoCbHEiIGZlZTU3YmM5MWMxZjA2NTI0YzEyMTUwNGYyMTA5ZGVj; ssid_ucp_v1=1.0.0-KGFmOGQ4N2RmN2QyNDZlMjc4NWJmMjNiNzBlMGQ1ZDJkYWMxM2JjMmQKKgi3upCOgY35BRDBgenLBhioyBcgDCiwsaGg-qxqMJG5hJQGOAdA9AdIBBoCbHEiIGZlZTU3YmM5MWMxZjA2NTI0YzEyMTUwNGYyMTA5ZGVj; ttwid=1|2nDZQwpBDpSg5LSst1G-qX4naqimKJ251TqY-S4xiNQ|1769870189|6cb474ffa16129d944ec180a2f509731741e96d0e13c1ae5e2bbae2fa695e150"
USER_AGENT = "LunaPC/3.0.0(290101097)"

# 通用查询参数
COMMON_PARAMS = {
    "aid": "386088",
    "app_name": "luna_pc",
    "device_id": "467737575446704",
    "version_name": "3.0.0",
    "device_platform": "windows",
    "os_version": "Windows 11 Home China"
}

def get_share_link(track_id):
    url = "https://api.qishui.com/luna/pc/share_info"
    headers = {
        "cookie": COOKIE,
        "user-agent": USER_AGENT,
        "X-Helios": "fSMAAIlsJjZZqFpFCnT+8c/UMcV/BlkakXE9qENnObchGeNd",
        "X-Medusa": "hxh+aXPwIZNTz6JSUjK2GdPXH3HjUQMBfTeHXwDAIUo5GU/IjMF6LfbLt8SKE63PaV8IlIe1KTRcO96BcKRtr9xAc1Y6FQ6LovlCQm99o2dD+mASAiltBvbVF4CEp9bnSbX1B7yyIa7PqUFfTnprktNTCpC4OWCtqeK/MgE1dITjk6OU43Hm9Olh2klxFf22blVjYYNu2W2d4DYcwozjXgJs0ug1rTBrdiCLpNQp/G8UlX3NAlHnvbfPOwGTfJJ1RamrWO70fz0th0wT9KWWHBGL/pHKJH+ukrVzTYktINcWU9T5t99fU+J57X8X6gnTeN090ilw0ug7fDpVJESOA2Ig7/shKCRCZSDo8s8y067iCZqWOODDFRpBdOUHY+jqEvm0N9rXP3gvXaMAiTu6lUB6a1AlXaHvhFEiHN6ILIzmYY59NR/DRWs1Lpvs8XMzEVMoHAZOQoTrv6+QEMysZW2Bxu1dpK4uVjBB11OzavSRDnSfEZFazNegsnge/xbs1Q/wR6Emnzh8gbAmeccnqBrlkGc96ku2v37+SppL+h4te+z1RJ8EH6Hqqj54djysbIRNLj0RCvFC0YwWv+HfRdhMCW6pfRW0ISm14ZjK4nAqx0SqeJ4RWTdEG29E/J1fcZ+JzXdhup7CN1hACfhFsmwgZlVwS5vFcVeOY77gxrBNr+95IImLt+cxqDfO2SXV6TfZznDO03M/3azV2ImNWTqwfy8vj78/B517Xetph8wZ8EtDhoiyo3JSGn0xiR/Bql8fMUwS5dQ4sCk7j88tDTwrv5rZ+7s6uUrM/hgIeUOghSfXH8kHHLmd3PKJ59nH947Uum9nZQnkI5hqtdAHKB7l+///v+////8AAA=="
    }
    params = COMMON_PARAMS.copy()
    params.update({
        "item_type": "track",
        "item_id": track_id
    })
    
    response = requests.get(url, params=params, headers=headers)
    if response.status_code == 200:
        data = response.json()
        return data.get("share_info", {}).get("short_share_link")
    return None

def parse_krc_to_lrc(krc_content):
    """
    将汽水的 KRC 格式转换为标准的 LRC 格式
    KRC 格式示例: [420,1350]<0,240,0>你<240,200,0>是...
    """
    if not krc_content:
        return ""
    
    lrc_lines = []
    # 匹配行 [开始时间,持续时间]
    lines = krc_content.split('\n')
    for line in lines:
        line = line.strip()
        if not line:
            continue
            
        # 提取时间标签 [start_ms, duration]
        match = re.match(r'\[(\d+),\d+\](.*)', line)
        if match:
            start_ms = int(match.group(1))
            content = match.group(2)
            
            # 移除字词级的时间标签 <offset, duration, ?>
            clean_content = re.sub(r'<\d+,\d+,\d+>', '', content)
            
            # 转换为 [mm:ss.ms] 格式
            minutes = start_ms // 60000
            seconds = (start_ms % 60000) // 1000
            ms = (start_ms % 1000) // 10 # 保持两位毫秒或根据需要
            
            time_tag = f"[{minutes:02d}:{seconds:02d}.{ms:02d}]"
            lrc_lines.append(f"{time_tag}{clean_content}")
        else:
            # 如果没有匹配到时间标签，直接添加（可能是普通文本）
            lrc_lines.append(line)
            
    return "\n".join(lrc_lines)

def get_playable_url(track_id):
    """
    通过分享页面获取非加密的可播放直链
    """
    url = f"https://music.douyin.com/qishui/share/track?track_id={track_id}"
    headers = {
        "user-agent": "Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1"
    }
    try:
        response = requests.get(url, headers=headers, timeout=10)
        if response.status_code == 200:
            content = response.text
            # 匹配 _ROUTER_DATA = {...};
            match = re.search(r'_ROUTER_DATA\s*=\s*({.*?});', content, re.S)
            if match:
                data = json.loads(match.group(1))
                audio_option = data.get('loaderData', {}).get('track_page', {}).get('audioWithLyricsOption', {})
                return audio_option.get('url')
    except Exception as e:
        print(f"Error fetching playable URL for {track_id}: {e}")
    return None

def get_track_info(track_id):
    print(f"正在获取歌曲详情: {track_id}")
    url = "https://api.qishui.com/luna/pc/track_v2"
    headers = {
        "cookie": COOKIE,
        "user-agent": USER_AGENT,
        "content-type": "application/json; charset=utf-8",
        # 这里的签名也需要对应 track_v2 的请求
        "X-Helios": "SicAACJWDNiSHEX4DSBVXo3+TNXAHXt9Af6CkPaMTmSX1Jcg",
        "X-Medusa": "GBR+aez8IZPMw6JSzT62GUzbH3GODwMBg7ZESAAAAQk/GduVkAZWRUCTX0SGSLVDDQ/gYOFKM/adGsI29F3FyR/OAoj+AK7fOY1Pe1po0w3w850g3Y0xvZOEl35RaWIynTM+dvmKmsQLoBG2LPT9eoaLqF8pi6MjvRdIJK8PMnnwDYrreh4OQ85zqzZdCFytOf6cXPH4NImgdUgBceuFfUtCN8ZdI3bRTDD28J8OxDK8vsWjdzimSPNTIe6C2EKel/U+PcqXfkbs/ZWCvHyxmqgrLfu5tHAtnXuEbQf6J53G8I6wdY8JQ5wm8+7o37XUiWC8FCB6y+09/aB9q4LTwNEMOlv50fAQg/bT9RgB6+7jF+7RXZyIuNkXAuJb2uZeBSzfJVvw6VITls5AFSOdNu376GqKGm4T6M8V9HzT2L8cW8smYgNG6HJPjd3iVVcv8fjeJeAGolEPMBbBvbAjJCSQAOY6jo/RGbRvOUsDyZgJ6fEp8ncjXIcK6Nw1GSPOv7AXWILqyt5sBpFDvPlpJTqih5TWbmWSEBc52+OPX2DJKknmz4qBPrRdJ7QvtxA5nrLDBjc3doDJa2iv1FE/7nUQoGJ5njCFw2BYfT9LE3kxDVUtWzmYLtxzkFGpuhGdAuRYSSC2LiCgbGcaqIkDrUpa2yaVZNimFJi3s08+OCUllT5aQQIh/mv02EEXGXi1IV7UCWqTNEdzjZrat6P2rNQbG0DYXvj3sbTJX8+7mS/c6LD5sWZ4UjKiVo4PMRknYHv3syjwX4VuvF49u/+fHYWtv72Y+buTO0iuGDxIiOk6kNElV895F40J6WpZ59nPpg7Qum8ndQHko5xqtdAXIB/l//3/v+/3//8AAA=="
    }
    payload = {
        "track_id": track_id,
        "media_type": "track",
        "queue_type": "daily_mix",
        "scene_name": "track_reco"
    }
    
    try:
        response = requests.post(url, params=COMMON_PARAMS, headers=headers, json=payload, timeout=10)
        if response.status_code == 200:
            data = response.json()
            track = data.get("track", {})
            track_player = data.get("track_player", {})
            
            # 提取所有音质的直链
            audio_urls = {}
            
            # 1. PC API 获取各种音质 (仅保留需要解密的高质量音质，去掉 30s 试听版)
            video_model_str = track_player.get("video_model", "{}")
            if video_model_str:
                try:
                    video_model = json.loads(video_model_str)
                    video_list = video_model.get("video_list", [])
                    if isinstance(video_list, dict):
                        for k, v in video_list.items():
                            quality = v.get("video_meta", {}).get("quality") or k
                            if v.get("main_url"):
                                # 存储 URL 和加密密钥 spade_a
                                encrypt_info = v.get("encrypt_info", {})
                                is_encrypted = encrypt_info.get("encrypt", False)
                                # 仅保留加密音质 (非加密通常是试听版)
                                if is_encrypted:
                                    spade_a = encrypt_info.get("spade_a")
                                    audio_urls[quality] = {
                                        "url": v.get("main_url"),
                                        "spade_a": spade_a,
                                        "encrypted": True
                                    }
                    else:
                        for v in video_list:
                            quality = v.get("video_meta", {}).get("quality")
                            if quality and v.get("main_url"):
                                encrypt_info = v.get("encrypt_info", {})
                                is_encrypted = encrypt_info.get("encrypt", False)
                                if is_encrypted:
                                    spade_a = encrypt_info.get("spade_a")
                                    audio_urls[quality] = {
                                        "url": v.get("main_url"),
                                        "spade_a": spade_a,
                                        "encrypted": True
                                    }
                except Exception:
                    pass

            # 提取歌手信息和头像
            artists = track.get('artists', [{}])
            artist_name = artists[0].get('name') if artists else ""
            artist_avatar = artists[0].get('medium_avatar_url', {}).get('urls', []) if artists else []

            # 提取封面图片
            album = track.get("album", {})
            url_cover = album.get("url_cover", {})
            cover_url = ""
            cover_list = []
            
            uri = url_cover.get("uri")
            urls = url_cover.get("urls", [])
            template_prefix = url_cover.get("template_prefix", "")
            
            if url_cover.get("url_list"):
                cover_list = url_cover["url_list"]
            
            if uri and urls:
                for base in urls:
                    full_url = f"{base}{uri}"
                    if full_url not in cover_list:
                        cover_list.append(full_url)
                    
                    # 构造高清链接
                    # 1. 尝试标准 c5 缩放 (720x720 是高清规格)
                    hd_url_c5_720 = f"{full_url}~c5_720x720.jpg"
                    if hd_url_c5_720 not in cover_list:
                        cover_list.insert(0, hd_url_c5_720)
                    
                    # 2. 尝试 375x375 (分享页规格)
                    hd_url_c5_375 = f"{full_url}~c5_375x375.jpg"
                    if hd_url_c5_375 not in cover_list:
                        cover_list.append(hd_url_c5_375)

            if cover_list:
                cover_url = cover_list[0]

            # 提取歌词并转换格式
            lyric_data = data.get("lyric", {})
            krc_content = lyric_data.get("content", "")
            lrc_content = parse_krc_to_lrc(krc_content)

            # 获取分享链接
            share_link = get_share_link(track_id)

            info = {
                "track_id": track_id,
                "name": track.get('name'),
                "artist": artist_name,
                "artists_avatar": artist_avatar, 
                "cover": cover_url,
                "cover_list": cover_list,
                "audio_urls": audio_urls,
                "lyrics": lrc_content,
                "share_link": share_link
            }
            return info
    except Exception as e:
        print(f"Error getting track info for {track_id}: {e}")
    return None



def get_feed_list(count=5):
    url = "https://api.qishui.com/luna/pc/feed/song-tab"
    headers = {
        "cookie": COOKIE,
        "user-agent": USER_AGENT,
        "content-type": "application/json; charset=utf-8",
        "X-Helios": "PyYAACAUZyaoObgp4Coq6rq17IRdKmVbbYDmT6rmPKkflMwN",
        "X-Medusa": "GBR+aez8IZPMw6JSzT62GUzbH3FPaQMBjrccFQAAIQk/GNQ1FhU21xIQIbt8ZwubEsosQdq26xulRs/YpO550arUxxsfhFoq+pqNF6jj5UHz/mn4dr40B5Jg21Rssr9caiIHcTUAdVa693G2de+xRLujdUPQQN0yuNqxdJ5JU+/LQ0j58rtiFQylVtWu1ylP9FlYnpuf0jy9dJvbe9GQTHu3jGm9uysvHzWAQryAB5lrWoktYhcdV1BzlDd93x2buuPnDXXX374XOCpkUVV99XI/mBzlSbHl1X+Qu0Q6Yd6K4SyFNzprJRR4oQD9ghn5+LVnwlGS6RBBbXFPxMj4Q4HQLGhMmh1M2i+NDmwAkTXICXC3iAox1YlbfzhjgLnCNBR9ZUXnq/Ry+KFmVobwA/lOuwr0ZPCCDHd538scVhE3oGHF/te8fZaTFd7YudCz10vW6vc92HH/7daj1K2aOUcMVqP6t/eQwlI6Hs5rsBvVv7b7Zeojdp424HdJWg4fYWyhmrqtEZbjgzAgQEvfx37xvY26J6xTfVuUGuDAuyms69i6+fDOlAR60W6HTv/1zwQAmiorVUidQcSyEd9ZoOFG8A1mmLwndW7u2AdnnY+H2y2znlPDJEt20kmiKKijqOQH8EHa4i/GNNjXvC0joa7nElNtemQ6dHIlDCkH9gbHXCbfinxDdct/1pIfhXk6GPcPQHyLzqm870nnN/QYdq3rex+P378HDyfJXc/7iS/d6LT5sWZoWjIiVowvMQkvYPt87Ki8jvA9vFo9m7+PFYStv724ubuTOkiqGDxIiOk7ENUnd495H4yJvQ1Fx8lHZ17QuE8ndQnlI5hqtdAHIB9l+//fv/////8AAA=="
    }
    
    results = []
    # 如果请求数量较多，可能需要多次循环获取
    while len(results) < count:
        print(f"正在请求 Feed... 已获取 {len(results)}/{count}")
        # 动态调整请求数量，确保能拿到足够的 track
        payload = {
            "is_first_request": False,
            "played_media": [],
            "is_did_first_request": False,
            "feed_counts": {"mix_session_count": 20}, # 每次请求多拿点
            "did_first_use_time": 1769619611
        }
        
        try:
            response = requests.post(url, params=COMMON_PARAMS, headers=headers, json=payload, timeout=10)
            if response.status_code == 200:
                data = response.json()
                items = data.get("items", [])
                
                new_found = 0
                for item in items:
                    track = item.get("entity", {}).get("track_wrapper", {}).get("track", {})
                    if not track: continue
                    
                    # DEBUG: Print the first track's full JSON
                    with open("debug_first_track.json", "w", encoding="utf-8") as f:
                        json.dump(item, f, ensure_ascii=False, indent=2)
                    print(f"DEBUG: Saved first track to debug_first_track.json")
                    
                    track_id = track.get("id")
                    # 去重处理
                    if any(r['id'] == track_id for r in results):
                        continue
                        
                    results.append({
                        "name": f"{track.get('name')} - {track.get('artists', [{}])[0].get('name')}",
                        "id": track_id
                    })
                    new_found += 1
                    
                    if len(results) >= count:
                        break
                
                # 如果这一轮没找到新歌，说明可能到头了或有问题，直接跳出避免死循环
                if new_found == 0:
                    break
            else:
                break
        except Exception:
            break
            
    return results


if __name__ == "__main__":
    # 获取更新列表 (Feed) 5 条
    print("正在获取更新列表 (Feed) 前 5 条数据...")
    feed_list = get_feed_list(5)
    if feed_list:
        for idx, item in enumerate(feed_list, 1):
            print(f"\n[{idx}] 歌曲详细信息:")
            info = get_track_info(item['id'])
            if info:
                print(f"歌曲名称: {info['name']}")
                print(f"封面链接 (高清优先): {info['cover']}")
                if info.get('cover_list'):
                    print(f"所有封面链接数量: {len(info['cover_list'])}")
                    for c_url in info['cover_list'][:2]: # 只打印前两个
                        print(f"  - {c_url}")
                print(f"音频直链数量: {len(info['audio_urls'])}")
                for quality, url_data in info['audio_urls'].items():
                    if isinstance(url_data, dict):
                        url = url_data.get('url', '')
                        print(f"  - {quality} (Encrypted): {url[:50]}...")
                    else:
                        print(f"  - {quality}: {url_data[:50]}...")
            else:
                print(f"获取 {item['id']} 详情失败")
    else:
        print("获取更新列表失败。")
