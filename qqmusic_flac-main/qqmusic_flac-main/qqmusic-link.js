/**
 * QQ音乐链接获取服务
 * 读取cookie文件，映射音质，返回歌曲播放链接
 */

const express = require('express');
const fs = require('fs');
const path = require('path');
const QQMusic = require('./qqapi');
const router = express.Router();

// 音质映射配置
const qualityMap = {
    '标准': '128',
    'HQ高品质': '320', 
    'SQ无损品质': 'flac',
    '臻品母带3.0': 'master',
    '臻品全景声2.0': 'atmos_2',
    '臻品音质2.0': 'atmos_51',
    'OGG高品质': 'ogg_320',
    'OGG标准': 'ogg_192',
    'AAC高品质': 'aac_192',
    'AAC标准': 'aac_96',
};

// 反向映射：从音质代码到中文名称
const reverseQualityMap = {
    '128': '标准',
    '320': 'HQ高品质',
    'flac': 'SQ无损品质',
    'master': '臻品母带3.0',
    'atmos_2': '臻品全景声2.0',
    'atmos_51': '臻品音质2.0',
    'ogg_320': 'OGG高品质',
    'ogg_192': 'OGG标准',
    'aac_192': 'AAC高品质',
    'aac_96': 'AAC标准'
};

// 音质优先级排序（从低到高）
const qualityPriority = [
    '标准',           // 128kbps MP3
    'AAC标准',        // 96kbps AAC
    'OGG标准',        // 192kbps OGG
    'AAC高品质',      // 192kbps AAC
    'HQ高品质',       // 320kbps MP3
    'OGG高品质',      // 320kbps OGG
    'SQ无损品质',     // FLAC
    '臻品音质2.0',    // Atmos 5.1
    '臻品全景声2.0',  // Atmos 2.0
    '臻品母带3.0'     // Master
];

// 获取最佳音质
function getBestQuality(supportedQualities) {
    if (!supportedQualities || supportedQualities.length === 0) {
        return null;
    }
    
    // 按优先级排序，返回最高优先级的音质
    let bestQuality = null;
    let highestPriority = -1;
    
    for (const quality of supportedQualities) {
        const priority = qualityPriority.indexOf(quality);
        if (priority > highestPriority) {
            highestPriority = priority;
            bestQuality = quality;
        }
    }
    
    return bestQuality || supportedQualities[0];
}

// 读取QQ音乐Cookie
function loadQQCookie() {
    try {
        const cookiePath = path.join(__dirname, 'qqcookie.txt');
        if (fs.existsSync(cookiePath)) {
            const cookieContent = fs.readFileSync(cookiePath, 'utf-8').trim();
            console.log('QQ音乐Cookie已加载:', cookieContent ? '有效' : '空');
            return cookieContent;
        } else {
            console.log('qqcookie.txt文件不存在');
            return '';
        }
    } catch (error) {
        console.error('读取QQ音乐Cookie失败:', error);
        return '';
    }
}

// 获取歌曲播放链接
router.post('/get-link', async (req, res) => {
    try {
        const { songmid, quality = 'HQ高品质' } = req.body;
        
        if (!songmid) {
            return res.status(400).json({
                code: 400,
                msg: '缺少songmid参数',
                data: null
            });
        }
        
        // 读取Cookie
        const cookieStr = loadQQCookie();
        if (!cookieStr) {
            return res.status(500).json({
                code: 500,
                msg: 'Cookie文件为空或不存在，请配置qqcookie.txt',
                data: null
            });
        }
        
        // 映射音质
        const mappedQuality = qualityMap[quality] || quality;
        
        // 创建QQ音乐实例
        const qqmusic = new QQMusic();
        qqmusic.setCookies(cookieStr);
        
        // 获取播放链接
        const result = await qqmusic.getMusicUrl(songmid, mappedQuality);
        
        if (!result) {
            return res.json({
                code: 500,
                msg: `无法获取${quality}(${mappedQuality})播放链接，可能需要VIP权限或歌曲不存在`,
                data: null
            });
        }
        
        res.json({
            code: 200,
            msg: '获取成功',
            data: {
                songmid: songmid,
                quality: mappedQuality,
                mapped_quality: reverseQualityMap[mappedQuality] || quality,
                url: result.url,
                bitrate: result.bitrate || mappedQuality.toUpperCase()
            }
        });
        
    } catch (error) {
        console.error('获取播放链接错误:', error);
        res.status(500).json({
            code: 500,
            msg: '服务器内部错误: ' + error.message,
            data: null
        });
    }
});

// 批量获取多种音质链接
router.post('/get-links', async (req, res) => {
    try {
        const { songmid, qualities = ['标准', 'HQ高品质', 'SQ无损品质'] } = req.body;
        
        if (!songmid) {
            return res.status(400).json({
                code: 400,
                msg: '缺少songmid参数',
                data: null
            });
        }
        
        // 读取Cookie
        const cookieStr = loadQQCookie();
        if (!cookieStr) {
            return res.status(500).json({
                code: 500,
                msg: 'Cookie文件为空或不存在，请配置qqcookie.txt',
                data: null
            });
        }
        
        // 创建QQ音乐实例
        const qqmusic = new QQMusic();
        qqmusic.setCookies(cookieStr);
        
        const results = {};
        
        // 批量获取不同音质的链接
        for (const quality of qualities) {
            const mappedQuality = qualityMap[quality] || quality;
            try {
                const result = await qqmusic.getMusicUrl(songmid, mappedQuality);
                if (result) {
                    results[quality] = {
                        mapped_quality: mappedQuality,
                        url: result.url,
                        bitrate: result.bitrate,
                        status: 'success'
                    };
                } else {
                    results[quality] = {
                        mapped_quality: mappedQuality,
                        url: null,
                        bitrate: null,
                        status: 'failed',
                        reason: '可能需要VIP权限或歌曲不存在'
                    };
                }
            } catch (error) {
                results[quality] = {
                    mapped_quality: mappedQuality,
                    url: null,
                    bitrate: null,
                    status: 'error',
                    reason: error.message
                };
            }
        }
        
        res.json({
            code: 200,
            msg: '批量获取完成',
            data: {
                songmid: songmid,
                results: results
            }
        });
        
    } catch (error) {
        console.error('批量获取播放链接错误:', error);
        res.status(500).json({
            code: 500,
            msg: '服务器内部错误: ' + error.message,
            data: null
        });
    }
});

// 获取支持的音质列表
router.get('/qualities', (req, res) => {
    res.json({
        code: 200,
        msg: '获取成功',
        data: {
            quality_map: qualityMap,
            supported_qualities: Object.keys(qualityMap)
        }
    });
});

// 检查Cookie状态
router.get('/cookie-status', (req, res) => {
    const cookieStr = loadQQCookie();
    res.json({
        code: 200,
        msg: '检查完成',
        data: {
            has_cookie: !!cookieStr,
            cookie_length: cookieStr.length,
            cookie_preview: cookieStr ? cookieStr.substring(0, 50) + '...' : '无Cookie'
        }
    });
});

// 获取歌词接口
router.post('/get-lyric', async (req, res) => {
    try {
        const { songmid, songid } = req.body;
        
        if (!songmid && !songid) {
            return res.status(400).json({
                code: 400,
                msg: '缺少songmid或songid参数',
                data: null
            });
        }
        
        // 读取Cookie
        const cookieStr = loadQQCookie();
        if (!cookieStr) {
            return res.status(500).json({
                code: 500,
                msg: 'Cookie文件为空或不存在，请配置qqcookie.txt',
                data: null
            });
        }
        
        // 创建QQ音乐实例
        const qqmusic = new QQMusic();
        qqmusic.setCookies(cookieStr);
        
        // 获取歌词 - 优先使用songid，如果没有则使用songmid
        let result;
        if (songid) {
            result = await qqmusic.getMusicLyricNew(songid);
        } else {
            // 如果只有songmid，需要先获取歌曲信息得到songid
            const songInfo = await qqmusic.getMusicSong(songmid, 0);
            if (songInfo.id) {
                result = await qqmusic.getMusicLyricNew(songInfo.id);
            } else {
                return res.json({
                    code: 500,
                    msg: '无法获取歌曲ID，请提供songid参数',
                    data: null
                });
            }
        }
        
        if (result.error) {
            return res.json({
                code: 500,
                msg: result.error,
                data: null
            });
        }
        
        res.json({
            code: 200,
            msg: '获取成功',
            data: {
                songmid: songmid || '',
                songid: songid || '',
                lyric: result.lyric || '',
                trans_lyric: result.tylyric || ''
            }
        });
        
    } catch (error) {
        console.error('获取歌词错误:', error);
        res.status(500).json({
            code: 500,
            msg: '服务器内部错误: ' + error.message,
            data: null
        });
    }
});

// 批量检查音质支持接口
router.post('/check-quality-support', async (req, res) => {
    try {
        const { songmids, qualities, mode = 'single' } = req.body;
        
        // 参数验证
        if (mode === 'single') {
            // 单首歌曲检查所有音质
            if (!songmids || (Array.isArray(songmids) && songmids.length === 0) || (!Array.isArray(songmids) && !songmids)) {
                return res.status(400).json({
                    code: 400,
                    msg: '缺少songmids参数，单首歌曲模式需要提供songmid',
                    data: null
                });
            }
            
            const songmid = Array.isArray(songmids) ? songmids[0] : songmids;
            
            // 读取Cookie
            const cookieStr = loadQQCookie();
            if (!cookieStr) {
                return res.status(500).json({
                    code: 500,
                    msg: 'Cookie文件为空或不存在，请配置qqcookie.txt',
                    data: null
                });
            }
            
            // 创建QQ音乐实例
            const qqmusic = new QQMusic();
            qqmusic.setCookies(cookieStr);
            
            // 获取歌曲基本信息
            const songInfo = await qqmusic.getMusicSong(songmid, 0);
            if (songInfo.msg) {
                return res.json({
                    code: 500,
                    msg: `歌曲信息获取失败: ${songInfo.msg}`,
                    data: null
                });
            }
            
            // 检查所有音质
            const allQualities = Object.keys(qualityMap);
            const supportedQualities = [];
            const unsupportedQualities = [];
            const qualityDetails = {};
            
            for (const qualityName of allQualities) {
                const qualityCode = qualityMap[qualityName];
                try {
                    const result = await qqmusic.getMusicUrl(songmid, qualityCode);
                    if (result && result.url) {
                        supportedQualities.push(qualityName);
                        qualityDetails[qualityName] = {
                            code: qualityCode,
                            bitrate: result.bitrate,
                            supported: true,
                            url_available: true,
                            url: result.url
                        };
                    } else {
                        unsupportedQualities.push(qualityName);
                        qualityDetails[qualityName] = {
                            code: qualityCode,
                            bitrate: null,
                            supported: false,
                            url_available: false,
                            reason: '可能需要VIP权限或该音质不存在'
                        };
                    }
                } catch (error) {
                    unsupportedQualities.push(qualityName);
                    qualityDetails[qualityName] = {
                        code: qualityCode,
                        bitrate: null,
                        supported: false,
                        url_available: false,
                        reason: `检查失败: ${error.message}`
                    };
                }
                
                // 添加小延迟避免请求过快
                await new Promise(resolve => setTimeout(resolve, 100));
            }
            
            res.json({
                code: 200,
                msg: '音质支持检查完成',
                data: {
                    songmid: songmid,
                    song_info: {
                        name: songInfo.name,
                        singer: songInfo.singer,
                        album: songInfo.album
                    },
                    total_qualities: allQualities.length,
                    supported_count: supportedQualities.length,
                    unsupported_count: unsupportedQualities.length,
                    supported_qualities: supportedQualities,
                    unsupported_qualities: unsupportedQualities,
                    quality_details: qualityDetails,
                    best_quality: getBestQuality(supportedQualities)
                }
            });
            
        } else if (mode === 'batch') {
            // 批量歌曲检查指定音质
            if (!songmids || !Array.isArray(songmids) || songmids.length === 0) {
                return res.status(400).json({
                    code: 400,
                    msg: '批量模式需要提供songmids数组',
                    data: null
                });
            }
            
            if (!qualities || !Array.isArray(qualities) || qualities.length === 0) {
                return res.status(400).json({
                    code: 400,
                    msg: '批量模式需要提供qualities数组',
                    data: null
                });
            }
            
            // 读取Cookie
            const cookieStr = loadQQCookie();
            if (!cookieStr) {
                return res.status(500).json({
                    code: 500,
                    msg: 'Cookie文件为空或不存在，请配置qqcookie.txt',
                    data: null
                });
            }
            
            // 创建QQ音乐实例
            const qqmusic = new QQMusic();
            qqmusic.setCookies(cookieStr);
            
            const batchResults = {};
            let totalChecked = 0;
            let totalSupported = 0;
            
            for (const songmid of songmids) {
                batchResults[songmid] = {
                    song_info: null,
                    qualities: {},
                    supported_count: 0,
                    total_count: qualities.length
                };
                
                // 获取歌曲信息
                try {
                    const songInfo = await qqmusic.getMusicSong(songmid, 0);
                    if (!songInfo.msg) {
                        batchResults[songmid].song_info = {
                            name: songInfo.name,
                            singer: songInfo.singer,
                            album: songInfo.album
                        };
                    }
                } catch (error) {
                    console.error(`获取歌曲信息失败 ${songmid}:`, error);
                }
                
                // 检查指定音质
                for (const qualityName of qualities) {
                    const qualityCode = qualityMap[qualityName] || qualityName;
                    totalChecked++;
                    
                    try {
                        const result = await qqmusic.getMusicUrl(songmid, qualityCode);
                        if (result && result.url) {
                            batchResults[songmid].qualities[qualityName] = {
                                code: qualityCode,
                                bitrate: result.bitrate,
                                supported: true,
                                url_available: true,
                                url: result.url
                            };
                            batchResults[songmid].supported_count++;
                            totalSupported++;
                        } else {
                            batchResults[songmid].qualities[qualityName] = {
                                code: qualityCode,
                                bitrate: null,
                                supported: false,
                                url_available: false,
                                reason: '可能需要VIP权限或该音质不存在'
                            };
                        }
                    } catch (error) {
                        batchResults[songmid].qualities[qualityName] = {
                            code: qualityCode,
                            bitrate: null,
                            supported: false,
                            url_available: false,
                            reason: `检查失败: ${error.message}`
                        };
                    }
                    
                    // 添加小延迟避免请求过快
                    await new Promise(resolve => setTimeout(resolve, 50));
                }
            }
            
            res.json({
                code: 200,
                msg: '批量音质支持检查完成',
                data: {
                    mode: 'batch',
                    total_songs: songmids.length,
                    checked_qualities: qualities,
                    total_checks: totalChecked,
                    total_supported: totalSupported,
                    support_rate: `${((totalSupported / totalChecked) * 100).toFixed(2)}%`,
                    results: batchResults
                }
            });
            
        } else {
            return res.status(400).json({
                code: 400,
                msg: 'mode参数无效，支持: single(单首歌曲检查所有音质) 或 batch(批量歌曲检查指定音质)',
                data: null
            });
        }
        
    } catch (error) {
        console.error('音质支持检查错误:', error);
        res.status(500).json({
            code: 500,
            msg: '服务器内部错误: ' + error.message,
            data: null
        });
    }
});

// 获取歌曲信息接口
router.post('/get-info', async (req, res) => {
    try {
        const { songmid, songid } = req.body;
        
        if (!songmid && !songid) {
            return res.status(400).json({
                code: 400,
                msg: '缺少songmid或songid参数',
                data: null
            });
        }
        
        // 读取Cookie
        const cookieStr = loadQQCookie();
        if (!cookieStr) {
            return res.status(500).json({
                code: 500,
                msg: 'Cookie文件为空或不存在，请配置qqcookie.txt',
                data: null
            });
        }
        
        // 创建QQ音乐实例
        const qqmusic = new QQMusic();
        qqmusic.setCookies(cookieStr);
        
        // 获取歌曲信息
        const result = await qqmusic.getMusicSong(songmid || '', songid || 0);
        
        if (result.msg) {
            return res.json({
                code: 500,
                msg: result.msg,
                data: null
            });
        }
        
        res.json({
            code: 200,
            msg: '获取成功',
            data: {
                name: result.name,
                album: result.album,
                singer: result.singer,
                pic: result.pic,
                mid: result.mid,
                id: result.id,
                interval: result.interval
            }
        });
        
    } catch (error) {
        console.error('获取歌曲信息错误:', error);
        res.status(500).json({
            code: 500,
            msg: '服务器内部错误: ' + error.message,
            data: null
        });
    }
});

module.exports = router;