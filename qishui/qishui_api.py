from flask import Flask, jsonify, request, Response
from qishui_updater import get_feed_list, get_track_info
from qishui_decrypt import decrypt_audio
import requests
import io

app = Flask(__name__)

@app.route('/feed', methods=['GET'])
def feed():
    """
    获取更新列表 (Feed)
    返回: 歌曲ID, 歌名, 歌手, 封面, 歌词, 分享链接
    """
    count = request.args.get('count', default=5, type=int)
    raw_data = get_feed_list(count)
    results = []
    for item in raw_data:
        track_id = item.get('id')
        if track_id:
            info = get_track_info(track_id)
            if info:
                results.append({
                    "track_id": info["track_id"],
                    "name": info["name"],
                    "artist": info["artist"],
                    "artists_avatar": info.get("artists_avatar", []),
                    "cover": info["cover"],
                    "lyrics": info["lyrics"],
                    "share_link": info["share_link"]
                })
    return jsonify({
        "count": len(results),
        "data": results
    })

@app.route('/track', methods=['GET'])
def track():
    """
    获取歌曲详细音质和播放链接
    参数: id (track_id)
    返回: 各音质对应的播放链接、原始链接及加密信息 (用于前端解密)
    """
    track_id = request.args.get('id')
    if not track_id:
        return jsonify({"error": "Missing track id"}), 400
        
    info = get_track_info(track_id)
    if not info:
        return jsonify({"error": "Track not found"}), 404
        
    processed_urls = {}
    for quality, data in info["audio_urls"].items():
        if isinstance(data, str):
            # 非加密链接
            processed_urls[quality] = {
                "play_url": data,
                "raw_url": data,
                "is_encrypted": False,
                "spade_a": None
            }
        elif isinstance(data, dict):
            is_encrypted = data.get('encrypted', False)
            spade_a = data.get('spade_a')
            raw_url = data.get('url')
            
            item_info = {
                "raw_url": raw_url,
                "is_encrypted": is_encrypted,
                "spade_a": spade_a
            }
            
            if is_encrypted and spade_a:
                # 提供后端代理链接作为备选
                proxy_url = f"http://{request.host}/play?url={requests.utils.quote(raw_url)}&spade_a={requests.utils.quote(spade_a)}"
                item_info["play_url"] = proxy_url
            else:
                item_info["play_url"] = raw_url
                
            processed_urls[quality] = item_info
            
    return jsonify({
        "track_id": track_id,
        "name": info["name"],
        "audio_urls": processed_urls
    })

@app.route('/play', methods=['GET'])
def play():
    """
    后端代理播放接口 (解密流)
    """
    url = request.args.get('url')
    spade_a = request.args.get('spade_a')
    
    if not url or not spade_a:
        return "Missing url or spade_a", 400
        
    try:
        resp = requests.get(url, timeout=30)
        if resp.status_code != 200:
            return f"Failed to download audio: {resp.status_code}", 500
            
        encrypted_data = resp.content
        decrypted_data = decrypt_audio(encrypted_data, spade_a)
        
        return Response(
            decrypted_data,
            mimetype="audio/mp4",
            headers={"Content-Disposition": "attachment; filename=music.m4a"}
        )
    except Exception as e:
        return f"Error during decryption: {str(e)}", 500

if __name__ == '__main__':
    # 运行 API 服务，监听 8372 端口
    print("汽水音乐 API 服务已启动")
    print("1. 获取更新列表: http://127.0.0.1:8372/feed?count=5")
    print("2. 获取播放链接: http://127.0.0.1:8372/track?id=歌曲ID")
    app.run(host='0.0.0.0', port=8372)
