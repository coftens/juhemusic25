#!/usr/bin/env python3
import os


def main() -> None:
    # Importing server will load cookies from configured paths.
    import server

    print('cookies_loaded', bool(server.QQ_COOKIE_STR), bool(server.WYY_COOKIE_STR))

    # /search smoke
    qq_s = server.qq_search('周杰伦', 3)
    wyy_s = server.wyy_search('周杰伦', 3)
    print('qq_search_count', len(qq_s))
    print('wyy_search_count', len(wyy_s))

    # /parse smoke
    qq_url = 'https://y.qq.com/n/ryqq_v2/songDetail/003cSLOO35W3yP'
wyy_url = 'https://music.163.com/song?id=3333988321'
    qs_url = 'https://qishui.douyin.com/s/ia2T2aMo/'

    qq_p = server.qq_parse(qq_url, 'lossless')
    print('qq_parse_best', qq_p['best']['quality'], bool(qq_p['best']['url']))

    wyy_p = server.wyy_parse(wyy_url, 'lossless')
    print('wyy_parse_best', wyy_p['best']['quality'], bool(wyy_p['best']['url']))

    # Qishui requires PHP.
    # If PHP_BIN is not set and php is not on PATH, this may fail.
    try:
        qs_p = server.qishui_parse(qs_url)
        print('qishui_parse_best', qs_p['best']['quality'], bool(qs_p['best']['url']))
    except Exception as e:
        print('qishui_parse_skipped', str(e)[:120])


if __name__ == '__main__':
    main()
