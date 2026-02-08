import base64
import struct
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.backends import default_backend

def bitcount(n):
    u = n & 0xFFFFFFFF
    u = u - ((u >> 1) & 0x55555555)
    u = (u & 0x33333333) + ((u >> 2) & 0x33333333)
    return (((u + (u >> 4)) & 0x0F0F0F0F) * 0x01010101 >> 24) & 0xFF

def decode_base36(c):
    if isinstance(c, int):
        c = chr(c)
    if '0' <= c <= '9':
        return ord(c) - ord('0')
    if 'a' <= c <= 'z':
        return ord(c) - ord('a') + 10
    return 0xFF

def decrypt_spade_inner(key_bytes):
    result = bytearray(len(key_bytes))
    buff = bytearray([0xFA, 0x55]) + key_bytes
    for i in range(len(key_bytes)):
        v = (key_bytes[i] ^ buff[i]) - bitcount(i) - 21
        while v < 0:
            v += 255 # Following Go code's 255
        result[i] = v % 256
    return result

def extract_key(play_auth):
    try:
        bytes_data = base64.b64decode(play_auth)
        if len(bytes_data) < 3:
            return None
        
        padding_len = (bytes_data[0] ^ bytes_data[1] ^ bytes_data[2]) - 48
        if len(bytes_data) < padding_len + 2:
            return None
            
        inner_input = bytes_data[1 : len(bytes_data) - padding_len]
        tmp_buff = decrypt_spade_inner(inner_input)
        
        if not tmp_buff:
            return None
            
        skip_bytes = decode_base36(tmp_buff[0])
        end_index = 1 + (len(bytes_data) - padding_len - 2) - skip_bytes
        
        if end_index > len(tmp_buff) or end_index < 1:
             return None
             
        return tmp_buff[1:end_index].decode('utf-8')
    except Exception as e:
        print(f"Extract key error: {e}")
        return None

class MP4Box:
    def __init__(self, offset, size, data):
        self.offset = offset
        self.size = size
        self.data = data

def find_box(data, box_type, start, end):
    if end > len(data):
        end = len(data)
    pos = start
    target = box_type.encode('ascii')
    while pos + 8 <= end:
        size = struct.unpack('>I', data[pos:pos+4])[0]
        if size < 8:
            break
        if data[pos+4:pos+8] == target:
            return MP4Box(pos, size, data[pos+8 : pos+size])
        pos += size
    return None

def parse_stsz(data):
    if len(data) < 12:
        return []
    sample_size_fixed = struct.unpack('>I', data[4:8])[0]
    sample_count = struct.unpack('>I', data[8:12])[0]
    if sample_size_fixed != 0:
        return [sample_size_fixed] * sample_count
    else:
        sizes = []
        for i in range(sample_count):
            if 12 + i * 4 + 4 <= len(data):
                sizes.append(struct.unpack('>I', data[12 + i * 4 : 12 + i * 4 + 4])[0])
        return sizes

def parse_senc(data):
    if len(data) < 8:
        return []
    flags = struct.unpack('>I', data[0:4])[0] & 0x00FFFFFF
    sample_count = struct.unpack('>I', data[4:8])[0]
    ivs = []
    ptr = 8
    has_subsamples = (flags & 0x02) != 0
    for i in range(sample_count):
        if ptr + 8 > len(data):
            break
        ivs.append(data[ptr : ptr + 8])
        ptr += 8
        if has_subsamples:
            if ptr + 2 > len(data):
                break
            sub_count = struct.unpack('>H', data[ptr : ptr + 2])[0]
            ptr += 2 + (sub_count * 6)
    return ivs

def decrypt_audio(file_data, play_auth):
    hex_key = extract_key(play_auth)
    if not hex_key:
        raise ValueError("Could not extract key from play_auth")
    
    key_bytes = bytes.fromhex(hex_key)
    
    moov = find_box(file_data, "moov", 0, len(file_data))
    if not moov:
        raise ValueError("moov box not found")
        
    stbl = find_box(file_data, "stbl", moov.offset, moov.offset + moov.size)
    if not stbl:
        # Search deeper for stbl
        trak = find_box(file_data, "trak", moov.offset + 8, moov.offset + moov.size)
        if trak:
            mdia = find_box(file_data, "mdia", trak.offset + 8, trak.offset + trak.size)
            if mdia:
                minf = find_box(file_data, "minf", mdia.offset + 8, mdia.offset + mdia.size)
                if minf:
                    stbl = find_box(file_data, "stbl", minf.offset + 8, minf.offset + minf.size)
    
    if not stbl:
        raise ValueError("stbl box not found")
        
    stsz_box = find_box(file_data, "stsz", stbl.offset + 8, stbl.offset + stbl.size)
    if not stsz_box:
        raise ValueError("stsz box not found")
    sample_sizes = parse_stsz(stsz_box.data)
    
    senc_box = find_box(file_data, "senc", moov.offset + 8, moov.offset + moov.size)
    if not senc_box:
        senc_box = find_box(file_data, "senc", stbl.offset + 8, stbl.offset + stbl.size)
    
    if not senc_box:
        raise ValueError("senc box not found")
    ivs = parse_senc(senc_box.data)
    
    mdat = find_box(file_data, "mdat", 0, len(file_data))
    if not mdat:
        raise ValueError("mdat box not found")
        
    decrypted_data = bytearray(file_data)
    read_ptr = mdat.offset + 8
    decrypted_mdat = bytearray()
    
    backend = default_backend()
    
    for i in range(len(sample_sizes)):
        size = sample_sizes[i]
        if read_ptr + size > len(decrypted_data):
            break
        chunk = decrypted_data[read_ptr : read_ptr + size]
        
        if i < len(ivs):
            iv = ivs[i]
            if len(iv) < 16:
                iv = iv + b'\x00' * (16 - len(iv))
            
            cipher = Cipher(algorithms.AES(key_bytes), modes.CTR(iv), backend=backend)
            decryptor = cipher.decryptor()
            decrypted_chunk = decryptor.update(chunk) + decryptor.finalize()
            decrypted_mdat.extend(decrypted_chunk)
        else:
            decrypted_mdat.extend(chunk)
        read_ptr += size
        
    if len(decrypted_mdat) == mdat.size - 8:
        decrypted_data[mdat.offset + 8 : mdat.offset + mdat.size] = decrypted_mdat
    else:
        raise ValueError(f"Decrypted size mismatch: {len(decrypted_mdat)} != {mdat.size - 8}")
        
    # Change 'enca' to 'mp4a' in stsd box
    stsd = find_box(file_data, "stsd", stbl.offset + 8, stbl.offset + stbl.size)
    if stsd:
        stsd_data = decrypted_data[stsd.offset : stsd.offset + stsd.size]
        idx = stsd_data.find(b'enca')
        if idx != -1:
            stsd_data[idx : idx + 4] = b'mp4a'
            decrypted_data[stsd.offset : stsd.offset + stsd.size] = stsd_data
            
    return bytes(decrypted_data)
