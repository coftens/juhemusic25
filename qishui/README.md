# 汽水音乐 (Qishui Music) API & 解密服务文档

本项目提供汽水音乐 PC 客户端数据的抓取、解析以及音频流的解密服务。为了节省服务器带宽，推荐采用 **API 获取数据 + 前端直接解密** 的方案。

## 1. 核心文件说明

- `qishui_api.py`: 基于 Flask 的 API 服务，提供歌曲列表和详情接口。
- `qishui_updater.py`: 核心逻辑库，负责与汽水音乐服务端交互及数据清洗。
- `get_final_links.py`: 快速测试脚本，用于解析分享链接并输出解密播放地址。
- `qishui_decrypt.js`: 前端解密库（JavaScript），支持在浏览器或 App 中直接解密 CDN 音频流。
- `qishui_decrypt.py`: 后端解密库（Python），作为备选方案或代理播放时使用。

---

## 2. API 接口文档

默认服务端口：`8372`

### 2.1 获取更新列表 (Feed)
用于获取最新的推荐歌曲，包含基础元数据。

- **接口**: `GET /feed`
- **参数**:
  - `count` (int, 可选): 获取数量，默认 5。
- **返回示例**:
```json
{
  "count": 5,
  "data": [
    {
      "track_id": "7471099142774704178",
      "name": "歌曲名称",
      "artist": "歌手名",
      "cover": "https://...~c5_720x720.jpg",
      "artists_avatar": ["https://..."],
      "lyrics": "[00:00.000]歌词内容...",
      "share_link": "https://..."
    }
  ]
}
```

### 2.2 获取播放详情 (Track)
用于获取指定歌曲的所有音质链接及解密所需的 Key。**注：程序已自动过滤掉 30s 试听版音质，仅返回需要解密的高质量全曲音质。**

- **接口**: `GET /track?id={track_id}`
- **参数**:
  - `id` (string, 必填): 歌曲的 `track_id`。
- **返回示例**:
```json
{
  "track_id": "7471099142774704178",
  "name": "歌曲名称",
  "audio_urls": {
    "highest": {
      "is_encrypted": true,
      "play_url": "http://SERVER_IP:8372/play?url=...",
      "raw_url": "https://CDN_URL...",
      "spade_a": "Base36EncodedKey..."
    },
    "lossless": {
      "is_encrypted": true,
      "play_url": "http://SERVER_IP:8372/play?url=...",
      "raw_url": "https://CDN_URL...",
      "spade_a": "Base36EncodedKey..."
    }
  }
}
```

---

## 3. 前端解密集成指南 (推荐)

为了避免所有音频流量经过服务器（节省带宽），请在客户端使用 `qishui_decrypt.js`。

### 集成步骤：
1. **获取数据**：调用 `/track` 接口获取 `raw_url` 和 `spade_a`。
2. **下载音频**：客户端直接从 `raw_url` (汽水 CDN) 下载加密的二进制数据。
3. **调用解密**：
```javascript
// 引入 qishui_decrypt.js
const { decryptAudio } = require('./qishui_decrypt.js');

// 示例：解密过程
const encryptedArrayBuffer = await fetch(track.raw_url).then(r => r.arrayBuffer());
const spadeA = track.spade_a;

const decryptedBlob = await decryptAudio(encryptedArrayBuffer, spadeA);
const playUrl = URL.createObjectURL(decryptedBlob);

// 使用 HTML5 Player 播放
const audio = new Audio();
audio.src = playUrl;
audio.play();
```

---

## 4. 后端代理播放 (备选)

如果客户端无法运行 JavaScript 加密逻辑，可以使用 API 提供的 `play_url`。此链接会经过服务器中转并实时解密：
`http://127.0.0.1:8372/play?url={ENC_URL}&spade_a={KEY}`

---

## 5. 常见问题 (FAQ)

1. **封面图片无法显示？**
   - 汽水音乐 CDN 对后缀有严格要求。本项目已自动将链接优化为 `~c5_720x720.jpg` 格式，该格式兼容性最好且清晰度高。
2. **为什么没有普通音质（Standard）？**
   - 汽水音乐提供的无需解密的普通音质通常只有 30 秒试听。为了确保获取完整歌曲，本项目已强制过滤非加密音质，仅保留需要解密的完整版高质量音质。
3. **如何更新 Cookie？**
   - 请在 `qishui_updater.py` 顶部的 `COOKIE` 变量中更新您的 PC 客户端抓取到的 Cookie。
