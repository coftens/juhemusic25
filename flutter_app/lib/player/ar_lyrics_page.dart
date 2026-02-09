import 'dart:async';
import 'dart:math' as math;

import 'package:arkit_plugin/arkit_plugin.dart';
import 'package:flutter/material.dart';
import 'package:vector_math/vector_math_64.dart' as vec;

import '../api/php_api_client.dart';
import '../audio/player_service.dart';

class ArLyricsPage extends StatefulWidget {
  const ArLyricsPage({super.key, required this.item});

  final SearchItem item;

  static Future<void> push(BuildContext context, {required SearchItem item}) async {
    await Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => ArLyricsPage(item: item)),
    );
  }

  @override
  State<ArLyricsPage> createState() => _ArLyricsPageState();
}

class _ArLyricsPageState extends State<ArLyricsPage> {
  final _api = PhpApiClient();
  final _svc = PlayerService.instance;

  ARKitController? _arkit;

  static const _fadeStep = Duration(milliseconds: 80);
  // 更多的淡入步数，让过渡更平滑
  static const _fadeInSteps = [0.15, 0.32, 0.48, 0.62, 0.75, 0.85, 0.93, 1.0];
  // 更多的淡出步数，让消失更自然
  static const _fadeOutSteps = [0.9, 0.75, 0.58, 0.42, 0.28, 0.15, 0.06, 0.0];

  StreamSubscription<Duration>? _posSub;
  Timer? _passTimer;

  SearchItem? _item;
  String _lyricFor = '';
  List<_LyricLine> _lines = const [];
  int _activeIndex = 0;
  bool _loading = true;
  String? _error;

  _ArLyricNode? _currentNode;
  _ArLyricNode? _nextNode;
  bool _currentClearedByPass = false;

  @override
  void initState() {
    super.initState();
    _item = _svc.current ?? widget.item;
    _loadLyricsForCurrent();

    _svc.addListener(_onPlayerChanged);
    _posSub = _svc.positionStream.listen(_onPosition);

    _passTimer = Timer.periodic(const Duration(milliseconds: 200), (_) => _checkPassThrough());
  }

  @override
  void dispose() {
    _svc.removeListener(_onPlayerChanged);
    _posSub?.cancel();
    _passTimer?.cancel();
    _removeAllNodes();
    _arkit?.dispose();
    super.dispose();
  }

  void _onPlayerChanged() {
    final cur = _svc.current;
    if (cur == null) return;
    if (_lyricFor == cur.shareUrl) return;
    _item = cur;
    _loadLyricsForCurrent();
  }

  Future<void> _loadLyricsForCurrent() async {
    final cur = _svc.current ?? _item;
    if (cur == null) return;
    final url = cur.shareUrl;
    if (url.isEmpty) return;

    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      var raw = cur.lyrics;
      if (raw.isEmpty) {
        final r = await _api.lyrics(url);
        raw = r.lyricLrc;
      }
      final lines = _parseLrc(raw);
      if (!mounted) return;
      _lyricFor = url;
      _lines = lines;
      _activeIndex = 0;
      _currentClearedByPass = false;
      _refreshNodes();
    } catch (e) {
      if (!mounted) return;
      _error = e.toString();
      _lines = const [];
      _removeAllNodes();
    } finally {
      if (!mounted) return;
      setState(() {
        _loading = false;
      });
    }
  }

  void _onPosition(Duration pos) {
    if (_lines.isEmpty) return;
    if (_svc.isBuffering) return;

    final q = _svc.quality;
    final isHq = q.contains('flac') ||
        q.contains('hires') ||
        q.contains('master') ||
        q.contains('atmos') ||
        q.contains('sky') ||
        q.contains('effect') ||
        q.contains('320');
    final offset = isHq ? 400 : 0;

    final ms = math.max(0, pos.inMilliseconds - offset);
    final idx = _findActiveIndex(_lines, ms);
    if (idx == _activeIndex) return;

    // 立即移除不匹配的旧节点，避免切换时重叠
    if (_currentNode != null && _currentNode!.index != idx) {
      _arkit?.remove(_currentNode!.id);
      _currentNode = null;
    }

    _activeIndex = idx;
    _currentClearedByPass = false;
    _refreshNodes();
  }

  void _onARKitViewCreated(ARKitController controller) {
    _arkit = controller;
    _refreshNodes();
  }

  void _refreshNodes() {
    if (_arkit == null) return;
    if (_lines.isEmpty) {
      _fadeOutAllNodes();
      return;
    }

    // 移除不匹配的节点（而不是全部移除）
    if (_currentNode != null && _currentNode!.index != _activeIndex) {
      _arkit?.remove(_currentNode!.id);
      _currentNode = null;
    }
    if (_nextNode != null && _nextNode!.index != _activeIndex + 1) {
      _arkit?.remove(_nextNode!.id);
      _nextNode = null;
    }

    if (_activeIndex < 0 || _activeIndex >= _lines.length) return;

    // 只创建缺失的节点
    if (!_currentClearedByPass && _currentNode == null) {
      _currentNode = _addLineNode(index: _activeIndex, distance: 5.0);
    }

    if (_nextNode == null && _activeIndex + 1 < _lines.length) {
      _nextNode = _addLineNode(index: _activeIndex + 1, distance: 10.0);
    }
  }

  _ArLyricNode? _addLineNode({required int index, required double distance}) {
    if (_arkit == null) return null;

    final text = _lines[index].text;
    if (text.trim().isEmpty) return null;

    final position = _positionInFrontOfCamera(distance, index);
    final nodeName = 'lyric_${index}_${distance.toStringAsFixed(1)}';
    _addTextNodeAt(nodeName, index, position, _fadeInSteps.first);
    _fadeInNode(nodeName, index, position);

    return _ArLyricNode(
      id: nodeName,
      index: index,
      position: position,
    );
  }

  void _addTextNodeAt(String nodeName, int index, vec.Vector3 position, double opacity) {
    if (_arkit == null) return;
    if (index < 0 || index >= _lines.length) return;

    final text = _lines[index].text;
    if (text.trim().isEmpty) return;

    final material = _buildMaterial(opacity);
    final geometry = ARKitText(
      text: text,
      extrusionDepth: 0.08, // 增加到0.08，字体更厚重、发光面更大
      materials: [material],
    );

    final node = ARKitNode(
      name: nodeName,
      geometry: geometry,
      position: position,
      scale: vec.Vector3.all(0.048), // 进一步增大到0.048，接近参考视频的字体大小
      constraints: [ARKitBillboardConstraint()],
    );

    _arkit?.add(node);
  }

  ARKitMaterial _buildMaterial(double opacity) {
    final clamped = opacity.clamp(0.0, 1.0);
    // 发光强度：透明到完全发光的渐进
    final glowIntensity = math.min(1.2, clamped + 0.75);
    
    // 漫反射：白色为主，透明度随opacity变化
    final diffuseColor = Colors.white.withOpacity(clamped);
    
    // 发光颜色：混合白色和青蓝色，在高opacity时更偏青蓝
    final emissionBaseColor = Color.lerp(
      const Color(0xFF88FFFF), // 浅青
      const Color(0xFFFFFFFF), // 白色
      (1.0 - clamped).clamp(0.0, 1.0),
    ) ?? const Color(0xFFFFFFFF);
    
    return ARKitMaterial(
      diffuse: ARKitMaterialProperty.color(diffuseColor),
      // 强化发光效果：颜色 + 强度都增加
      emission: ARKitMaterialProperty.color(
        emissionBaseColor.withOpacity(glowIntensity),
      ),
      lightingModelName: ARKitLightingModel.constant,
    );
  }

  bool _isNodeActive(String nodeId) {
    return _currentNode?.id == nodeId || _nextNode?.id == nodeId;
  }

  Future<void> _fadeInNode(String nodeName, int index, vec.Vector3 position) async {
    for (final opacity in _fadeInSteps) {
      if (!mounted || _arkit == null) return;
      if (!_isNodeActive(nodeName)) return;
      _arkit?.remove(nodeName);
      _addTextNodeAt(nodeName, index, position, opacity);
      await Future.delayed(_fadeStep);
    }
  }

  Future<void> _fadeOutNode(_ArLyricNode node) async {
    for (final opacity in _fadeOutSteps) {
      if (!mounted || _arkit == null) break;
      try {
        _arkit?.remove(node.id);
        if (opacity > 0) {
          _addTextNodeAt(node.id, node.index, node.position, opacity);
        }
      } catch (_) {
        // 节点可能已被移除，忽略异常
      }
      await Future.delayed(_fadeStep);
    }
  }

  double _getHeightForIndex(int index) {
    // 使用索引生成伪随机高度，保证同一句歌词位置一致
    final seed = (index * 12345) % 0x7FFFFFFF;
    final random = math.Random(seed);
    return -0.15 + random.nextDouble() * 0.25; // 范围: -0.15 ~ 0.1，"立在地面上"
  }

  vec.Vector3 _positionInFrontOfCamera(double distance, int index) {
    final camera = _arkit?.cameraTransform;
    final height = _getHeightForIndex(index);
    if (camera == null) {
      return vec.Vector3(0, height, -distance);
    }

    final camPos = camera.getTranslation();
    final forward = vec.Vector3(
      -camera.entry(0, 2),
      -camera.entry(1, 2),
      -camera.entry(2, 2),
    );
    final dir = forward.length2 == 0 ? vec.Vector3(0, 0, -1) : forward.normalized();
    final basePos = camPos + dir * distance;
    return vec.Vector3(basePos.x, basePos.y + height, basePos.z);
  }

  vec.Vector3? _cameraPosition() {
    final camera = _arkit?.cameraTransform;
    if (camera == null) return null;
    return camera.getTranslation();
  }

  void _checkPassThrough() {
    if (_arkit == null) return;
    if (_currentNode == null) return;
    if (_activeIndex < _currentNode!.index) return;

    final camPos = _cameraPosition();
    if (camPos == null) return;

    final dist = (camPos - _currentNode!.position).length;
    if (dist > 0.7) return;

    final fading = _currentNode;
    _currentNode = null;
    _currentClearedByPass = true;
    if (fading != null) {
      // 延时2秒后再销毁，保留穿过效果
      Future.delayed(const Duration(seconds: 2), () {
        if (mounted && _arkit != null) {
          _fadeOutNode(fading);
        }
      });
    }
  }

  void _removeNode(_ArLyricNode? node) {
    if (node == null) return;
    _arkit?.remove(node.id);
  }

  void _removeAllNodes() {
    _removeNode(_currentNode);
    _removeNode(_nextNode);
    _currentNode = null;
    _nextNode = null;
  }

  void _fadeOutAllNodes() {
    final current = _currentNode;
    final next = _nextNode;
    _currentNode = null;
    _nextNode = null;
    if (current != null) {
      _fadeOutNode(current);
    }
    if (next != null) {
      _fadeOutNode(next);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(
        fit: StackFit.expand,
        children: [
          ARKitSceneView(
            onARKitViewCreated: _onARKitViewCreated,
          ),
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(14, 10, 14, 0),
              child: Row(
                children: [
                  IconButton(
                    onPressed: () => Navigator.of(context).pop(),
                    icon: const Icon(Icons.close_rounded),
                    color: Colors.white,
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      _item?.name ?? 'AR歌词',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
                    ),
                  ),
                ],
              ),
            ),
          ),
          if (_loading)
            const Align(
              alignment: Alignment.bottomCenter,
              child: Padding(
                padding: EdgeInsets.only(bottom: 24),
                child: SizedBox(
                  width: 22,
                  height: 22,
                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white70),
                ),
              ),
            ),
          if (_error != null)
            Align(
              alignment: Alignment.bottomCenter,
              child: Padding(
                padding: const EdgeInsets.only(bottom: 24),
                child: Text(
                  _error!,
                  style: const TextStyle(color: Colors.redAccent, fontWeight: FontWeight.w600),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _ArLyricNode {
  const _ArLyricNode({
    required this.id,
    required this.index,
    required this.position,
  });

  final String id;
  final int index;
  final vec.Vector3 position;
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
