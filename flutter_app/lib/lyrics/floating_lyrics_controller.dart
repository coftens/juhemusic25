import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/php_api_client.dart';
import '../audio/player_service.dart';

class FloatingLyricsController with WidgetsBindingObserver {
  FloatingLyricsController._();

  static final instance = FloatingLyricsController._();

  static const _prefKey = 'lyrics.floating.enabled.v1';
  static const _colorPrefKey = 'lyrics.floating.color.v1';
  static const _channel = MethodChannel('floating_lyrics');

  // 所有可用的颜色
  static const colorOptions = {
    'cherry': 0xFF74070E,    // CHERRY RED · 樱桃红
    'butter': 0xFFFFEDA8,    // BUTTER YELLOW · 奶油黄
    'indigo': 0xFFB0A6DF,    // AURA INDIGO · 光环靛蓝
    'dill': 0xFF4E6813,      // Dill Green · 泡菜绿
    'oat': 0xFFF0E7DA,       // Alpine Oat · 阿尔卑斯燕麦色
  };

  static const colorNames = {
    'cherry': '樱桃红',
    'butter': '奶油黄',
    'indigo': '光环靛蓝',
    'dill': '泡菜绿',
    'oat': '燕麦色',
  };

  final _api = PhpApiClient();
  final _player = PlayerService.instance;

  bool _enabled = false;
  bool _hasPermission = false;
  bool _appInForeground = true;
  bool _shown = false;
  String _selectedColor = 'butter'; // 默认奶油黄

  String _lyricFor = '';
  List<_LyricLine> _lines = const [];
  final Map<String, List<_LyricLine>> _cache = {};

  StreamSubscription<Duration>? _posSub;
  StreamSubscription<dynamic>? _stateSub;
  Timer? _updateTimer;

  bool get enabled => _enabled;
  bool get hasPermission => _hasPermission;
  String get selectedColor => _selectedColor;

  Future<void> init() async {
    WidgetsBinding.instance.addObserver(this);
    _enabled = await _loadEnabled();
    _selectedColor = await _loadColor();
    _hasPermission = await checkPermission();
    _player.addListener(_onPlayerChanged);
    _posSub = _player.positionStream.listen(_onPosition);
    _stateSub = _player.playerStateStream.listen((_) => _syncVisibility());
    await _syncLyricsForCurrent();
    _syncVisibility();
  }

  Future<void> dispose() async {
    WidgetsBinding.instance.removeObserver(this);
    _player.removeListener(_onPlayerChanged);
    await _posSub?.cancel();
    await _stateSub?.cancel();
    _updateTimer?.cancel();
  }

  Future<bool> _loadEnabled() async {
    final sp = await SharedPreferences.getInstance();
    return sp.getBool(_prefKey) ?? false;
  }

  Future<String> _loadColor() async {
    final sp = await SharedPreferences.getInstance();
    return sp.getString(_colorPrefKey) ?? 'butter';
  }

  Future<void> setEnabled(bool v) async {
    final sp = await SharedPreferences.getInstance();
    await sp.setBool(_prefKey, v);
    _enabled = v;
    if (_enabled && !_hasPermission) {
      _hasPermission = await checkPermission();
    }
    _syncVisibility();
  }

  Future<void> setColor(String colorKey) async {
    if (!colorOptions.containsKey(colorKey)) return;
    final sp = await SharedPreferences.getInstance();
    await sp.setString(_colorPrefKey, colorKey);
    _selectedColor = colorKey;
    
    // 立即应用颜色
    final color = colorOptions[colorKey] ?? 0xFFFFEDA8;
    try {
      await _channel.invokeMethod('updateColor', {'color': color});
    } catch (e) {
      debugPrint('FloatingLyrics: updateColor error: $e');
    }
  }

  Future<bool> checkPermission() async {
    try {
      final ok = await _channel.invokeMethod<bool>('checkPermission');
      _hasPermission = ok ?? false;
      return _hasPermission;
    } catch (e) {
      debugPrint('FloatingLyrics: checkPermission error: $e');
      _hasPermission = false;
      return false;
    }
  }

  Future<void> requestPermission() async {
    try {
      await _channel.invokeMethod('requestPermission');
    } catch (e) {
      debugPrint('FloatingLyrics: requestPermission error: $e');
    }
  }

  Future<void> openOverlaySettings({String? vendor}) async {
    try {
      await _channel.invokeMethod('openOverlaySettings', {'vendor': vendor ?? ''});
    } catch (e) {
      debugPrint('FloatingLyrics: openOverlaySettings error: $e');
      // 静默处理，不影响用户体验
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _appInForeground = state == AppLifecycleState.resumed;
    if (_appInForeground) {
      checkPermission().then((_) => _syncVisibility());
      return;
    }
    _syncVisibility();
  }

  void _onPlayerChanged() {
    _syncLyricsForCurrent();
  }

  Future<void> _syncLyricsForCurrent() async {
    final cur = _player.current;
    if (cur == null) {
      _lyricFor = '';
      _lines = const [];
      _syncVisibility();
      return;
    }
    final url = cur.shareUrl;
    if (url.isEmpty || url == _lyricFor) return;

    _lyricFor = url;
    if (_cache.containsKey(url)) {
      _lines = _cache[url] ?? const [];
      _syncVisibility();
      return;
    }

    String raw = cur.lyrics;
    if (raw.isEmpty) {
      try {
        final r = await _api.lyrics(url);
        raw = r.lyricLrc;
      } catch (_) {
        raw = '';
      }
    }
    final lines = _parseLrc(raw);
    _cache[url] = lines;
    _lines = lines;
    _syncVisibility();
  }

  void _onPosition(Duration pos) {
    if (_lines.isEmpty || !_shown) return;
    
    final ms = pos.inMilliseconds;
    final idx = _findActiveIndex(_lines, ms);
    
    if (idx < 0 || idx >= _lines.length) return;
    
    final currentText = _lines[idx].text;
    
    final nextIdx = idx + 1;
    if (nextIdx < _lines.length) {
      final nextLine = _lines[nextIdx];
      final timeToNext = nextLine.ms - ms;
      
      if (timeToNext <= 0) {
        _channel.invokeMethod('update', {'text': nextLine.text});
        return;
      }
    }
    
    _channel.invokeMethod('update', {'text': currentText});
  }

  void _syncVisibility() {
    final shouldShow = _enabled && _hasPermission && !_appInForeground && _player.playing && _lines.isNotEmpty;
    if (shouldShow && !_shown) {
      final ms = _player.position.inMilliseconds;
      final idx = _findActiveIndex(_lines, ms);
      final text = idx >= 0 && idx < _lines.length ? _lines[idx].text : (_lines.isNotEmpty ? _lines.first.text : '');
      final color = colorOptions[_selectedColor] ?? 0xFFFFEDA8;
      _channel.invokeMethod('start', {'text': text, 'color': color});
      _shown = true;
      _startHighFrequencyUpdates();
      return;
    }
    if (!shouldShow && _shown) {
      _channel.invokeMethod('stop');
      _shown = false;
      _updateTimer?.cancel();
      _updateTimer = null;
    }
  }

  void _startHighFrequencyUpdates() {
    _updateTimer?.cancel();
    _updateTimer = Timer.periodic(const Duration(milliseconds: 100), (_) {
      if (_shown && _player.playing) {
        _onPosition(_player.position);
      } else {
        _updateTimer?.cancel();
        _updateTimer = null;
      }
    });
  }
}

class _LyricLine {
  const _LyricLine(this.ms, this.text);

  final int ms;
  final String text;
}

final _timeRe = RegExp(r'\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]');

List<_LyricLine> _parseLrc(String input) {
  if (input.trim().isEmpty) return const [];
  final lines = <_LyricLine>[];
  for (final raw in input.split(RegExp(r'\r?\n'))) {
    final matches = _timeRe.allMatches(raw).toList();
    if (matches.isEmpty) continue;
    final text = raw.replaceAll(_timeRe, '').trim();
    if (text.isEmpty) continue;
    for (final m in matches) {
      final mm = int.parse(m.group(1)!);
      final ss = int.parse(m.group(2)!);
      final frac = m.group(3);
      final ms = frac == null
          ? 0
          : frac.length == 1
              ? int.parse(frac) * 100
              : frac.length == 2
                  ? int.parse(frac) * 10
                  : int.parse(frac.padRight(3, '0').substring(0, 3));
      final t = (mm * 60 + ss) * 1000 + ms;
      lines.add(_LyricLine(t, text));
    }
  }
  lines.sort((a, b) => a.ms.compareTo(b.ms));
  return lines;
}

int _findActiveIndex(List<_LyricLine> lines, int ms) {
  var lo = 0;
  var hi = lines.length - 1;
  var ans = 0;
  while (lo <= hi) {
    final mid = (lo + hi) >> 1;
    final t = lines[mid].ms;
    if (t <= ms) {
      ans = mid;
      lo = mid + 1;
    } else {
      hi = mid - 1;
    }
  }
  return ans;
}
