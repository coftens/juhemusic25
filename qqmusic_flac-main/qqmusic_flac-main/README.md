# QQ音乐解析API服务

一个基于Node.js和Express的QQ音乐播放链接获取服务，支持多种音质、歌词获取、歌曲信息查询等功能。

## 功能特点

- ✅ 支持多种音质播放链接获取（128kbps到母带）
- ✅ 批量获取多种音质链接
- ✅ 歌词获取（支持翻译歌词）
- ✅ 歌曲信息查询
- ✅ 自动读取Cookie配置
- ✅ 完整的错误处理和状态反馈

## 快速开始

### 1. 安装依赖

```bash
npm install
```

### 2. 配置Cookie

在项目根目录创建 `qqcookie.txt` 文件，并填入有效的QQ音乐Cookie：

```
pgv_pvid=277615248; fqm_pvqid=aa07e5c1-520b-419d-9...
```

### 3. 启动服务

```bash
npm start
```

服务将在 `http://localhost:3000` 启动。

## API接口文档

### 基础信息

- **基础URL**: `http://localhost:3000`
- **Content-Type**: `application/json`
- **所有POST接口都需要JSON格式的请求体**

### 1. 服务状态检查

**GET** `/health`

```json
{
  "status": "ok",
  "timestamp": "2025-09-23T16:25:48.268Z",
  "uptime": 96.8302123
}
```

### 2. 获取单个播放链接

**POST** `/api/qqmusic/get-link`

**请求体：**
```json
{
  "songmid": "004YZbkL2MNHoY",
  "quality": "HQ高品质"
}
```

**响应：**
```json
{
  "code": 200,
  "msg": "获取成功",
  "data": {
    "songmid": "004YZbkL2MNHoY",
    "quality": "320",
    "mapped_quality": "HQ高品质",
    "url": "https://...",
    "bitrate": "320kbps"
  }
}
```

### 3. 批量获取多种音质链接

**POST** `/api/qqmusic/get-links`

**请求体：**
```json
{
  "songmid": "004YZbkL2MNHoY",
  "qualities": ["标准", "HQ高品质", "SQ无损品质"]
}
```

### 4. 获取歌词

**POST** `/api/qqmusic/get-lyric`

**请求体：**
```json
{
  "songmid": "004YZbkL2MNHoY"
}
```

或

```json
{
  "songid": "123456789"
}
```

### 5. 获取歌曲信息

**POST** `/api/qqmusic/get-info`

**请求体：**
```json
{
  "songmid": "004YZbkL2MNHoY"
}
```

**响应：**
```json
{
  "code": 200,
  "msg": "获取成功",
  "data": {
    "name": "歌曲名称",
    "album": "专辑名称",
    "singer": "歌手名称",
    "pic": "封面图片URL",
    "mid": "004YZbkL2MNHoY",
    "id": 123456789,
    "interval": 240
  }
}
```

### 6. 获取支持的音质列表

**GET** `/api/qqmusic/qualities`

**响应：**
```json
{
  "code": 200,
  "msg": "获取成功",
  "data": {
    "quality_map": {
      "标准": "128",
      "HQ高品质": "320",
      "SQ无损品质": "flac",
      "臻品母带3.0": "master"
    },
    "supported_qualities": ["标准", "HQ高品质", "SQ无损品质", "臻品母带3.0"]
  }
}
```

### 7. 批量检查音质支持

**POST** `/api/qqmusic/check-quality-support`

这个接口支持两种模式：单首歌曲检查所有音质，或批量歌曲检查指定音质。

#### 7.1 单首歌曲模式（检查所有音质）

**请求体：**
```json
{
  "songmids": "004YZbkL2MNHoY",
  "mode": "single"
}
```

**响应：**
```json
{
  "code": 200,
  "msg": "音质支持检查完成",
  "data": {
    "songmid": "004YZbkL2MNHoY",
    "song_info": {
      "name": "歌曲名称",
      "singer": "歌手名称",
      "album": "专辑名称"
    },
    "total_qualities": 10,
    "supported_count": 6,
    "unsupported_count": 4,
    "supported_qualities": ["标准", "HQ高品质", "SQ无损品质"],
    "unsupported_qualities": ["臻品母带3.0", "臻品全景声2.0"],
    "quality_details": {
      "标准": {
        "code": "128",
        "bitrate": "128kbps",
        "supported": true,
        "url_available": true,
        "url": "https://ws.stream.qqmusic.qq.com/M500..."
      },
      "臻品母带3.0": {
        "code": "master",
        "bitrate": null,
        "supported": false,
        "url_available": false,
        "reason": "可能需要VIP权限或该音质不存在"
      }
    },
    "best_quality": "SQ无损品质"
  }
}
```

#### 7.2 批量歌曲模式（检查指定音质）

**请求体：**
```json
{
  "songmids": ["004YZbkL2MNHoY", "003aAYrN3GE5Xu", "002J4UUk29y8BY"],
  "qualities": ["标准", "HQ高品质", "SQ无损品质"],
  "mode": "batch"
}
```

**响应：**
```json
{
  "code": 200,
  "msg": "批量音质支持检查完成",
  "data": {
    "mode": "batch",
    "total_songs": 3,
    "checked_qualities": ["标准", "HQ高品质", "SQ无损品质"],
    "total_checks": 9,
    "total_supported": 7,
    "support_rate": "77.78%",
    "results": {
      "004YZbkL2MNHoY": {
        "song_info": {
          "name": "歌曲名称1",
          "singer": "歌手名称1",
          "album": "专辑名称1"
        },
        "qualities": {
          "标准": {
            "code": "128",
            "bitrate": "128kbps",
            "supported": true,
            "url_available": true,
            "url": "https://ws.stream.qqmusic.qq.com/M500..."
          },
          "HQ高品质": {
            "code": "320",
            "bitrate": "320kbps",
            "supported": true,
            "url_available": true,
            "url": "https://ws.stream.qqmusic.qq.com/M800..."
          },
          "SQ无损品质": {
            "code": "flac",
            "bitrate": null,
            "supported": false,
            "url_available": false,
            "reason": "可能需要VIP权限或该音质不存在"
          }
        },
        "supported_count": 2,
        "total_count": 3
      }
    }
  }
}
```

### 8. 检查Cookie状态

**GET** `/api/qqmusic/cookie-status`

**响应：**
```json
{
  "code": 200,
  "msg": "检查完成",
  "data": {
    "has_cookie": true,
    "cookie_length": 978,
    "cookie_preview": "pgv_pvid=277615248; fqm_pvqid=aa07e5c1-520b-419d-9..."
  }
}
```

## 支持的音质

| 中文名称 | 代码 | 格式 | 说明 |
|---------|------|------|------|
| 标准 | 128 | MP3 | 128kbps |
| HQ高品质 | 320 | MP3 | 320kbps |
| SQ无损品质 | flac | FLAC | 无损音质 |
| 臻品母带3.0 | master | FLAC | 母带音质 |
| 臻品全景声2.0 | atmos_2 | FLAC | 全景声 |
| 臻品音质2.0 | atmos_51 | FLAC | 5.1声道 |
| OGG高品质 | ogg_320 | OGG | 320kbps |
| OGG标准 | ogg_192 | OGG | 192kbps |
| AAC高品质 | aac_192 | M4A | 192kbps |
| AAC标准 | aac_96 | M4A | 96kbps |


## 注意事项

1. **Cookie配置**：需要有效的QQ音乐Cookie才能正常使用
2. **VIP权限**：某些高音质可能需要QQ音乐VIP权限
3. **请求频率**：建议控制请求频率，避免被限制
4. **歌曲ID**：songmid可以从QQ音乐分享链接中获取

## 错误处理

所有接口都遵循统一的错误响应格式：

```json
{
  "code": 400,
  "msg": "错误描述",
  "data": null
}
```

常见错误码：
- `400`: 请求参数错误
- `500`: 服务器内部错误或Cookie无效

## 项目结构

```
qqnode/
├── qqapi.js           # QQ音乐API核心类
├── qqmusic-link.js    # Express路由处理
├── server.js          # 主服务器文件
├── test.js            # 测试脚本
├── package.json       # 项目配置
├── qqcookie.txt       # Cookie配置文件
└── README.md          # 说明文档
```

## 许可证

MIT License

## 贡献

欢迎提交Issue和Pull Request！

---

**免责声明**：本项目仅供学习和研究使用，请遵守相关法律法规和服务条款。