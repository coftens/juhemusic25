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

  // ── 淡入淡出参数 ──
  static const _fadeStep = Duration(milliseconds: 80);
  static const _fadeInSteps  = [0.15, 0.32, 0.48, 0.62, 0.75, 0.85, 0.93, 1.0];
  static const _fadeOutSteps = [0.9, 0.75, 0.58, 0.42, 0.28, 0.15, 0.06, 0.0];

  // ── 订阅 & 定时器 ──
  StreamSubscription<Duration>? _posSub;
  Timer? _passTimer;
  double _lastFrameTime = 0;
  bool _trackingInFlight = false;

  // ── 歌曲 / 歌词状态 ──
  SearchItem? _item;
  String _lyricFor = '';
  List<_LyricLine> _lines = const [];
  int _activeIndex = 0;
  bool _loading = true;
  String? _error;

  // ── AR 场景节点 ──
  _ArLyricNode? _currentNode;
  _ArLyricNode? _nextNode;
  bool _currentClearedByPass = false;
  final _fadingNodeIds = <String>{}; // 正在淡入淡出的节点，跳过重定位
  final Map<String, double> _lastForwardDist = {};

  // ── 相机追踪 ──
  double _camX = 0, _camY = 0, _camZ = 0, _camYaw = 0;
  bool _cameraReady = false;

  // ─────────────────── 生命周期 ───────────────────────────

  @override
  void initState() {
    super.initState();
    _item = _svc.current ?? widget.item;
    _loadLyricsForCurrent();

    _svc.addListener(_onPlayerChanged);
    _posSub = _svc.positionStream.listen(_onPosition);

    _passTimer = Timer.periodic(
      const Duration(milliseconds: 200), (_) => _checkPassThrough());
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

  // ─────────────────── 播放器 / 歌词 ─────────────────────

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

    setState(() { _loading = true; _error = null; });

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
      if (mounted) setState(() => _loading = false);
    }
  }

  void _onPosition(Duration pos) {
    if (_lines.isEmpty || _svc.isBuffering) return;

    final q = _svc.quality;
    final isHq = q.contains('flac') || q.contains('hires') ||
        q.contains('master') || q.contains('atmos') ||
        q.contains('sky') || q.contains('effect') || q.contains('320');
    final offset = isHq ? 400 : 0;

    final ms = math.max(0, pos.inMilliseconds - offset);
    final idx = _findActiveIndex(_lines, ms);
    if (idx == _activeIndex) return;

    // 立即移除旧的当前节点
    if (_currentNode != null && _currentNode!.index != idx) {
      _safeRemove(_currentNode!.id);
      _currentNode = null;
    }

    _activeIndex = idx;
    _currentClearedByPass = false;
    _refreshNodes();
  }

  // ─────────────────── AR 控制器 ──────────────────────────

  void _onARKitViewCreated(ARKitController c) {
    _arkit = c;
    c.updateAtTime = _onFrameUpdate;
  }

  // ─────────────────── 相机追踪 ──────────────────────────

  void _onFrameUpdate(double time) {
    if (time - _lastFrameTime < 0.05) return; // ~20 FPS 更新
    _lastFrameTime = time;
    _trackCamera();
  }

  Future<void> _trackCamera() async {
    if (_arkit == null || _trackingInFlight) return;
    _trackingInFlight = true;
    try {
      final pos = await _arkit!.cameraPosition();
      if (pos == null) return;
      final angles = await _arkit!.getCameraEulerAngles();

      final wasReady = _cameraReady;
      _cameraReady = true;

      _camX = pos.x;
      _camY = pos.y;
      _camZ = pos.z;
      _camYaw = angles.y;

      if (!wasReady) {
        _refreshNodes();
        return;
      }
      _repositionNodes();
    } catch (_) {
      // 忽略相机读取失败
    } finally {
      _trackingInFlight = false;
    }
  }

  // ─────────────────── 坐标计算 ──────────────────────────

  /// 根据当前相机位置 + 朝向，计算歌词在世界坐标中的位置
  vec.Vector3 _calcWorldPosition(double distance, int index) {
    final groundY = _groundY(index);
    // 相机前方 = (-sin(yaw), 0, -cos(yaw))
    final x = _camX - math.sin(_camYaw) * distance;
    final z = _camZ - math.cos(_camYaw) * distance;
    return vec.Vector3(x, groundY, z);
  }

  /// 地面高度：手机通常在地面上方 ~1.2m，歌词放在地面上
  double _groundY(int index) {
    final seed = (index * 12345) % 0x7FFFFFFF;
    final r = math.Random(seed);
    // 地面约在相机下方 1.15~1.30 米
    return _camY - 1.15 - r.nextDouble() * 0.15;
  }

  // ─────────────────── 节点重定位 ────────────────────────

  void _repositionNodes() {
    if (_arkit == null) return;

    // 重定位当前歌词（如果未被穿越清除且不在淡入淡出中）
    if (!_currentClearedByPass && _currentNode != null &&
        !_fadingNodeIds.contains(_currentNode!.id)) {
      final n = _currentNode!;
      final newPos = _calcWorldPosition(n.distance, n.index);
      _updateNodeTransform(n.id, newPos);
      _currentNode = _ArLyricNode(
          id: n.id, index: n.index, distance: n.distance, position: newPos);
      _updateForwardDistance(n.id, newPos);
    }

    // 重定位下一句歌词
    if (_nextNode != null && !_fadingNodeIds.contains(_nextNode!.id)) {
      final n = _nextNode!;
      final newPos = _calcWorldPosition(n.distance, n.index);
      _updateNodeTransform(n.id, newPos);
      _nextNode = _ArLyricNode(
          id: n.id, index: n.index, distance: n.distance, position: newPos);
      _updateForwardDistance(n.id, newPos);
    }
  }

  void _updateNodeTransform(String id, vec.Vector3 pos) {
    _arkit?.update(
      id,
      node: ARKitNode(
        name: id,
        position: pos,
        eulerAngles: vec.Vector3(0, _camYaw, 0),
      ),
    );
  }

  void _updateForwardDistance(String id, vec.Vector3 pos) {
    final forwardX = -math.sin(_camYaw);
    final forwardZ = -math.cos(_camYaw);
    final dx = pos.x - _camX;
    final dz = pos.z - _camZ;
    _lastForwardDist[id] = dx * forwardX + dz * forwardZ;
  }

  // ─────────────────── 节点管理 ──────────────────────────

  void _refreshNodes() {
    if (_arkit == null || !_cameraReady) return;
    if (_lines.isEmpty) { _fadeOutAllNodes(); return; }

    if (_currentNode != null && _currentNode!.index != _activeIndex) {
      _safeRemove(_currentNode!.id);
      _currentNode = null;
    }
    if (_nextNode != null && _nextNode!.index != _activeIndex + 1) {
      _safeRemove(_nextNode!.id);
      _nextNode = null;
    }

    if (_activeIndex < 0 || _activeIndex >= _lines.length) return;

    if (!_currentClearedByPass && _currentNode == null) {
      _currentNode = _createNode(index: _activeIndex, distance: 5.0);
    }
    if (_nextNode == null && _activeIndex + 1 < _lines.length) {
      _nextNode = _createNode(index: _activeIndex + 1, distance: 10.0);
    }
  }

  _ArLyricNode? _createNode({required int index, required double distance}) {
    if (_arkit == null) return null;
    final text = _lines[index].text;
    if (text.trim().isEmpty) return null;

    final pos = _calcWorldPosition(distance, index);
    final id = 'lyric_${index}_${distance.toStringAsFixed(0)}';
    _addTextNodeAt(id, index, pos, _fadeInSteps.first);
    _fadeInNode(id, index, distance);
    _updateForwardDistance(id, pos);

    return _ArLyricNode(id: id, index: index, distance: distance, position: pos);
  }

  void _addTextNodeAt(String name, int index, vec.Vector3 pos, double opacity) {
    if (_arkit == null || index < 0 || index >= _lines.length) return;
    final text = _lines[index].text;
    if (text.trim().isEmpty) return;

    final node = ARKitNode(
      name: name,
      geometry: ARKitText(
        text: text,
        extrusionDepth: 0.08,
        materials: [_buildMaterial(opacity)],
      ),
      position: pos,
      scale: vec.Vector3.all(0.048),
      // 面向相机（billboard 效果）：Y 轴旋转 = 相机 yaw
      eulerAngles: vec.Vector3(0, _camYaw, 0),
    );
    _arkit?.add(node);
  }

  // ─────────────────── 材质（发光 + 淡入淡出）───────────

  ARKitMaterial _buildMaterial(double opacity) {
    final t = opacity.clamp(0.0, 1.0);
    return ARKitMaterial(
      // 白色漫反射，接受场景光照
      diffuse: ARKitMaterialProperty.color(Colors.white),
      // 青蓝色自发光（发光效果的关键！）
      // ★ 必须使用 lambert/blinn，constant 模式会忽略 emission ★
      emission: ARKitMaterialProperty.color(const Color(0xFF88FFFF)),
      lightingModelName: ARKitLightingModel.lambert,
      doubleSided: true,
      // 整体透明度控制淡入淡出
      transparency: t,
    );
  }

  // ─────────────────── 淡入淡出动画 ─────────────────────

  Future<void> _fadeInNode(String id, int index, double distance) async {
    _fadingNodeIds.add(id);
    for (final opacity in _fadeInSteps) {
      if (!mounted || _arkit == null) break;
      if (!_isNodeActive(id)) break;
      _safeRemove(id);
      // 每帧使用最新相机位置，保证淡入过程中歌词跟随
      final pos = _calcWorldPosition(distance, index);
      _addTextNodeAt(id, index, pos, opacity);
      // 更新存储的位置
      if (_currentNode?.id == id) {
        _currentNode = _ArLyricNode(
            id: id, index: index, distance: distance, position: pos);
      } else if (_nextNode?.id == id) {
        _nextNode = _ArLyricNode(
            id: id, index: index, distance: distance, position: pos);
      }
      await Future.delayed(_fadeStep);
    }
    _fadingNodeIds.remove(id);
  }

  Future<void> _fadeOutNode(_ArLyricNode node) async {
    _fadingNodeIds.add(node.id);
    for (final opacity in _fadeOutSteps) {
      if (!mounted || _arkit == null) break;
      try {
        _safeRemove(node.id);
        if (opacity > 0) {
          _addTextNodeAt(node.id, node.index, node.position, opacity);
        }
      } catch (_) {}
      await Future.delayed(_fadeStep);
    }
    _fadingNodeIds.remove(node.id);
  }

  bool _isNodeActive(String id) =>
      _currentNode?.id == id || _nextNode?.id == id;

  // ─────────────────── 穿越检测 ──────────────────────────

  void _checkPassThrough() {
    if (_arkit == null || !_cameraReady || _currentNode == null) return;
    if (_activeIndex < _currentNode!.index) return;

    final n = _currentNode!;
    final forwardX = -math.sin(_camYaw);
    final forwardZ = -math.cos(_camYaw);
    final dx = n.position.x - _camX;
    final dy = n.position.y - _camY;
    final dz = n.position.z - _camZ;
    final forwardDist = dx * forwardX + dz * forwardZ;
    final lateralDist = (dx * -forwardZ + dz * forwardX).abs();
    final lastForward = _lastForwardDist[n.id] ?? 999;

    if (lateralDist > 0.8 || dy.abs() > 1.2) return;
    if (lastForward > 0.4 && forwardDist <= 0.05) {
      _lastForwardDist[n.id] = forwardDist;
    } else {
      _lastForwardDist[n.id] = forwardDist;
      return;
    }

    final fading = _currentNode;
    _currentNode = null;
    _currentClearedByPass = true;
    if (fading != null) {
      Future.delayed(const Duration(seconds: 2), () {
        if (mounted && _arkit != null) _fadeOutNode(fading);
      });
    }
  }

  // ─────────────────── 清理 ─────────────────────────────

  void _safeRemove(String name) {
    try { _arkit?.remove(name); } catch (_) {}
  }

  void _removeAllNodes() {
    if (_currentNode != null) _safeRemove(_currentNode!.id);
    if (_nextNode != null) _safeRemove(_nextNode!.id);
    _currentNode = null;
    _nextNode = null;
  }

  void _fadeOutAllNodes() {
    final c = _currentNode, n = _nextNode;
    _currentNode = null;
    _nextNode = null;
    if (c != null) _fadeOutNode(c);
    if (n != null) _fadeOutNode(n);
  }

  // ─────────────────── 构建 ─────────────────────────────

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(
        fit: StackFit.expand,
        children: [
          ARKitSceneView(
            onARKitViewCreated: _onARKitViewCreated,
            // 开启水平面检测，辅助 AR 追踪精度
            planeDetection: ARPlaneDetection.horizontal,
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
                      style: const TextStyle(
                          color: Colors.white, fontWeight: FontWeight.w700),
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
                  width: 22, height: 22,
                  child: CircularProgressIndicator(
                      strokeWidth: 2, color: Colors.white70),
                ),
              ),
            ),
          if (_error != null)
            Align(
              alignment: Alignment.bottomCenter,
              child: Padding(
                padding: const EdgeInsets.only(bottom: 24),
                child: Text(_error!,
                    style: const TextStyle(
                        color: Colors.redAccent, fontWeight: FontWeight.w600)),
              ),
            ),
        ],
      ),
    );
  }
}

// ───────────────────── 数据模型 ─────────────────────────

class _ArLyricNode {
  const _ArLyricNode({
    required this.id,
    required this.index,
    required this.distance,
    required this.position,
  });

  final String id;
  final int index;
  final double distance; // 距相机的距离（5.0 或 10.0）
  final vec.Vector3 position;
}

class _LyricLine {
  const _LyricLine(this.ms, this.text);
  final int ms;
  final String text;
}

// ───────────────────── LRC 解析 ─────────────────────────

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
      lines.add(_LyricLine((mm * 60 + ss) * 1000 + ms, text));
    }
  }
  lines.sort((a, b) => a.ms.compareTo(b.ms));
  return lines;
}

int _findActiveIndex(List<_LyricLine> lines, int ms) {
  var lo = 0, hi = lines.length - 1, ans = 0;
  while (lo <= hi) {
    final mid = (lo + hi) >> 1;
    if (lines[mid].ms <= ms) { ans = mid; lo = mid + 1; } else { hi = mid - 1; }
  }
  return ans;
}
