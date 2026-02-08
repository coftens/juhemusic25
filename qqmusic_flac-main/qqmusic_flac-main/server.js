/**
 * QQ音乐解析API服务器
 * 基于Express框架，提供QQ音乐播放链接获取服务
 */

const express = require('express');
const cors = require('cors');
const path = require('path');
const qqmusicRouter = require('./qqmusic-link');

const app = express();
const PORT = process.env.PORT || 3000;

// 中间件配置
app.use(cors()); // 允许跨域请求
app.use(express.json({ limit: '10mb' })); // 解析JSON请求体
app.use(express.urlencoded({ extended: true, limit: '10mb' })); // 解析URL编码请求体

// 静态文件服务（如果需要）
app.use('/static', express.static(path.join(__dirname, 'public')));

// 路由配置
app.use('/api/qqmusic', qqmusicRouter);

// 根路径欢迎信息
app.get('/', (req, res) => {
    res.json({
        name: 'QQ音乐解析API服务',
        version: '1.0.0',
        description: '提供QQ音乐播放链接获取、歌词获取、歌曲信息查询等功能',
        endpoints: {
            '获取播放链接': 'POST /api/qqmusic/get-link',
            '批量获取链接': 'POST /api/qqmusic/get-links',
            '获取歌词': 'POST /api/qqmusic/get-lyric',
            '获取歌曲信息': 'POST /api/qqmusic/get-info',
            '检查音质支持': 'POST /api/qqmusic/check-quality-support',
            '支持的音质': 'GET /api/qqmusic/qualities',
            'Cookie状态': 'GET /api/qqmusic/cookie-status'
        }
    });
});

// 健康检查接口
app.get('/health', (req, res) => {
    res.json({
        status: 'ok',
        timestamp: new Date().toISOString(),
        uptime: process.uptime()
    });
});

// 404处理
app.use('*', (req, res) => {
    res.status(404).json({
        code: 404,
        msg: '接口不存在',
        path: req.originalUrl
    });
});

// 全局错误处理
app.use((err, req, res, next) => {
    console.error('服务器错误:', err);
    res.status(500).json({
        code: 500,
        msg: '服务器内部错误',
        error: process.env.NODE_ENV === 'development' ? err.message : '请联系管理员'
    });
});

// 启动服务器
app.listen(PORT, () => {
    console.log(`\n🎵 QQ音乐解析API服务已启动`);
    console.log(`🌐 服务地址: http://localhost:${PORT}`);
    console.log(`📖 API文档: http://localhost:${PORT}`);
    console.log(`💡 健康检查: http://localhost:${PORT}/health`);
    console.log(`\n📋 主要接口:`);
    console.log(`   POST /api/qqmusic/get-link     - 获取播放链接`);
    console.log(`   POST /api/qqmusic/get-links    - 批量获取链接`);
    console.log(`   POST /api/qqmusic/get-lyric    - 获取歌词`);
    console.log(`   POST /api/qqmusic/get-info     - 获取歌曲信息`);
    console.log(`   POST /api/qqmusic/check-quality-support - 检查音质支持`);
    console.log(`   GET  /api/qqmusic/qualities    - 支持的音质`);
    console.log(`   GET  /api/qqmusic/cookie-status - Cookie状态`);
    console.log(`\n⚠️  请确保 qqcookie.txt 文件已正确配置`);
});

// 优雅关闭
process.on('SIGTERM', () => {
    console.log('\n🛑 收到SIGTERM信号，正在关闭服务器...');
    process.exit(0);
});

process.on('SIGINT', () => {
    console.log('\n🛑 收到SIGINT信号，正在关闭服务器...');
    process.exit(0);
});

module.exports = app;