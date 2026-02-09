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
  // 重构：使用矩阵直接作为锚点，不再手动计算 Euler 角
  // _anchorMatrix 存储了点击重置时刻的相机【位置】和【旋转】
  // 歌词将基于这个矩阵的局部坐标系生成 (0, 0, -dist)
  vec.Matrix4? _anchorMatrix;
  bool _anchorSet = false;

  static const _flowSpeed = 0.8; // 稍微调慢一点，让字飘得稳一点
  static const _baseDistance = 0.5; // 距离镜头最近的距离（米）

  static const _windowPastSec = 2.5;
  static const _windowFutureSec = 10.0;
  static const _passZTrigger = 0.08;
  static const _floatAmp = 0.0;
  static const _centerYOffset = 0.0;
  bool _cameraReady = false;
  
  // ── 调试模式 ──
  bool _debugMode = false;
  vec.Vector3 _debugCamPos = vec.Vector3.zero(); // 相机相对于锚点的位置

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
      // 直接获取相机 4x4 矩阵，包含所有位置和旋转信息
      final transform = await _arkit!.getCameraNodeTransform();
      
      if (transform == null) return;
      
      final wasReady = _cameraReady;
      _cameraReady = true;

      // 如果尚未设置锚点，则以当前相机矩阵作为锚点
      // 这意味着歌词轨道将完美对齐当前的视线方向
      if (!_anchorSet && transform != null) {
        _anchorMatrix = transform;
        _anchorSet = true;
        _updateDebugNodes(); // 锚点设定时更新调试节点
      }

      if (_debugMode && _anchorMatrix != null) {
         // 计算相机相对于锚点的位移 (Debug Display)
         // World = Anchor * Local -> Local = AnchorInverse * World
         final invAnchor = vec.Matrix4.copy(_anchorMatrix!)..invert();
         final localCamPos = vec.Vector3(
           transform.getColumn(3).x,
           transform.getColumn(3).y,
           transform.getColumn(3).z
         ); 
         // 上面的代码有误，矩阵乘法不需要手动提取列，transformed3 直接做坐标转换
         // 但 transformed3 的实现是 (M * v)，我们需要的是 (InverseM * CamWorld)
         _debugCamPos = invAnchor.transformed3(localCamPos);

         if (mounted) {
            setState(() {}); // 刷新界面文字
         }
      }

      if (!wasReady) {
        _refreshNodes();
        return;
      }
      _updateNodesForTime();
    } catch (_) {
    } finally {
      _trackingInFlight = false;
    }
  }

  // ─────────────────── 坐标计算 (Matrix Based) ────────────────

  // 计算世界坐标：将局部坐标 (0, 0, -z) 转换到锚点世界空间
  vec.Vector3 _calcWorldPosition(double zLocal) {
    if (_anchorMatrix == null) return vec.Vector3.zero();
    
    // ARKit右手坐标系：-Z 是前方
    final localPos = vec.Vector3(0, 0, -zLocal);
    
    // 应用矩阵变换：World = M * Local
    final worldPos = _anchorMatrix!.transformed3(localPos);
    
    return worldPos;
  }
  
  // 获取锚点的旋转，确保文字正对（或垂直于）锚点方向
  vec.Vector3 _getAnchorEulerAngles() {
     if (_anchorMatrix == null) return vec.Vector3.zero();
     // 从矩阵提取欧拉角
     // 注意：Matrix4.getRotation() 返回的是 Rotation Matrix，需要转 Euler
     // 这里我们简单起见，直接复用矩阵的方向，或者使用 lookAt
     // 但最简单的是：我们在生成 Node 时，直接设置 rotation = anchorMatrix.rotation
     // 不过 ARKitNode 接受 eulerAngles 或 rotation (Vector4 quaternion)
     // 为防万一，我们暂不设置 eulerAngles，而是直接让 Node 继承锚点的朝向
     // 但 ARKitNode 没有 direct matrix 属性。
     // 我们用一个 Trick: 提取矩阵的 Rotation 部分
     
     // 简化方案：我们只用 Position 确定位置，Angle 保持与 Anchor 一致
     // 这里手动解算一下 yaw/pitch/roll 比较麻烦，
     // 不如直接在 spawn 时只传 position，然后让文字产生的平面垂直于视线?
     // 为了确保文字是“正立”的，我们假设 _anchorMatrix 的 Up 向量大概是 (0,1,0)
     // 但如果是躺着看，Up 向量可能是 (0,0,1)。
     // 所以：文字的旋转应该 = _anchorMatrix 的旋转。
     
     // 为了代码简单，我们暂时返回与初始相机一致的欧拉角不太容易
     // 更好的做法是：让 Node 的 rotation 属性使用 _anchorMatrix 的旋转分量
     return vec.Vector3.zero(); 
  }

  double _zForLine(int index, int currentMs) {
    final dt = (_lines[index].ms - currentMs) / 1000.0;
    // 距离 = 基础距离 + 时间差 * 速度
    // dt > 0 (这种歌词在未来)，应该在远处 (zLocal > base)
    // dt < 0 (歌词已过去)，应该在背后 (zLocal < base)
    return _baseDistance + dt * _flowSpeed;
  }

  double _alphaForZ(double zLocal, bool isCurrent) {
    if (zLocal < 0.2) {
      // 距离非常近（穿过身体时）淡出
      return (zLocal / 0.2).clamp(0.0, 1.0);
    }
    // 基础透明度
    var a = 0.8; 
    
    // 如果是当前行，更亮一点
    if (isCurrent) a = 1.0;
    
    // 远处淡出（超过5米开始变淡）
    if (zLocal > 5.0) {
      final f = 1.0 - ((zLocal - 5.0) / 5.0); // 5m->10m 线性淡出
      a *= f.clamp(0.0, 1.0);
    }
    return a;
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
      final pos = _calcWorldPosition(zLocal);
      final alpha = _alphaForZ(zLocal, i == _activeIndex); // 重新使用之前的透明度逻辑
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
    if (_arkit == null || index < 0 || index >= _lines.length || _anchorMatrix == null) return;
    final text = _lines[index].text;
    if (text.trim().isEmpty) return;
    
    // 提取锚点矩阵的旋转部分 (Vector4 Quaternion)
    // 这样文字的朝向就和锚点（初始相机）完全一致，看起来就是正对着你的
    final rotation = vec.Quaternion.fromRotation(_anchorMatrix!.getRotation());

    final node = ARKitNode(
      name: name,
      geometry: ARKitText(
        text: text,
        extrusionDepth: 0.05, // 稍微薄一点
        materials: [_buildMaterial(opacity)],
      ),
      position: pos,
      rotation: vec.Vector4(rotation.x, rotation.y, rotation.z, rotation.w),
      scale: vec.Vector3.all(0.02), // 调整Scale，0.1可能太大，0.02试试（ARKit通常1=1米）
      // 之前是 0.048，用户觉得远小。
      // 如果我们把 baseDistance 拉近（0.5米），其实 0.02 看起来会正好。
      // 先用 0.06 试试（稍大）
    );
    // 强制修正一下 scale，文字大小直接影响体验
    node.scale = vec.Vector3.all(0.08); 

    _arkit?.add(node);
  }

  // ─────────────────── 材质（高亮白）───────────

  ARKitMaterial _buildMaterial(double opacity) {
    // 回归高对比度设计：纯白+发光
    return ARKitMaterial(
      diffuse: ARKitMaterialProperty.color(Colors.white.withOpacity(opacity)),
      // 稍微加一点点发光防止阴影全黑，但不要太亮影响轮廓
      emission: ARKitMaterialProperty.color(Colors.white.withOpacity(0.2)),
      lightingModelName: ARKitLightingModel.constant, // Constant 保证亮度一致
      transparency: opacity,
      doubleSided: true,
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
      // zLocal 是距离相机的距离。如果 < -1.0 表示已经跑到相机后面 1 米了，删除。
      if (zLocal < -1.0) {
        toRemove.add(index);
        return;
      }
      
      // 不再做复杂的 "PassThrough" 淡出，简单点，离得太近就淡出
      // 假设 baseDistance = 0.5。当 zLocal < 0.2 时开始变透明
      

      final pos = _calcWorldPosition(zLocal);
      final alpha = _alphaForZ(zLocal, index == _activeIndex); 
      // 重写 alpha 逻辑：当前行高亮，其他稍微淡一点
      // 或者：距离越远越淡，距离 < 0.2 也淡
      
      final needsAlphaUpdate = (alpha - node.lastAlpha).abs() > 0.04;
      final posChanged = (node.position - pos).length > 0.005;

      if (posChanged || needsAlphaUpdate) {
        _arkit?.update(
          node.id,
          node: ARKitNode(
             position: pos,
             // 旋转始终保持与锚点一致，不需要更新 update euler
             // 只要 add 的时候设置对了 rotation，这里不传就会保持原样吗？
             // 为了保险，我们不传 rotation/eulerAngles，假设它不动
          ),
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
  
  void _updateDebugNodes() {
    if (!_debugMode || _arkit == null || !_anchorSet) {
       // 如果关闭调试，移除调试节点
       _safeRemove('debug_origin');
       _safeRemove('debug_axis_z');
       return;
    }
    
    // 1. 在锚点原点 (0,0,0) 放置红色方块
    final originPos = _calcWorldPosition(0); 
    _arkit?.add(ARKitNode(
      name: 'debug_origin',
      geometry: ARKitBox(width: 0.1, height: 0.1, length: 0.1, materials: [
        ARKitMaterial(diffuse: ARKitMaterialProperty.color(Colors.red))
      ]),
      position: originPos,
    ));
    
    // 2. 也是在 z=0.5 (Base Distance) 放一个绿色球，指示歌词起始点
    final startPos = _calcWorldPosition(_baseDistance);
    _arkit?.add(ARKitNode(
      name: 'debug_start',
      geometry: ARKitSphere(radius: 0.03, materials: [
        ARKitMaterial(diffuse: ARKitMaterialProperty.color(Colors.green))
      ]),
      position: startPos,
    ));

    // 3. 绘制 Z 轴轨道（蓝色细长圆柱体）从 0 到 10米
    // 圆柱体默认中心在原点，所以需要位移到 5米处
    // Cylinders are aligned along Y axis by default in standard 3D usually, 
    // but in ARKit geometry simple primitives... let's just use spheres to mark path
    // Or just a box stretched.
    // 简单起见，每隔一米放一个小蓝点
    for(var z=1.0; z<=10.0; z+=1.0) {
       _arkit?.add(ARKitNode(
         name: 'debug_path_$z',
         geometry: ARKitSphere(radius: 0.01, materials: [
            ARKitMaterial(diffuse: ARKitMaterialProperty.color(Colors.blueAccent))
         ]),
         position: _calcWorldPosition(z),
       ));
    }
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
      // 清除调试节点以便重建
      try {
        _arkit?.remove('debug_origin'); 
        _arkit?.remove('debug_start');
        for(var z=1.0; z<=10.0; z+=1.0) _arkit?.remove('debug_path_$z');
      } catch(_) {}
    });
    // 下一帧 _trackCamera 会自动重新设定锚点
  }
  
  void _toggleDebug() {
    setState(() {
      _debugMode = !_debugMode;
      _updateDebugNodes(); // 切换时立即刷新场景
    });
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
                    onPressed: _toggleDebug,
                    icon: Icon(_debugMode ? Icons.bug_report : Icons.bug_report_outlined),
                    color: _debugMode ? Colors.redAccent : Colors.white70,
                    tooltip: '调试模式',
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
          if (_debugMode)
            Positioned(
              left: 10, top: 100,
              child: Container(
                padding: const EdgeInsets.all(8),
                color: Colors.black54,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('DEBUG INFO', style: TextStyle(color: Colors.red, fontWeight: FontWeight.bold)),
                    Text('Anchor Set: $_anchorSet', style: const TextStyle(color: Colors.white)),
                    if (_anchorMatrix != null)
                      Text('Offset: (${_debugCamPos.x.toStringAsFixed(2)}, ${_debugCamPos.y.toStringAsFixed(2)}, ${_debugCamPos.z.toStringAsFixed(2)})', 
                        style: const TextStyle(color: Colors.yellowAccent)),
                    const Text('Red Box = Origin (0,0,0)', style: TextStyle(color: Colors.white70, fontSize: 10)),
                    const Text('Green Sphere = Start (0.5m)', style: TextStyle(color: Colors.white70, fontSize: 10)),
                    const Text('Blue Dots = Path (1m steps)', style: TextStyle(color: Colors.white70, fontSize: 10)),
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
