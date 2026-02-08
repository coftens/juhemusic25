import 'dart:math' as math;
import 'dart:ui';
import 'dart:async';

import 'package:flutter/material.dart';

import '../api/php_api_client.dart';
import '../audio/player_service.dart';
import '../widgets/cached_cover_image.dart';
import 'widgets/marquee_text.dart';
import 'widgets/stylus_arm.dart';
import 'widgets/vinyl_disc.dart';
import '../together/together_service.dart';
import '../widgets/typewriter_text.dart';

import '../ui/dominant_color.dart';

import '../app/app_tabs.dart';
import '../home/home_page.dart';
import '../ui/home_drawer.dart';

class VinylPlayerPage extends StatefulWidget {
  const VinylPlayerPage({
    super.key,
    required this.title,
    required this.artist,
    required this.shareUrl,
    required this.index,
    required this.cover,
    required this.playing,
    required this.position,
    required this.duration,
    required this.selectedQuality,
    required this.availableQualities,
    required this.qualitiesLoading,
    required this.playMode,
    required this.favorite,
    required this.onToggleFavorite,
    required this.onTogglePlay,
    required this.onToggleMode,
    required this.onPrev,
    required this.onNext,
    required this.onOpenQueue,
    required this.onSeek,
    required this.onSelectQuality,
    this.onOpenArLyrics,
    this.onTapDisc,
  });

  final String title;
  final String artist;
  final String shareUrl;
  final int index;
  final ImageProvider cover;

  final bool playing;
  final Duration position;
  final Duration duration;

  // Keys: standard | exhigh | lossless
  final String selectedQuality;
  final Map<String, String> availableQualities;
  final bool qualitiesLoading;

  final String playMode;
  final bool favorite;
  final VoidCallback onToggleFavorite;

  final VoidCallback onTogglePlay;
  final VoidCallback onToggleMode;
  final VoidCallback onPrev;
  final VoidCallback onNext;
  final VoidCallback onOpenQueue;
  final ValueChanged<Duration> onSeek;
  final ValueChanged<String> onSelectQuality;
  final VoidCallback? onOpenArLyrics;
  final VoidCallback? onTapDisc;

  @override
  State<VinylPlayerPage> createState() => _VinylPlayerPageState();
}

class _VinylPlayerPageState extends State<VinylPlayerPage>
    with TickerProviderStateMixin {
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();
  late final AnimationController _disc;
  late final AnimationController _stylus;
  late PageController _pageCtrl;
  bool _isProgrammaticScroll = false;

  final _api = PhpApiClient();
  final _miniLyricScroll = ScrollController();
  final _mainLyricScroll = ScrollController();
  String _lyricFor = '';
  bool _lyricLoading = false;
  List<_LyricLine> _lyricLines = const [];
  int _lyricActive = 0;

  bool _showLyrics = false;
  Color? _dominantBg;

  void _setShowLyrics(bool v) {
    if (_showLyrics == v) return;
    setState(() => _showLyrics = v);
    _syncLyricScrollAfterBuild();
  }

  void _syncLyricScrollAfterBuild() {
    var tries = 0;
    void tick() {
      if (!mounted) return;
      tries++;
      _updateMiniLyricActive(jump: true);
      final okMain = !_showLyrics || _mainLyricScroll.hasClients;
      final okMini = _showLyrics || _miniLyricScroll.hasClients;
      if ((okMain && okMini) || tries >= 3) return;
      WidgetsBinding.instance.addPostFrameCallback((_) => tick());
    }

    WidgetsBinding.instance.addPostFrameCallback((_) => tick());
  }

  @override
  void initState() {
    super.initState();
    _pageCtrl = PageController(initialPage: widget.index);
    _disc = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 18),
    );
    _stylus = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 280),
    );

    _syncAnimations(first: true);

    WidgetsBinding.instance.addPostFrameCallback((_) {
      _loadMiniLyrics();
      _updateMiniLyricActive(jump: true);
    });
  }

  @override
  void didUpdateWidget(covariant VinylPlayerPage oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.index != oldWidget.index) {
      if (_pageCtrl.hasClients && _pageCtrl.page?.round() != widget.index) {
        _isProgrammaticScroll = true;
        _pageCtrl.jumpToPage(widget.index);
        _isProgrammaticScroll = false;
      }
    }
    if (oldWidget.playing != widget.playing) {
      _syncAnimations(first: false);
    }

    if (oldWidget.shareUrl != widget.shareUrl) {
      _lyricFor = '';
      _lyricLines = const [];
      _lyricActive = 0;
      _dominantBg = null;
      _loadMiniLyrics();
    }
    _updateMiniLyricActive();
  }

  void _syncAnimations({required bool first}) {
    if (widget.playing) {
      if (!_disc.isAnimating) {
        _disc.repeat();
      }
      _stylus.forward();
    } else {
      _disc.stop(canceled: false);
      _stylus.reverse();
      if (first) {
        _stylus.value = 0;
      }
    }
  }

  @override
  void dispose() {
    _pageCtrl.dispose();
    _disc.dispose();
    _stylus.dispose();
    _miniLyricScroll.dispose();
    _mainLyricScroll.dispose();
    super.dispose();
  }

  Future<void> _loadMiniLyrics() async {
    final url = widget.shareUrl;
    if (url.isEmpty) return;
    if (_lyricLoading) return;
    if (_lyricFor == url && _lyricLines.isNotEmpty) return;

    final currentItem = PlayerService.instance.current;
    if (currentItem != null && currentItem.shareUrl == url && currentItem.lyrics.isNotEmpty) {
      final lines = _parseLrc(currentItem.lyrics);
      setState(() {
        _lyricFor = url;
        _lyricLines = lines;
        _lyricActive = 0;
      });
      _syncLyricScrollAfterBuild();
      return;
    }

    _lyricLoading = true;
    if (mounted) setState(() {});
    try {
      final r = await _api.lyrics(url);
      final lines = _parseLrc(r.lyricLrc);
      if (!mounted) return;
      _lyricFor = url;
      _lyricLines = lines;
      _lyricActive = 0;
      _syncLyricScrollAfterBuild();
      setState(() {});
    } catch (_) {
      if (!mounted) return;
      _lyricFor = url;
      _lyricLines = const [];
      setState(() {});
    } finally {
      _lyricLoading = false;
      if (mounted) setState(() {});
    }
  }

  void _updateMiniLyricActive({bool jump = false}) {
    if (_lyricLines.isEmpty) return;
    final ms = widget.position.inMilliseconds;
    final idx = _findActiveIndex(_lyricLines, ms);
    if (idx == _lyricActive && !jump) return;
    _lyricActive = idx;

    if (_miniLyricScroll.hasClients) {
      const itemH = 26.0;
      final target = ((idx - 1).clamp(0, _lyricLines.length).toDouble() * itemH);
      if (jump) {
        _miniLyricScroll.jumpTo(target);
      } else {
        _miniLyricScroll.animateTo(
          target,
          duration: const Duration(milliseconds: 220),
          curve: Curves.easeOutCubic,
        );
      }
    }

    if (_mainLyricScroll.hasClients) {
      const itemH = 56.0;
      final target = ((idx - 4).clamp(0, _lyricLines.length).toDouble() * itemH);
      if (jump) {
        _mainLyricScroll.jumpTo(target);
      } else {
        _mainLyricScroll.animateTo(
          target,
          duration: const Duration(milliseconds: 260),
          curve: Curves.easeOutCubic,
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final media = MediaQuery.of(context);
    final w = media.size.width;
    final discSize = math.min(w * 0.76, 360.0);

    return Scaffold(
      key: _scaffoldKey,
      drawer: const HomeDrawer(),
      backgroundColor: const Color(0xFF141A16),
      body: Stack(
        fit: StackFit.expand,
        children: [
          _showLyrics
              ? DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topCenter,
                      end: Alignment.bottomCenter,
                      colors: [
                        (_dominantBg ?? const Color(0xFFE9E0FF)).withOpacity(0.92),
                        (_dominantBg ?? const Color(0xFFE9E0FF)).withOpacity(0.82),
                        const Color(0xFF141A16).withOpacity(0.55),
                      ],
                      stops: const [0.0, 0.62, 1.0],
                    ),
                  ),
                )
              : _Backdrop(cover: widget.cover),
          SafeArea(
            child: Column(
              children: [
                const SizedBox(height: 8),
                _TopRow(
                  onGoHome: () {
                    HomePage.tabRequest.value = 1;
                    AppTabs.go(0);
                    Navigator.of(context).popUntil((r) => r.isFirst);
                  },
                  onGoPlaylist: () {
                    HomePage.tabRequest.value = 2;
                    AppTabs.go(0);
                    Navigator.of(context).popUntil((r) => r.isFirst);
                  },
                  onGoSearch: () {
                    AppTabs.go(1);
                    Navigator.of(context).popUntil((r) => r.isFirst);
                  },
                  onOpenDrawer: () {
                    _scaffoldKey.currentState?.openDrawer();
                  },
                ),
                const SizedBox(height: 14),
                Expanded(
                  child: AnimatedSwitcher(
                    duration: const Duration(milliseconds: 240),
                    switchInCurve: Curves.easeOutCubic,
                    switchOutCurve: Curves.easeInCubic,
                    child: _showLyrics
                        ? GestureDetector(
                            key: const ValueKey('lyrics'),
                            onTap: () => _setShowLyrics(false),
                            child: _MainLyrics(
                              loading: _lyricLoading,
                              lines: _lyricLines,
                              activeIndex: _lyricActive,
                              controller: _mainLyricScroll,
                            ),
                          )
                        : Column(
                            key: const ValueKey('vinyl'),
                            children: [
                              SizedBox(
                                height: discSize + 20,
                                child: PageView.builder(
                                  physics: const _OnePageScrollPhysics(),
                                  controller: _pageCtrl,
                                  onPageChanged: (i) {
                                    if (_isProgrammaticScroll) return;
                                    PlayerService.instance.jumpTo(i);
                                  },
                                  itemCount: PlayerService.instance.queue.length,
                                  itemBuilder: (context, index) {
                                    final item = PlayerService.instance.queue[index];
                                    final isCurrent = index == widget.index;
                                    ImageProvider image;
                                    if (isCurrent) {
                                      image = widget.cover;
                                    } else if (item.coverUrl.isNotEmpty) {
                                      image = cachedImageProvider(item.coverUrl);
                                    } else {
                                      image = const AssetImage('');
                                    }

                                    return Center(
                                      child: SizedBox(
                                        width: discSize,
                                        height: discSize,
                                        child: Stack(
                                          clipBehavior: Clip.none,
                                          children: [
                                            Positioned.fill(
                                              child: Padding(
                                                padding: const EdgeInsets.all(8),
                                                child: GestureDetector(
                                                  onTap: () {
                                                    _setShowLyrics(true);
                                                    _ensureDominantBg();
                                                  },
                                                  child: isCurrent
                                                      ? RotationTransition(
                                                          turns: _disc,
                                                          child: VinylDisc(cover: image),
                                                        )
                                                      : VinylDisc(cover: image),
                                                ),
                                              ),
                                            ),
                                            if (isCurrent)
                                              Positioned(
                                                top: -discSize * 0.14,
                                                left: discSize * 0.32,
                                                child: AnimatedBuilder(
                                                  animation: _stylus,
                                                  builder: (context, _) {
                                                    return StylusArm(
                                                      progress: CurvedAnimation(
                                                        parent: _stylus,
                                                        curve: Curves.easeOutCubic,
                                                        reverseCurve: Curves.easeInCubic,
                                                      ),
                                                      size: discSize * 0.9,
                                                    );
                                                  },
                                                ),
                                              ),
                                          ],
                                        ),
                                      ),
                                    );
                                  },
                                ),
                              ),
                              const SizedBox(height: 10),
                              Expanded(
                                child: LayoutBuilder(
                                  builder: (context, c) {
                                    return _MiniLyrics(
                                      loading: _lyricLoading,
                                      lines: _lyricLines,
                                      activeIndex: _lyricActive,
                                      controller: _miniLyricScroll,
                                      height: c.maxHeight,
                                    );
                                  },
                                ),
                              ),
                            ],
                          ),
                  ),
                ),
                _MetaAndActions(
                  title: widget.title,
                  artist: widget.artist,
                  favorite: widget.favorite,
                  onToggleFavorite: widget.onToggleFavorite,
                  selectedQuality: widget.selectedQuality,
                  availableQualities: widget.availableQualities,
                  qualitiesLoading: widget.qualitiesLoading,
                  onSelectQuality: widget.onSelectQuality,
                  onOpenArLyrics: widget.onOpenArLyrics,
                ),
                const SizedBox(height: 6),
                _ProgressBar(
                  position: widget.position,
                  duration: widget.duration,
                  onSeek: widget.onSeek,
                  selectedQuality: widget.selectedQuality,
                ),
                const SizedBox(height: 24),
                _Controls(
                  playing: widget.playing,
                  playMode: widget.playMode,
                  onPrev: widget.onPrev,
                  onTogglePlay: widget.onTogglePlay,
                  onNext: widget.onNext,
                  onToggleMode: widget.onToggleMode,
                  onOpenQueue: widget.onOpenQueue,
                ),
                const SizedBox(height: 38),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _ensureDominantBg() async {
    if (_dominantBg != null) return;
    try {
      final c = await dominantColorFromImage(widget.cover);
      if (!mounted) return;
      _dominantBg = c;
      setState(() {});
    } catch (_) {
    }
  }
}

class _Backdrop extends StatelessWidget {
  const _Backdrop({required this.cover});

  final ImageProvider cover;

  @override
  Widget build(BuildContext context) {
    return Stack(
      fit: StackFit.expand,
      children: [
        Image(
          image: cover,
          fit: BoxFit.cover,
          color: const Color(0xFF0C100D).withOpacity(0.35),
          colorBlendMode: BlendMode.darken,
        ),
        ImageFiltered(
          imageFilter: ImageFilter.blur(sigmaX: 28, sigmaY: 28),
          child: Container(color: Colors.transparent),
        ),
        DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [
                const Color(0xFF101612).withOpacity(0.72),
                const Color(0xFF101612).withOpacity(0.45),
                const Color(0xFF0C100D).withOpacity(0.92),
              ],
              stops: const [0.0, 0.55, 1.0],
            ),
          ),
        ),
      ],
    );
  }
}

class _TopRow extends StatelessWidget {
  const _TopRow({
    required this.onGoHome, 
    required this.onGoSearch, 
    required this.onGoPlaylist,
    required this.onOpenDrawer,
  });

  final VoidCallback onGoHome;
  final VoidCallback onGoSearch;
  final VoidCallback onGoPlaylist;
  final VoidCallback onOpenDrawer;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    
    return ValueListenableBuilder(
      valueListenable: TogetherService().statusNotifier,
      builder: (context, status, _) {
        final isTogether = TogetherService().isConnected;
        final isHost = TogetherService().isHost;
        
        return Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
               Row(
                children: [
                  IconButton(
                    onPressed: onOpenDrawer,
                    icon: const Icon(Icons.menu_rounded),
                    color: Colors.white70,
                  ),
                  const SizedBox(width: 2),
                  Expanded(
                    child: SingleChildScrollView(
                      scrollDirection: Axis.horizontal,
                      child: Row(
                        children: [
                          if (isTogether) ...[
                             Container(
                               padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                               margin: const EdgeInsets.only(right: 12),
                               decoration: BoxDecoration(
                                 color: const Color(0xFFE04A3A),
                                 borderRadius: BorderRadius.circular(20),
                               ),
                               child: Row(
                                 mainAxisSize: MainAxisSize.min,
                                 children: [
                                   const Icon(Icons.headphones_rounded, color: Colors.white, size: 12),
                                   const SizedBox(width: 4),
                                   Text(
                                     isHost ? '房主' : '听客',
                                     style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
                                   ),
                                 ],
                               ),
                             ),
                          ],
                          _TopTab(label: '心动', active: true, text: text, onTap: null),
                          _TopTab(label: '推荐', active: false, text: text, onTap: onGoHome),
                          _TopTab(label: '歌单', active: false, text: text, onTap: onGoPlaylist),
                          _TopTab(label: '播客', active: false, text: text, onTap: null),
                          _TopTab(label: '听书', active: false, text: text, onTap: null),
                          _TopTab(label: '午夜飞行', active: false, text: text, onTap: null),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(width: 6),
                  if (isTogether)
                     IconButton(
                        onPressed: () {
                           showDialog(
                             context: context,
                             builder: (ctx) => AlertDialog(
                               title: const Text('一起听 (Listen Together)'),
                               content: Column(
                                 mainAxisSize: MainAxisSize.min,
                                 children: [
                                    ListTile(
                                      leading: const Icon(Icons.exit_to_app, color: Colors.red),
                                      title: const Text('退出房间'),
                                      onTap: () {
                                         TogetherService().leaveRoom();
                                         Navigator.pop(ctx);
                                      },
                                    ),
                                    if (!isHost)
                                      ListTile(
                                        leading: const Icon(Icons.sync, color: Colors.blue),
                                        title: const Text('同步进度'),
                                        onTap: () {
                                           TogetherService().requestSync();
                                           Navigator.pop(ctx);
                                        },
                                      ),
                                 ],
                               ),
                             ),
                           );
                        },
                        icon: const Icon(Icons.group_work_rounded, color: Color(0xFFE04A3A)),
                     )
                  else
                    IconButton(
                      onPressed: onGoSearch,
                      icon: const Icon(Icons.search_rounded),
                      color: Colors.white70,
                    ),
                ],
              ),
              if (isTogether)
                Padding(
                  padding: const EdgeInsets.only(top: 4, bottom: 2),
                  child: TypewriterText(
                    '当前房间: ${TogetherService().roomId ?? "未知"} · ${isHost ? "您控制播放" : "跟随房主"}',
                    style: TextStyle(color: Colors.white.withOpacity(0.5), fontSize: 10),
                     duration: const Duration(milliseconds: 50),
                  ),
                ),
            ],
          ),
        );
      }
    );
  }
}

class _TopTab extends StatelessWidget {
  const _TopTab({required this.label, required this.active, required this.text, required this.onTap});

  final String label;
  final bool active;
  final TextTheme text;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final child = Padding(
      padding: const EdgeInsets.only(right: 16),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            label,
            style: text.titleMedium?.copyWith(
              color: active ? Colors.white : Colors.white60,
              fontWeight: active ? FontWeight.w700 : FontWeight.w500,
            ),
          ),
          const SizedBox(height: 6),
          AnimatedContainer(
            duration: const Duration(milliseconds: 180),
            width: active ? 22 : 0,
            height: 2,
            decoration: BoxDecoration(
              color: Colors.white.withOpacity(0.9),
              borderRadius: BorderRadius.circular(999),
            ),
          ),
        ],
      ),
    );
    if (onTap == null) return child;
    return InkWell(onTap: onTap, child: child);
  }
}

class _MetaAndActions extends StatelessWidget {
  const _MetaAndActions({
    required this.title,
    required this.artist,
    required this.favorite,
    required this.onToggleFavorite,
    required this.selectedQuality,
    required this.availableQualities,
    required this.qualitiesLoading,
    required this.onSelectQuality,
    required this.onOpenArLyrics,
  });

  final String title;
  final String artist;
  final bool favorite;
  final VoidCallback onToggleFavorite;

  final String selectedQuality;
  final Map<String, String> availableQualities;
  final bool qualitiesLoading;
  final ValueChanged<String> onSelectQuality;
  final VoidCallback? onOpenArLyrics;

  String _qualityLabel() {
    return switch (selectedQuality) {
      'jymaster' => '超清母带',
      'sky' => '沉浸环绕声',
      'jyeffect' => '高清环绕声',
      'hires' => 'Hi-Res音质',
      'master' => '臻品母带3.0',
      'atmos_2' => '臻品全景声2.0',
      'atmos_51' => '臻品音质2.0',
      'lossless' => 'SQ无损品质',
      'exhigh' => 'HQ高品质',
      'flac' => 'SQ无损品质',
      '320' => 'HQ高品质',
      'ogg_320' => 'OGG高品质',
      'aac_192' => 'AAC高品质',
      'ogg_192' => 'OGG标准',
      '128' => '标准音质',
      'standard' => '标准',
      'aac_96' => 'AAC标准',
      _ => '音质',
    };
  }

  void _openMenu(BuildContext context) {
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.transparent,
      barrierColor: Colors.black.withOpacity(0.35),
      showDragHandle: true,
      isScrollControlled: true,
      builder: (_) {
        final t = Theme.of(context).textTheme;
        return AnimatedBuilder(
          animation: PlayerService.instance,
          builder: (context, _) {
            final svc = PlayerService.instance;
            final loading = svc.qualitiesLoading;
            final currentQ = svc.quality;
            final avail = svc.qualities;

            const allQualities = <String>[
              'jymaster',
              'sky',
              'jyeffect',
              'hires',
              'atmos_51',
              'atmos_2',
              'master',
              'flac',
              'lossless',
              'exhigh',
              '320',
              'ogg_320',
              'aac_192',
              'ogg_192',
              '128',
              'standard',
              'aac_96',
            ];
            final filtered = allQualities.where((q) => avail.containsKey(q)).toList();
            
            return ClipRRect(
              borderRadius: const BorderRadius.vertical(top: Radius.circular(18)),
              child: BackdropFilter(
                filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
                child: Container(
                  color: Colors.white.withOpacity(0.72),
                  constraints: BoxConstraints(
                    maxHeight: MediaQuery.of(context).size.height * 0.85,
                  ),
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.fromLTRB(16, 6, 16, 18),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Text('音质', style: t.titleLarge?.copyWith(fontWeight: FontWeight.w900, color: Colors.black87)),
                            const Spacer(),
                            if (loading)
                              const SizedBox(
                                width: 16,
                                height: 16,
                                child: CircularProgressIndicator(strokeWidth: 2, color: Color(0xFFE04A3A)),
                              ),
                          ],
                        ),
                        const SizedBox(height: 6),
                        Text('当前：${_qualityLabel()}', style: t.bodyMedium?.copyWith(color: Colors.black54, fontWeight: FontWeight.w700)),
                        const SizedBox(height: 10),
                        if (filtered.isEmpty && !loading)
                           Padding(
                             padding: const EdgeInsets.all(16.0),
                             child: Text('暂无更多音质选项', style: t.bodyMedium?.copyWith(color: Colors.black45)),
                           ),
                        for (final q in filtered)
                          _QualityOption(
                            label: switch (q) {
                              'jymaster' => '超清母带',
                              'sky' => '沉浸环绕声',
                              'jyeffect' => '高清环绕声',
                              'hires' => 'Hi-Res音质',
                              'master' => '臻品母带级音质 (FLAC)',
                              'atmos_51' => '臻品音质2.0 (5.1声道)',
                              'atmos_2' => '臻品全景声2.0',
                              'lossless' => 'SQ无损品质',
                              'exhigh' => 'HQ高品质',
                              'flac' => 'SQ无损品质',
                              '320' => 'HQ高品质',
                              'ogg_320' => 'OGG高品质',
                              'aac_192' => 'AAC高品质',
                              'ogg_192' => 'OGG标准',
                              '128' => '标准音质',
                              'standard' => '标准',
                              'aac_96' => 'AAC标准',
                              _ => '标准',
                            },
                            desc: switch (q) {
                              'jymaster' => 'Mastering 顶级母带',
                              'sky' => '沉浸空间音效',
                              'jyeffect' => '超清 24bit 环绕',
                              'hires' => 'Hi-Res 高解析音频',
                              'master' => '母带级音质 (FLAC)',
                              'atmos_51' => '5.1 声道沉浸感',
                              'atmos_2' => '双声道全景声',
                              'lossless' => 'SQ 无损品质',
                              'exhigh' => 'HQ 极高品质',
                              'flac' => 'SQ 无损品质',
                              '320' => 'HQ 高品质 (320kbps)',
                              'ogg_320' => 'OGG 320kbps',
                              'aac_192' => 'AAC 192kbps',
                              'ogg_192' => 'OGG 192kbps',
                              '128' => '标准音质 (128kbps)',
                              'standard' => '标准 128kbps',
                              'aac_96' => 'AAC 96kbps',
                              _ => '标准 128kbps',
                            },
                            enabled: true,
                            selected: q == currentQ,
                            onTap: () {
                              Navigator.of(context).pop();
                              onSelectQuality(q);
                            },
                          ),
                      ],
                    ),
                  ),
                ),
              ),
            );
          },
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 18),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Flexible(
                      child: MarqueeText(
                        title,
                        style: (text.headlineSmall ?? const TextStyle(fontSize: 22)).copyWith(
                          color: Colors.white,
                          fontWeight: FontWeight.w800,
                          letterSpacing: 0.2,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Row(
                  children: [
                    Flexible(
                      child: Text(
                        artist,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: text.titleSmall?.copyWith(color: Colors.white60),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Row(
            children: [
              if (onOpenArLyrics != null)
                InkResponse(
                  onTap: onOpenArLyrics,
                  radius: 22,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      const Icon(Icons.view_in_ar_rounded, color: Colors.white70, size: 28),
                      const SizedBox(height: 2),
                      Text(
                        'AR歌词',
                        style: text.labelSmall?.copyWith(
                          color: Colors.white60,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                  ),
                ),
              if (onOpenArLyrics != null) const SizedBox(width: 8),
              _FavAction(
                active: favorite,
                onTap: onToggleFavorite,
              ),
              IconButton(
                onPressed: () => _openMenu(context),
                icon: const Icon(Icons.more_vert_rounded),
                color: Colors.white70,
              ),
            ],
          ),
        ],
      ),
    );
  }
}
class _ActionIcon extends StatelessWidget {
  const _ActionIcon({required this.icon, required this.color, required this.label});

  final IconData icon;
  final Color color;
  final String label;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Icon(icon, color: color, size: 30),
        const SizedBox(height: 2),
        Text(
          label,
          style: text.labelSmall?.copyWith(
            color: Colors.white60,
            fontWeight: FontWeight.w600,
          ),
        ),
      ],
    );
  }
}

class _FavAction extends StatelessWidget {
  const _FavAction({required this.active, required this.onTap});

  final bool active;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return _FavActionAnim(active: active, onTap: onTap);
  }
}

class _FavActionAnim extends StatefulWidget {
  const _FavActionAnim({required this.active, required this.onTap});

  final bool active;
  final VoidCallback onTap;

  @override
  State<_FavActionAnim> createState() => _FavActionAnimState();
}

class _FavActionAnimState extends State<_FavActionAnim> with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(vsync: this, duration: const Duration(milliseconds: 220));
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  Future<void> _bump() async {
    if (_ctrl.isAnimating) return;
    await _ctrl.forward(from: 0);
    if (!mounted) return;
    await _ctrl.reverse();
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final color = widget.active ? const Color(0xFFE63946) : Colors.white70;
    final scale = TweenSequence<double>([
      TweenSequenceItem(tween: Tween(begin: 1.0, end: 1.18).chain(CurveTween(curve: Curves.easeOutCubic)), weight: 60),
      TweenSequenceItem(tween: Tween(begin: 1.18, end: 1.0).chain(CurveTween(curve: Curves.easeInCubic)), weight: 40),
    ]).animate(_ctrl);

    return InkResponse(
      onTap: () {
        widget.onTap();
        _bump();
      },
      radius: 22,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          AnimatedBuilder(
            animation: _ctrl,
            builder: (context, _) {
              return Transform.scale(
                scale: scale.value,
                child: Icon(
                  widget.active ? Icons.favorite_rounded : Icons.favorite_border_rounded,
                  color: color,
                  size: 30,
                ),
              );
            },
          ),
          const SizedBox(height: 2),
          Text(
            widget.active ? '已喜欢' : '喜欢',
            style: text.labelSmall?.copyWith(
              color: Colors.white60,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }
}

class _ProgressBar extends StatefulWidget {
  const _ProgressBar({
    required this.position,
    required this.duration,
    required this.onSeek,
    required this.selectedQuality,
  });

  final Duration position;
  final Duration duration;
  final ValueChanged<Duration> onSeek;
  final String selectedQuality;

  @override
  State<_ProgressBar> createState() => _ProgressBarState();
}

class _ProgressBarState extends State<_ProgressBar> {
  bool _isDragging = false;
  double _dragValue = 0.0;

  String _qualityLabel() {
    return switch (widget.selectedQuality) {
      'jymaster' => '超清母带',
      'sky' => '沉浸',
      'jyeffect' => '环绕',
      'hires' => 'Hi-Res',
      'master' => '臻品母带3.0',
      'atmos_51' => '臻品音质2.0',
      'atmos_2' => '臻品全景声2.0',
      'lossless' => 'SQ无损品质',
      'exhigh' => 'HQ高品质',
      'flac' => 'SQ无损品质',
      '320' => 'HQ高品质',
      'ogg_320' => 'OGG高品质',
      'aac_192' => 'AAC高品质',
      'ogg_192' => 'OGG标准',
      '128' => '标准音质',
      'standard' => '标准',
      'aac_96' => 'AAC标准',
      _ => '音质',
    };
  }

  String _fmt(Duration d) {
    final m = d.inMinutes;
    final s = d.inSeconds % 60;
    return '${m.toString().padLeft(2, '0')}:${s.toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final max = math.max(1, widget.duration.inMilliseconds).toDouble();
    final pos = widget.position.inMilliseconds.toDouble().clamp(0.0, max);
    
    final displayValue = _isDragging ? _dragValue : pos;
    final displayTime = Duration(milliseconds: displayValue.round());

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 18),
      child: Column(
        children: [
          SliderTheme(
            data: SliderTheme.of(context).copyWith(
              trackHeight: 2.2,
              inactiveTrackColor: Colors.white24,
              activeTrackColor: Colors.white70,
              thumbColor: Colors.white,
              overlayColor: Colors.white10,
              thumbShape: const RoundSliderThumbShape(enabledThumbRadius: 6),
              overlayShape: const RoundSliderOverlayShape(overlayRadius: 14),
            ),
            child: Slider(
              value: displayValue.clamp(0.0, max),
              min: 0,
              max: max,
              onChangeStart: (v) {
                setState(() {
                  _isDragging = true;
                  _dragValue = v;
                });
              },
              onChanged: (v) {
                setState(() {
                  _dragValue = v;
                });
              },
              onChangeEnd: (v) {
                setState(() {
                  _isDragging = false;
                });
                widget.onSeek(Duration(milliseconds: v.round()));
              },
            ),
          ),
          Row(
            children: [
              Text(_fmt(displayTime), style: text.labelMedium?.copyWith(color: Colors.white54)),
              const Spacer(),
              Text(_qualityLabel(), style: text.labelLarge?.copyWith(color: Colors.white70, fontWeight: FontWeight.w600)),
              const Spacer(),
              Text(_fmt(widget.duration), style: text.labelMedium?.copyWith(color: Colors.white54)),
            ],
          ),
        ],
      ),
    );
  }
}

class _QualityOption extends StatelessWidget {
  const _QualityOption({
    required this.label,
    required this.desc,
    required this.enabled,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final String desc;
  final bool enabled;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    final canTap = enabled;
    final bg = selected
        ? const Color(0xFFFFE6E2).withOpacity(0.85)
        : enabled
            ? Colors.white.withOpacity(0.55)
            : Colors.white.withOpacity(0.30);
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Material(
        color: bg,
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: canTap ? onTap : null,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 12, 12, 12),
            child: Row(
              children: [
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    color: selected
                        ? const Color(0xFFE04A3A).withOpacity(0.12)
                        : enabled
                            ? Colors.black.withOpacity(0.06)
                            : Colors.black.withOpacity(0.03),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: selected ? const Color(0xFFE04A3A).withOpacity(0.18) : Colors.black.withOpacity(0.06)),
                  ),
                  child: Icon(
                    selected ? Icons.check_rounded : Icons.music_note_rounded,
                    color: selected
                        ? const Color(0xFFE04A3A)
                        : enabled
                            ? Colors.black45
                            : Colors.black26,
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        label,
                        style: t.titleMedium?.copyWith(
                          color: enabled ? Colors.black87 : Colors.black38,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        enabled ? desc : '当前歌曲不支持',
                        style: t.bodySmall?.copyWith(
                          color: enabled ? Colors.black54 : Colors.black38,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                Icon(
                  selected ? Icons.radio_button_checked_rounded : Icons.radio_button_off_rounded,
                  color: selected
                      ? const Color(0xFFE04A3A)
                      : enabled
                          ? Colors.black38
                          : Colors.black26,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Controls extends StatelessWidget {
  const _Controls({
    required this.playing,
    required this.playMode,
    required this.onPrev,
    required this.onTogglePlay,
    required this.onNext,
    required this.onToggleMode,
    required this.onOpenQueue,
  });

  final bool playing;
  final String playMode;
  final VoidCallback onPrev;
  final VoidCallback onTogglePlay;
  final VoidCallback onNext;
  final VoidCallback onToggleMode;
  final VoidCallback onOpenQueue;

  @override
  Widget build(BuildContext context) {
    IconData modeIcon;
    Color modeColor;
    switch (playMode) {
      case 'shuffle':
        modeIcon = Icons.shuffle_rounded;
        modeColor = const Color(0xFFE04A3A);
        break;
      case 'repeat_one':
        modeIcon = Icons.repeat_one_rounded;
        modeColor = const Color(0xFFE04A3A);
        break;
      case 'sequence':
      default:
        modeIcon = Icons.repeat_rounded;
        modeColor = Colors.white38;
        break;
    }

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 14),
      child: Stack(
        alignment: Alignment.center,
        children: [
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              IconButton(
                onPressed: onPrev,
                icon: const Icon(Icons.skip_previous_rounded),
                iconSize: 42,
                color: Colors.white60,
              ),
              const SizedBox(width: 14),
              GestureDetector(
                onTap: onTogglePlay,
                child: Container(
                  width: 74,
                  height: 74,
                  decoration: BoxDecoration(
                    color: Colors.white.withOpacity(0.06),
                    borderRadius: BorderRadius.circular(26),
                    border: Border.all(color: Colors.white12),
                  ),
                  child: Icon(
                    playing ? Icons.pause_rounded : Icons.play_arrow_rounded,
                    size: 44,
                    color: Colors.white70,
                  ),
                ),
              ),
              const SizedBox(width: 14),
              IconButton(
                onPressed: onNext,
                icon: const Icon(Icons.skip_next_rounded),
                iconSize: 42,
                color: Colors.white60,
              ),
            ],
          ),
          
          Align(
            alignment: Alignment.centerLeft,
            child: IconButton(
              onPressed: onToggleMode,
              icon: Icon(modeIcon),
              iconSize: 26,
              color: modeColor,
            ),
          ),

          Align(
            alignment: Alignment.centerRight,
            child: IconButton(
              onPressed: onOpenQueue,
              icon: const Icon(Icons.queue_music_rounded),
              iconSize: 26,
              color: Colors.white38,
            ),
          ),
        ],
      ),
    );
  }
}

class _MiniLyrics extends StatelessWidget {
  const _MiniLyrics({
    required this.loading,
    required this.lines,
    required this.activeIndex,
    required this.controller,
    required this.height,
  });

  final bool loading;
  final List<_LyricLine> lines;
  final int activeIndex;
  final ScrollController controller;
  final double height;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    if (loading) {
      return SizedBox(
        height: 26 * 3,
        child: Center(
          child: Text('歌词加载中…', style: t.bodyMedium?.copyWith(color: Colors.white54, fontWeight: FontWeight.w600)),
        ),
      );
    }
    if (lines.isEmpty) {
      return SizedBox(
        height: 26 * 3,
        child: Center(
          child: Text('暂无歌词', style: t.bodyMedium?.copyWith(color: Colors.white38, fontWeight: FontWeight.w600)),
        ),
      );
    }

    const itemH = 26.0;
    final visible = (height / itemH).floor().clamp(2, 8);
    final viewH = itemH * visible;
    return SizedBox(
      height: viewH,
      child: ClipRect(
        child: ListView.builder(
          controller: controller,
          physics: const NeverScrollableScrollPhysics(),
          itemExtent: itemH,
          itemCount: lines.length,
          itemBuilder: (context, i) {
            final isActive = i == activeIndex;
            final isNear = (i - activeIndex).abs() <= 1;
            final color = isActive
                ? Colors.white
                : isNear
                    ? Colors.white.withOpacity(0.55)
                    : Colors.white.withOpacity(0.28);
            return Align(
              alignment: Alignment.center,
              child: Text(
                lines[i].text,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                textAlign: TextAlign.center,
                style: t.titleMedium?.copyWith(
                  color: color,
                  fontWeight: isActive ? FontWeight.w900 : FontWeight.w600,
                  height: 1.0,
                ),
              ),
            );
          },
        ),
      ),
    );
  }
}

class _MainLyrics extends StatelessWidget {
  const _MainLyrics({
    required this.loading,
    required this.lines,
    required this.activeIndex,
    required this.controller,
  });

  final bool loading;
  final List<_LyricLine> lines;
  final int activeIndex;
  final ScrollController controller;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    if (loading) {
      return Center(
        child: Text('歌词加载中…', style: t.bodyLarge?.copyWith(color: Colors.white.withOpacity(0.75), fontWeight: FontWeight.w700)),
      );
    }
    if (lines.isEmpty) {
      return Center(
        child: Text('暂无歌词', style: t.bodyLarge?.copyWith(color: Colors.white.withOpacity(0.65), fontWeight: FontWeight.w700)),
      );
    }

    return Padding(
      padding: const EdgeInsets.fromLTRB(18, 14, 18, 14),
      child: ListView.builder(
        controller: controller,
        itemExtent: 56,
        itemCount: lines.length,
        itemBuilder: (context, i) {
          final active = i == activeIndex;
          final dist = (i - activeIndex).abs();
          final base = active
              ? Colors.white
              : dist <= 1
                  ? Colors.white.withOpacity(0.62)
                  : Colors.white.withOpacity(0.30);
          return Align(
            alignment: Alignment.center,
            child: Text(
              lines[i].text,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              textAlign: TextAlign.center,
              style: t.titleLarge?.copyWith(
                    color: base,
                    fontWeight: active ? FontWeight.w900 : FontWeight.w700,
                    height: 1.15,
                  ),
            ),
          );
        },
      ),
    );
  }
}

class _LyricLine {
  const _LyricLine(this.ms, this.text);

  final int ms;
  final String text;
}

final _timeRe = RegExp(r'\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]');

List<_LyricLine> _parseLrc(String input) {
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

class _OnePageScrollPhysics extends ScrollPhysics {
  const _OnePageScrollPhysics({super.parent});

  @override
  _OnePageScrollPhysics applyTo(ScrollPhysics? ancestor) {
    return _OnePageScrollPhysics(parent: buildParent(ancestor));
  }

  double _getPage(ScrollMetrics position) {
    return position.pixels / position.viewportDimension;
  }

  double _getPixels(ScrollMetrics position, double page) {
    return page * position.viewportDimension;
  }

  double _getTargetPixels(ScrollMetrics position, Tolerance tolerance, double velocity) {
    double page = _getPage(position);
    if (velocity < -tolerance.velocity) {
      page -= 0.5;
    } else if (velocity > tolerance.velocity) {
      page += 0.5;
    }
    return _getPixels(position, page.roundToDouble());
  }

  @override
  Simulation? createBallisticSimulation(ScrollMetrics position, double velocity) {
    if ((velocity <= 0.0 && position.pixels <= position.minScrollExtent) ||
        (velocity >= 0.0 && position.pixels >= position.maxScrollExtent)) {
      return super.createBallisticSimulation(position, velocity);
    }

    final tolerance = this.tolerance;
    final double target = _getTargetPixels(position, tolerance, velocity);
    
    if (target != position.pixels) {
       final double clampedVelocity = velocity.clamp(-8000.0, 8000.0);
       
      return ScrollSpringSimulation(
        SpringDescription.withDampingRatio(
          mass: 0.8,
          stiffness: 100.0,
          ratio: 1.1,
        ),
        position.pixels,
        target,
        clampedVelocity,
        tolerance: tolerance,
      );
    }
    return null;
  }

  @override
  bool get allowImplicitScrolling => false;
}
