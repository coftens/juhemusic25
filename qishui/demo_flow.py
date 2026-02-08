import requests
import os
from qishui_decrypt import decrypt_audio

def run_demo():
    # 1. 调用 API 获取 Feed 列表
    print("--- Step 1: 获取 Feed 列表 ---")
    api_base = "http://127.0.0.1:8372"
    try:
        feed_resp = requests.get(f"{api_base}/feed?count=1")
        feed_data = feed_resp.json()
        if not feed_data.get("data"):
            print("未能获取到歌曲列表，请确保 API 服务已启动。")
            return
        
        song = feed_data["data"][0]
        track_id = song["track_id"]
        print(f"找到歌曲: {song['name']} - {song['artist']} (ID: {track_id})")
        print(f"封面链接: {song['cover']}")
    except Exception as e:
        print(f"连接 API 失败: {e}")
        return

    # 2. 调用 API 获取播放链接和加密信息
    print("\n--- Step 2: 获取播放链接和加密信息 ---")
    track_resp = requests.get(f"{api_base}/track?id={track_id}")
    track_info = track_resp.json()
    
    # 找一个加密的链接来演示
    target_quality = None
    target_data = None
    
    for quality, data in track_info["audio_urls"].items():
        if data.get("is_encrypted"):
            target_quality = quality
            target_data = data
            break
    
    if not target_data:
        print("未找到加密音质链接。")
        return
        
    print(f"选择音质: {target_quality}")
    print(f"原始加密链接: {target_data['raw_url'][:100]}...")
    print(f"SpadeA 密钥: {target_data['spade_a']}")

    # 3. 下载并解密 (模拟后端或前端解密逻辑)
    print("\n--- Step 3: 下载并解密 ---")
    print("正在下载加密音频流...")
    audio_resp = requests.get(target_data['raw_url'])
    encrypted_bytes = audio_resp.content
    print(f"下载完成，大小: {len(encrypted_bytes)} 字节")
    
    print("正在进行 cenc-aes-ctr 解密...")
    try:
        decrypted_bytes = decrypt_audio(encrypted_bytes, target_data['spade_a'])
        
        # 4. 保存文件
        output_file = "demo_decrypted.m4a"
        with open(output_file, "wb") as f:
            f.write(decrypted_bytes)
        
        print(f"解密成功！已保存为: {os.path.abspath(output_file)}")
        print("你可以尝试使用播放器播放该文件。")
    except Exception as e:
        print(f"解密失败: {e}")

if __name__ == "__main__":
    run_demo()
