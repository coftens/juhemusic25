/**
 * 汽水音乐 (Qishui Music) 前端解密工具 (JavaScript 版)
 * 支持在浏览器或 Node.js 环境中解密 cenc-aes-ctr 加密的音频流。
 */

const QishuiDecrypt = {
    /**
     * 计算位 1 的个数 (equivalent to Python bitcount)
     */
    bitcount(n) {
        let u = n >>> 0;
        u = u - ((u >> 1) & 0x55555555);
        u = (u & 0x33333333) + ((u >> 2) & 0x33333333);
        return (((u + (u >> 4)) & 0x0F0F0F0F) * 0x01010101 >> 24) & 0xFF;
    },

    /**
     * Base36 解码
     */
    decodeBase36(c) {
        const char = String.fromCharCode(c).toLowerCase();
        if (char >= '0' && char <= '9') return char.charCodeAt(0) - 48;
        if (char >= 'a' && char <= 'z') return char.charCodeAt(0) - 97 + 10;
        return 0xFF;
    },

    /**
     * Spade 内部解密算法
     */
    decryptSpadeInner(keyBytes) {
        const result = new Uint8Array(keyBytes.length);
        const buff = new Uint8Array([0xFA, 0x55, ...keyBytes]);
        for (let i = 0; i < keyBytes.length; i++) {
            let v = (keyBytes[i] ^ buff[i]) - this.bitcount(i) - 21;
            while (v < 0) v += 255;
            result[i] = v % 256;
        }
        return result;
    },

    /**
     * 从 spade_a 提取 16 进制 AES 密钥
     */
    extractKey(playAuth) {
        try {
            const bytesData = Uint8Array.from(atob(playAuth), c => c.charCodeAt(0));
            if (bytesData.length < 3) return null;

            const paddingLen = (bytesData[0] ^ bytesData[1] ^ bytesData[2]) - 48;
            if (bytesData.length < paddingLen + 2) return null;

            const innerInput = bytesData.slice(1, bytesData.length - paddingLen);
            const tmpBuff = this.decryptSpadeInner(innerInput);

            const skipBytes = this.decodeBase36(tmpBuff[0]);
            const endIndex = 1 + (bytesData.length - paddingLen - 2) - skipBytes;

            if (endIndex > tmpBuff.length || endIndex < 1) return null;

            const hexKey = new TextDecoder().decode(tmpBuff.slice(1, endIndex));
            return hexKey;
        } catch (e) {
            console.error("Extract key error:", e);
            return null;
        }
    },

    /**
     * 解析 MP4 Box
     */
    findBox(data, boxType, start, end) {
        const view = new DataView(data.buffer, data.byteOffset, data.byteLength);
        let pos = start;
        const target = boxType;
        
        while (pos + 8 <= end) {
            const size = view.getUint32(pos);
            if (size < 8) break;
            
            const type = String.fromCharCode(data[pos+4], data[pos+5], data[pos+6], data[pos+7]);
            if (type === target) {
                return {
                    offset: pos,
                    size: size,
                    data: data.slice(pos + 8, pos + size)
                };
            }
            pos += size;
        }
        return null;
    },

    /**
     * 解析 stsz box (Sample Sizes)
     */
    parseStsz(data) {
        if (data.length < 12) return [];
        const view = new DataView(data.buffer, data.byteOffset, data.byteLength);
        const sampleSizeFixed = view.getUint32(4);
        const sampleCount = view.getUint32(8);
        
        if (sampleSizeFixed !== 0) {
            return new Array(sampleCount).fill(sampleSizeFixed);
        } else {
            const sizes = [];
            for (let i = 0; i < sampleCount; i++) {
                sizes.push(view.getUint32(12 + i * 4));
            }
            return sizes;
        }
    },

    /**
     * 解析 senc box (Sample Encryption)
     */
    parseSenc(data) {
        if (data.length < 8) return [];
        const view = new DataView(data.buffer, data.byteOffset, data.byteLength);
        const flags = view.getUint32(0) & 0x00FFFFFF;
        const sampleCount = view.getUint32(4);
        const ivs = [];
        let ptr = 8;
        const hasSubsamples = (flags & 0x02) !== 0;
        
        for (let i = 0; i < sampleCount; i++) {
            ivs.push(data.slice(ptr, ptr + 8));
            ptr += 8;
            if (hasSubsamples) {
                const subCount = view.getUint16(ptr);
                ptr += 2 + (subCount * 6);
            }
        }
        return ivs;
    },

    /**
     * 主解密函数 (Web Crypto API)
     * @param {Uint8Array} fileData 加密的音频二进制数据
     * @param {string} spadeA 从 API 获取的 spade_a 字符串
     * @returns {Promise<Uint8Array>} 解密后的音频数据
     */
    async decryptAudio(fileData, spadeA) {
        const hexKey = this.extractKey(spadeA);
        if (!hexKey) throw new Error("Could not extract key");

        // 将 hexKey 转为 Uint8Array
        const keyBytes = new Uint8Array(hexKey.match(/.{1,2}/g).map(byte => parseInt(byte, 16)));
        
        // 导入密钥 (AES-CTR)
        const cryptoKey = await crypto.subtle.importKey(
            "raw",
            keyBytes,
            { name: "AES-CTR" },
            false,
            ["decrypt"]
        );

        const moov = this.findBox(fileData, "moov", 0, fileData.length);
        if (!moov) throw new Error("moov not found");

        let stbl = this.findBox(fileData, "stbl", moov.offset, moov.offset + moov.size);
        if (!stbl) {
            const trak = this.findBox(fileData, "trak", moov.offset + 8, moov.offset + moov.size);
            if (trak) {
                const mdia = this.findBox(fileData, "mdia", trak.offset + 8, trak.offset + trak.size);
                if (mdia) {
                    const minf = this.findBox(fileData, "minf", mdia.offset + 8, mdia.offset + mdia.size);
                    if (minf) {
                        stbl = this.findBox(fileData, "stbl", minf.offset + 8, minf.offset + minf.size);
                    }
                }
            }
        }
        if (!stbl) throw new Error("stbl not found");

        const stszBox = this.findBox(fileData, "stsz", stbl.offset + 8, stbl.offset + stbl.size);
        const sampleSizes = this.parseStsz(stszBox.data);

        let sencBox = this.findBox(fileData, "senc", moov.offset + 8, moov.offset + moov.size);
        if (!sencBox) sencBox = this.findBox(fileData, "senc", stbl.offset + 8, stbl.offset + stbl.size);
        if (!sencBox) throw new Error("senc not found");
        const ivs = this.parseSenc(sencBox.data);

        const mdat = this.findBox(fileData, "mdat", 0, fileData.length);
        if (!mdat) throw new Error("mdat not found");

        const decryptedData = new Uint8Array(fileData);
        let readPtr = mdat.offset + 8;
        const decryptedMdat = [];

        for (let i = 0; i < sampleSizes.length; i++) {
            const size = sampleSizes[i];
            const chunk = fileData.slice(readPtr, readPtr + size);
            
            if (i < ivs.length) {
                const ivShort = ivs[i];
                const iv = new Uint8Array(16);
                iv.set(ivShort); // 剩余部分默认为 0
                
                // 使用 Web Crypto API 解密 chunk
                const decryptedChunk = await crypto.subtle.decrypt(
                    { name: "AES-CTR", counter: iv, length: 64 }, // Qishui uses 64-bit counter in 8-byte IV
                    cryptoKey,
                    chunk
                );
                decryptedMdat.push(new Uint8Array(decryptedChunk));
            } else {
                decryptedMdat.push(chunk);
            }
            readPtr += size;
        }

        // 合并解密后的 mdat
        const totalMdatSize = decryptedMdat.reduce((acc, curr) => acc + curr.length, 0);
        const mdatBuffer = new Uint8Array(totalMdatSize);
        let offset = 0;
        for (const chunk of decryptedMdat) {
            mdatBuffer.set(chunk, offset);
            offset += chunk.length;
        }

        decryptedData.set(mdatBuffer, mdat.offset + 8);

        // 将 'enca' 替换为 'mp4a'
        const stsd = this.findBox(fileData, "stsd", stbl.offset + 8, stbl.offset + stbl.size);
        if (stsd) {
            for (let i = 0; i < stsd.data.length - 4; i++) {
                if (stsd.data[i] === 101 && stsd.data[i+1] === 110 && stsd.data[i+2] === 99 && stsd.data[i+3] === 97) { // 'enca'
                    decryptedData[stsd.offset + 8 + i] = 109; // 'm'
                    decryptedData[stsd.offset + 8 + i + 1] = 112; // 'p'
                    decryptedData[stsd.offset + 8 + i + 2] = 52; // '4'
                    decryptedData[stsd.offset + 8 + i + 3] = 97; // 'a'
                    break;
                }
            }
        }

        return decryptedData;
    }
};

// 如果是 Node.js 环境，导出模块
if (typeof module !== 'undefined' && module.exports) {
    module.exports = QishuiDecrypt;
}
