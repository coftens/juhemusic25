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
  double _lastFrameTime = 0;
  bool _trackingInFlight = false;
  int _lastPosMs = 0;
  DateTime? _lastPosUpdatedAt;

  // ── 歌曲 / 歌词状态 ──
  SearchItem? _item;
  String _lyricFor = '';
  List<_LyricLine> _lines = const [];
  int _activeIndex = 0;
  bool _loading = true;
  String? _error;

  // ── AR 场景节点 ──
  final Map<int, _ArLyricNode> _nodes = {};
  final _fadingNodeIds = <String>{}; // 正在淡入淡出的节点，跳过更新

  // ── 相机追踪 ──
  double _camX = 0, _camY = 0, _camZ = 0, _camYaw = 0, _camPitch = 0;
  // 锚点（固定世界坐标系参考系），实现“固定在一个位置”
  bool _anchorSet = false;
  vec.Vector3? _anchorPos;
  vec.Vector3? _anchorForward; // 初始视线方向
  double _anchorYaw = 0; // 初始偏航角（用于文字朝向）

  static const _focusDistance = 1.5;
  static const _flowSpeed = 1.2;
  static const _windowPastSec = 2.5;
  static const _windowFutureSec = 10.0;
  static const _farFadeStart = 6.0;
  static const _farFadeEnd = 14.0;
  static const _passZTrigger = 0.08;
  static const _floatAmp = 0.0;
  static const _centerYOffset = 0.0;
  bool _cameraReady = false;

  // ─────────────────── 生命周期 ───────────────────────────

  @override
  void initState() {
    super.initState();
    _item = _svc.current ?? widget.item;
    _loadLyricsForCurrent();

    _svc.addListener(_onPlayerChanged);
    _posSub = _svc.positionStream.listen(_onPosition);
  }

  @override
  void dispose() {
    _svc.removeListener(_onPlayerChanged);
    _posSub?.cancel();
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
      _nodes.clear();
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
    _lastPosMs = ms;
    _lastPosUpdatedAt = DateTime.now();

    final idx = _findActiveIndex(_lines, ms);
    if (idx != _activeIndex) {
      _activeIndex = idx;
    }
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
      _camPitch = angles.x;

      // 如果尚未设置锚点，则以当前相机位置和朝向作为固定的参考系
      if (!_anchorSet) {
        _anchorPos = vec.Vector3(_camX, _camY, _camZ);
        _anchorYaw = _camYaw;
        
        // 使用矩阵计算精确的前向向量，避免手动三角函数的坐标系混淆
        // 顺序：Yaw(Y) -> Pitch(X) -> Roll(Z) 是常见的相机旋转顺序
        final mat = vec.Matrix4.identity()
          ..rotateY(angles.y)
          ..rotateX(angles.x)
          ..rotateZ(angles.z);
          
        // 本地坐标系中，前向通常是 -Z (0, 0, -1)
        final localForward = vec.Vector3(0, 0, -1);
        _anchorForward = mat.transformed3(localForward).normalized();
        
        _anchorSet = true;
      }

      if (!wasReady) {
        _refreshNodes();
        return;
      }
      _updateNodesForTime();
    } catch (_) {
      // 忽略相机读取失败
    } finally {
      _trackingInFlight = false;
    }
  }

  // ─────────────────── 坐标计算 ──────────────────────────

  /// 根据相机位姿 + 歌词时间，计算世界坐标
  vec.Vector3 _calcWorldPosition(double zLocal, int index) {
    // 如果没有锚点，暂时返回零向量
    if (!_anchorSet || _anchorPos == null || _anchorForward == null) {
      return vec.Vector3.zero();
    }

    // 根据初始锁定的正方向和位置延伸，确保歌词隧道位于镜头正中心
    final base = _anchorPos! + _anchorForward! * zLocal;
    
    // 稍微调整Y轴偏移（可选），让文字看起来更自然地悬浮
    final floatY = math.sin(_lastFrameTime * 0.8 + index) * _floatAmp;
    return vec.Vector3(base.x, base.y + _centerYOffset + floatY, base.z);
  }

  int _currentSongMs() {
    if (_lastPosUpdatedAt == null) return _lastPosMs;
    final drift = DateTime.now().difference(_lastPosUpdatedAt!).inMilliseconds;
    return _lastPosMs + drift;
  }

  double _zForLine(int index, int currentMs) {
    final dt = (_lines[index].ms - currentMs) / 1000.0;
    return _focusDistance + dt * _flowSpeed;
  }

  double _alphaForZ(double zLocal, bool isCurrent) {
    var alpha = 1.0;
    if (zLocal <= _passZTrigger) {
      alpha = (zLocal / _passZTrigger).clamp(0.0, 1.0);
    } else if (zLocal > _farFadeStart) {
      alpha = (1.0 - (zLocal - _farFadeStart) / (_farFadeEnd - _farFadeStart))
          .clamp(0.0, 1.0);
    }
    if (isCurrent) {
      alpha = math.min(1.0, alpha + 0.12);
    }
    return alpha;
  }

  // ─────────────────── 节点管理 ──────────────────────────

  void _refreshNodes() {
    if (_arkit == null || !_cameraReady) return;
    if (_lines.isEmpty) { _fadeOutAllNodes(); return; }
    _syncVisibleNodes();
  }

  void _syncVisibleNodes() {
    if (_arkit == null || !_cameraReady) return;
    if (_lines.isEmpty) return;

    final currentMs = _currentSongMs();
    final minMs = currentMs - (_windowPastSec * 1000).round();
    final maxMs = currentMs + (_windowFutureSec * 1000).round();

    var start = _activeIndex;
    while (start > 0 && _lines[start - 1].ms >= minMs) {
      start--;
    }
    var end = _activeIndex;
    while (end + 1 < _lines.length && _lines[end + 1].ms <= maxMs) {
      end++;
    }

    final toRemove = <int>[];
    for (final i in _nodes.keys) {
      if (i < start || i > end) toRemove.add(i);
    }
    for (final i in toRemove) {
      _safeRemove(_nodes[i]!.id);
      _nodes.remove(i);
    }

    for (var i = start; i <= end; i++) {
      if (_nodes.containsKey(i)) continue;
      final text = _lines[i].text;
      if (text.trim().isEmpty) continue;
      final zLocal = _zForLine(i, currentMs);
      final pos = _calcWorldPosition(zLocal, i);
      final alpha = _alphaForZ(zLocal, i == _activeIndex);
      final id = 'lyric_${i}_${_lines[i].ms}';
      _addTextNodeAt(id, i, pos, alpha);
      _nodes[i] = _ArLyricNode(
        id: id,
        index: i,
        position: pos,
        lastAlpha: alpha,
        passThroughTriggered: false,
      );
    }
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
      // 固定朝向：Pitch=0保证文字垂直竖立，Yaw=_anchorYaw保证正对初始视角
      eulerAngles: vec.Vector3(0, _anchorYaw, 0),
    );
    _arkit?.add(node);
  }

  // ─────────────────── 材质（发光 + 淡入淡出）───────────

  ARKitMaterial _buildMaterial(double opacity) {
    // 材质优化：Cyberpunk 风格
    // 漫反射设为黑色，避免受环境光影响变成死白
    // 主要靠自发光，lightingModelName设为 constant 更省性能且更像UI
    return ARKitMaterial(
      diffuse: ARKitMaterialProperty.color(Colors.black),
      emission: ARKitMaterialProperty.color(
        const Color(0xFF00FFFF).withOpacity(opacity)
      ),
      lightingModelName: ARKitLightingModel.constant,
      doubleSided: true,
      transparency: opacity,
    );
  }

  // ─────────────────── 节点更新（时间-空间映射）──────────

  void _updateNodesForTime() {
    if (_arkit == null || !_cameraReady || _lines.isEmpty) return;

    final currentMs = _currentSongMs();
    _syncVisibleNodes();

    final toRemove = <int>[];
    _nodes.forEach((index, node) {
      if (_fadingNodeIds.contains(node.id)) return;

      final zLocal = _zForLine(index, currentMs);
      if (zLocal < -0.4) {
        toRemove.add(index);
        return;
      }

      if (!node.passThroughTriggered && zLocal <= _passZTrigger) {
        node.passThroughTriggered = true;
        _fadeOutNode(node);
        return;
      }

      final pos = _calcWorldPosition(zLocal, index);
      final alpha = _alphaForZ(zLocal, index == _activeIndex);
      final needsAlphaUpdate = (alpha - node.lastAlpha).abs() > 0.04;
      final posChanged = (node.position - pos).length > 0.005;

      if (posChanged || needsAlphaUpdate) {
        _arkit?.update(
          node.id,
          position: pos,
          // ⚠️ 性能优化：不要在 update 中重新传入 geometry/node，只更新属性
          materials: needsAlphaUpdate ? [_buildMaterial(alpha)] : null,
        );
        node.position = pos;
        if (needsAlphaUpdate) node.lastAlpha = alpha;
      }
    });

    for (final i in toRemove) {
      _safeRemove(_nodes[i]!.id);
      _nodes.remove(i);
    }
  }

  Future<void> _fadeOutNode(_ArLyricNode node) async {
    _fadingNodeIds.add(node.id);
    for (final opacity in _fadeOutSteps) {
      if (!mounted || _arkit == null) break;
      try {
        _arkit?.update(
          node.id,
          materials: [_buildMaterial(opacity)],
        );
      } catch (_) {}
      await Future.delayed(_fadeStep);
    }
    _safeRemove(node.id);
    _nodes.remove(node.index);
    _fadingNodeIds.remove(node.id);
  }

  // ─────────────────── 清理 ─────────────────────────────

  void _safeRemove(String name) {
    try { _arkit?.remove(name); } catch (_) {}
  }

  void _removeAllNodes() {
    for (final node in _nodes.values) {
      _safeRemove(node.id);
    }
    _nodes.clear();
  }

  void _fadeOutAllNodes() {
    for (final node in _nodes.values) {
      _fadeOutNode(node);
    }
    _nodes.clear();
  }

  void _recenter() {
    setState(() {
      _anchorSet = false;
      _removeAllNodes();
    });
    // 下一帧 _trackCamera 会自动重新设定锚点
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
                  IconButton(
                    onPressed: _recenter,
                    icon: const Icon(Icons.center_focus_weak),
                    color: Colors.white70,
                    tooltip: '重置位置',
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
  _ArLyricNode({
    required this.id,
    required this.index,
    required this.position,
    required this.lastAlpha,
    required this.passThroughTriggered,
  });

  final String id;
  final int index;
  vec.Vector3 position;
  double lastAlpha;
  bool passThroughTriggered;
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
