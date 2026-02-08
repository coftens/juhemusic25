/**
 * QQ音乐API Node.js版本
 * 原作者: 苏晓晴
 * 原作者QQ: 3074193836
 * 个人博客 www.toubiec.cn
 */

const https = require('https');
const http = require('http');
const url = require('url');
const querystring = require('querystring');

class QQMusic {
    constructor() {
        this.baseUrl = 'https://u.y.qq.com/cgi-bin/musicu.fcg';
        this.guid = '10000';
        this.uin = '0';
        this.cookies = {};
        this.headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3'
        };
        
        // 歌曲文件类型配置
        this.fileConfig = {
            '128': { s: 'M500', e: '.mp3', bitrate: '128kbps' },
            '320': { s: 'M800', e: '.mp3', bitrate: '320kbps' },
            'flac': { s: 'F000', e: '.flac', bitrate: 'FLAC' },
            'master': { s: 'AI00', e: '.flac', bitrate: 'Master' },
            'atmos_2': { s: 'Q000', e: '.flac', bitrate: 'Atmos 2' },
            'atmos_51': { s: 'Q001', e: '.flac', bitrate: 'Atmos 5.1' },
            'ogg_640': { s: 'O801', e: '.ogg', bitrate: '640kbps' },
            'ogg_320': { s: 'O800', e: '.ogg', bitrate: '320kbps' },
            'ogg_192': { s: 'O600', e: '.ogg', bitrate: '192kbps' },
            'ogg_96': { s: 'O400', e: '.ogg', bitrate: '96kbps' },
            'aac_320': { s: 'C800', e: '.m4a', bitrate: '320kbps' },
            'aac_256': { s: 'C700', e: '.m4a', bitrate: '256kbps' },
            'aac_192': { s: 'C600', e: '.m4a', bitrate: '192kbps' },
            'aac_128': { s: 'C500', e: '.m4a', bitrate: '128kbps' },
            'aac_96': { s: 'C400', e: '.m4a', bitrate: '96kbps' },
            'aac_64': { s: 'C300', e: '.m4a', bitrate: '64kbps' },
            'aac_48': { s: 'C200', e: '.m4a', bitrate: '48kbps' },
            'aac_24': { s: 'C100', e: '.m4a', bitrate: '24kbps' },
            'ape': { s: 'A000', e: '.ape', bitrate: 'APE' },
            'dts': { s: 'D000', e: '.dts', bitrate: 'DTS' },
            'dolby': { s: 'RS01', e: '.flac', bitrate: 'Dolby Atmos' },
            'hires': { s: 'SQ00', e: '.flac', bitrate: 'Hi-Res' }
        };
        
        this.songUrl = 'https://c.y.qq.com/v8/fcg-bin/fcg_play_single_song.fcg';
        this.lyricUrl = 'https://c.y.qq.com/lyric/fcgi-bin/fcg_query_lyric_new.fcg';
    }
    
    setCookies(cookieStr) {
        if (cookieStr) {
            const cookies = cookieStr.split('; ');
            cookies.forEach(cookie => {
                const [key, value] = cookie.split('=');
                if (key && value) {
                    this.cookies[key] = value;
                }
            });
        }
    }
    
    ids(urlStr) {
        if (urlStr.includes('y.qq.com')) {
            if (urlStr.includes('/songDetail/')) {
                const match = urlStr.match(/\/songDetail\/([^\/\?]+)/);
                return match ? match[1] : '';
            }
            
            if (urlStr.includes('id=')) {
                const match = urlStr.match(/id=(\w+)/);
                return match ? match[1] : '';
            }
        }
        return null;
    }
    
    async getMusicUrl(songmid, fileType = 'flac') {
        if (!this.fileConfig[fileType]) {
            throw new Error(`Invalid file_type. Choose from: ${Object.keys(this.fileConfig).join(', ')}`);
        }
        
        const fileInfo = this.fileConfig[fileType];
        const file = `${fileInfo.s}${songmid}${songmid}${fileInfo.e}`;
        
        const reqData = {
            req_1: {
                module: 'vkey.GetVkeyServer',
                method: 'CgiGetVkey',
                param: {
                    filename: [file],
                    guid: this.guid,
                    songmid: [songmid],
                    songtype: [0],
                    uin: this.uin,
                    loginflag: 1,
                    platform: '20'
                }
            },
            loginUin: this.uin,
            comm: {
                uin: this.uin,
                format: 'json',
                ct: 24,
                cv: 0
            }
        };
        
        try {
            const response = await this.curlRequest(this.baseUrl, JSON.stringify(reqData));
            const data = JSON.parse(response);
            const purl = data.req_1?.data?.midurlinfo?.[0]?.purl || '';
            
            if (!purl) {
                return null; // VIP or unavailable
            }
            
            const musicUrl = data.req_1.data.sip[1] + purl;
            
            return {
                url: musicUrl.replace('http://', 'https://'),
                bitrate: fileInfo.bitrate
            };
        } catch (error) {
            console.error('获取音乐URL失败:', error);
            return null;
        }
    }
    
    async getMusicSong(mid, sid) {
        const reqData = sid !== 0 
            ? { songid: sid, platform: 'yqq', format: 'json' }
            : { songmid: mid, platform: 'yqq', format: 'json' };
            
        try {
            const response = await this.curlRequest(this.songUrl, querystring.stringify(reqData));
            const data = JSON.parse(response);
            
            if (data.data && data.data.length > 0) {
                const songInfo = data.data[0];
                const albumInfo = songInfo.album || {};
                const singers = songInfo.singer || [];
                const singerNames = singers.map(singer => singer.name).join(', ');
                
                const albumMid = albumInfo.mid || '';
                const imgUrl = albumMid 
                    ? `https://y.qq.com/music/photo_new/T002R800x800M000${albumMid}.jpg?max_age=2592000`
                    : 'https://example.com/default-cover.jpg';
                    
                const minutes = Math.floor(songInfo.interval / 60);
                const seconds = songInfo.interval % 60;
                const durationStr = `${minutes}:${seconds.toString().padStart(2, '0')}`;
                
                return {
                    name: songInfo.name || 'Unknown',
                    album: albumInfo.name || 'Unknown',
                    singer: singerNames,
                    pic: imgUrl,
                    mid: songInfo.mid || mid,
                    id: songInfo.id || sid,
                    interval: durationStr
                };
            } else {
                return { msg: '信息获取错误/歌曲不存在' };
            }
        } catch (error) {
            console.error('获取歌曲信息失败:', error);
            return { msg: '信息获取错误/歌曲不存在' };
        }
    }
    
    async getMusicLyricNew(songid) {
        const payload = {
            "music.musichallSong.PlayLyricInfo.GetPlayLyricInfo": {
                "module": "music.musichallSong.PlayLyricInfo",
                "method": "GetPlayLyricInfo",
                "param": {
                    "trans_t": 0,
                    "roma_t": 0,
                    "crypt": 0,
                    "lrc_t": 0,
                    "interval": 208,
                    "trans": 1,
                    "ct": 6,
                    "songID": songid
                }
            },
            "comm": {
                "ct": "6",
                "cv": "80600"
            }
        };
        
        try {
            const response = await this.curlRequest(this.baseUrl, JSON.stringify(payload));
            const data = JSON.parse(response);
            const lyricData = data["music.musichallSong.PlayLyricInfo.GetPlayLyricInfo"]?.data;
            
            const lyric = lyricData?.lyric ? Buffer.from(lyricData.lyric, 'base64').toString('utf-8') : '';
            const tylyric = lyricData?.trans ? Buffer.from(lyricData.trans, 'base64').toString('utf-8') : '';
            
            return { lyric, tylyric };
        } catch (error) {
            console.error('获取歌词失败:', error);
            return { error: '无法获取歌词' };
        }
    }
    
    curlRequest(requestUrl, postFields = null) {
        return new Promise((resolve, reject) => {
            const parsedUrl = url.parse(requestUrl);
            const isHttps = parsedUrl.protocol === 'https:';
            const client = isHttps ? https : http;
            
            const options = {
                hostname: parsedUrl.hostname,
                port: parsedUrl.port || (isHttps ? 443 : 80),
                path: parsedUrl.path,
                method: postFields ? 'POST' : 'GET',
                headers: {
                    ...this.headers
                }
            };
            
            if (postFields) {
                options.headers['Content-Type'] = 'application/x-www-form-urlencoded';
                options.headers['Content-Length'] = Buffer.byteLength(postFields);
            }
            
            // 添加Cookie
            if (Object.keys(this.cookies).length > 0) {
                const cookieStr = Object.entries(this.cookies)
                    .map(([key, value]) => `${key}=${value}`)
                    .join('; ');
                options.headers['Cookie'] = cookieStr;
            }
            
            const req = client.request(options, (res) => {
                let data = '';
                res.on('data', (chunk) => {
                    data += chunk;
                });
                res.on('end', () => {
                    resolve(data);
                });
            });
            
            req.on('error', (error) => {
                reject(error);
            });
            
            if (postFields) {
                req.write(postFields);
            }
            
            req.end();
        });
    }
    
    async processRequest(songUrl) {
        const songmid = this.ids(songUrl);
        if (!songmid) {
            return { error: '歌曲ID无效' };
        }
        
        let sid = 0;
        let mid = songmid;
        
        if (/^\d+$/.test(songmid)) {
            // 如果 songmid 是数字，视为 songid (sid)
            sid = parseInt(songmid);
            mid = 0;
        }
        
        try {
            const musicInfo = await this.getMusicSong(mid, sid);
            if (musicInfo.msg) {
                return musicInfo;
            }
            
            const musicLyric = await this.getMusicLyricNew(musicInfo.id);
            
            // 获取不同格式的音乐URL
            const fileTypes = ['aac_48', 'aac_96', 'aac_192', 'ogg_96', 'ogg_192', 'ogg_320', 'ogg_640', 'atmos_51', 'atmos_2', 'master', 'flac', '320', '128'];
            const results = {};
            
            for (const fileType of fileTypes) {
                const result = await this.getMusicUrl(musicInfo.mid, fileType);
                if (result) {
                    results[fileType] = {
                        url: result.url,
                        bitrate: result.bitrate
                    };
                }
            }
            
            return {
                music_info: musicInfo,
                music_url: results,
                music_lyric: musicLyric
            };
        } catch (error) {
            console.error('处理请求失败:', error);
            return { error: '处理请求失败' };
        }
    }
}

module.exports = QQMusic;
