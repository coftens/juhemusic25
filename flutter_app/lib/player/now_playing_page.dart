import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../api/php_api_client.dart';
import '../audio/player_service.dart';
import '../together/together_service.dart';
import '../widgets/cached_cover_image.dart';
import 'ar_lyrics_page.dart';
import 'vinyl_player_page.dart';

class NowPlayingPage extends StatefulWidget {
  const NowPlayingPage({super.key, this.item});

  final SearchItem? item;

  static bool _isNavigating = false;

  static Future<void> push(BuildContext context, {SearchItem? item}) async {
    if (_isNavigating) return;
    _isNavigating = true;
    try {
      await Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => NowPlayingPage(item: item)),
      );
    } finally {
      // Small delay to prevent double-tap during pop transition
      await Future.delayed(const Duration(milliseconds: 300));
      _isNavigating = false;
    }
  }

  @override
  State<NowPlayingPage> createState() => _NowPlayingPageState();
}

class _NowPlayingPageState extends State<NowPlayingPage> {
  final _svc = PlayerService.instance;

  String _coverUrl = '';

  @override
  void initState() {
    super.initState();
    _coverUrl = widget.item?.coverUrl ?? '';
    TogetherService().messages.listen((msg) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(msg),
        behavior: SnackBarBehavior.floating,
        backgroundColor: Colors.redAccent,
      ));
    });
    WidgetsBinding.instance.addPostFrameCallback((_) => _maybeStart());
  }

  @override
  void dispose() {
    super.dispose();
  }

  Future<void> _maybeStart() async {
    final it = widget.item;
    if (it == null) return;
    if (_svc.current?.shareUrl == it.shareUrl) {
      return;
    }
    try {
      await _svc.playItem(it);
      if (!mounted) return;
      final nextCover = _svc.current?.coverUrl ?? '';
      if (nextCover.isNotEmpty) {
        setState(() => _coverUrl = nextCover);
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('播放失败: $e'),
          behavior: SnackBarBehavior.floating,
        ),
      );
    }
  }

  ImageProvider _coverProvider() {
    final url = _svc.current?.coverUrl ?? _coverUrl;
    if (url.isEmpty) {
      return MemoryImage(_transparentPng);
    }
    return cachedImageProvider(url);
  }

  Future<void> _togglePlay() async {
    if (TogetherService().isConnected && !TogetherService().isHost) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('跟着房主听歌中，无法操作哦 ~'), duration: Duration(milliseconds: 1000)),
      );
      return;
    }
    try {
      if (_svc.current == null && widget.item != null) {
        await _svc.playItem(widget.item!);
      } else {
        await _svc.toggle();
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('播放失败: $e'),
          behavior: SnackBarBehavior.floating,
        ),
      );
    }
  }

  Future<void> _switchQuality(String q) async {
    try {
      await _svc.setQuality(q);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('切换音质失败: $e'),
          behavior: SnackBarBehavior.floating,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final fallback = widget.item;
    final current = _svc.current ?? fallback;
    if (current == null) {
      return const Scaffold(backgroundColor: Color(0xFF141A16));
    }

    return AnimatedBuilder(
      animation: _svc,
      builder: (context, _) {
        final it = _svc.current ?? current;
        final dur = _svc.duration;
        final pos = _svc.position;
        return VinylPlayerPage(
          title: it.name,
          artist: it.artist,
          shareUrl: it.shareUrl,
          index: _svc.index,
          cover: _coverProvider(),
          playing: _svc.playing,
          position: pos,
          duration: dur == Duration.zero ? const Duration(minutes: 3, seconds: 31) : dur,
          selectedQuality: _svc.quality,
          availableQualities: _svc.qualities,
          qualitiesLoading: _svc.qualitiesLoading,
          playMode: _svc.playMode,
          favorite: _svc.isFavorite,
          onToggleFavorite: () => _svc.toggleFavoriteCurrent(),
          onTogglePlay: _togglePlay,
          onToggleMode: () {
            if (TogetherService().isConnected && !TogetherService().isHost) {
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('跟着房主听歌中，无法切换模式哦 ~'), duration: Duration(milliseconds: 1000)),
              );
              return;
            }
            final next = switch (_svc.playMode) {
              'sequence' => 'shuffle',
              'shuffle' => 'repeat_one',
              _ => 'sequence',
            };
            _svc.setPlayMode(next);
          },
          onPrev: () {
            if (TogetherService().isConnected && !TogetherService().isHost) {
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('跟着房主听歌中，无法切换哦 ~'), duration: Duration(milliseconds: 1000)),
              );
              return;
            }
            if (_svc.hasPrev) {
              _svc.prev();
            } else {
              _svc.seek(Duration.zero);
            }
          },
          onNext: () {
            if (TogetherService().isConnected && !TogetherService().isHost) {
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('跟着房主听歌中，无法切换哦 ~'), duration: Duration(milliseconds: 1000)),
              );
              return;
            }
            if (_svc.hasNext) {
              _svc.next();
            } else {
              final d = _svc.duration;
              if (d != Duration.zero) {
                _svc.seek(d);
              }
            }
          },
          onOpenQueue: () => _openQueue(context),
          onOpenArLyrics: Platform.isIOS
              ? () => ArLyricsPage.push(context, item: it)
              : null,
          onSeek: (d) {
            if (TogetherService().isConnected && !TogetherService().isHost) {
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('跟着房主听歌中，无法拖动进度哦 ~'), duration: Duration(milliseconds: 1000)),
              );
              return;
            }
            _svc.seek(d);
          },
          onSelectQuality: (q) => _switchQuality(q),
        );
      },
    );
  }


  void _openQueue(BuildContext context) {
    // Auto-scroll to current song
    // Assuming dense ListTile height is approx 48.0
    const itemHeight = 48.0;
    // Find visual index of current song
    var visualIndex = _svc.index;
    final order = _svc.effectiveQueue;
    final current = _svc.current;
    if (current != null) {
        visualIndex = order.indexOf(current);
        if (visualIndex < 0) visualIndex = 0;
    }
    final initialOffset = (visualIndex * itemHeight).clamp(0.0, double.infinity);
    final controller = ScrollController(initialScrollOffset: initialOffset);

    showModalBottomSheet(
      context: context,
      showDragHandle: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
      ),
      builder: (_) {
        final t = Theme.of(context).textTheme;
        return AnimatedBuilder(
          animation: _svc,
          builder: (context, __) {
            final items = _svc.effectiveQueue;
            return Padding(
              padding: const EdgeInsets.fromLTRB(14, 6, 14, 14),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Row(
                    children: [
                      Text('播放列表', style: t.titleLarge?.copyWith(fontWeight: FontWeight.w900)),
                      const Spacer(),
                      TextButton(
                        onPressed: items.isEmpty ? null : () => _svc.clearQueue(),
                        child: Text('清空', style: t.titleSmall?.copyWith(fontWeight: FontWeight.w800)),
                      ),
                      TextButton.icon(
                        onPressed: () {
                          final next = switch (_svc.playMode) {
                            'sequence' => 'shuffle',
                            'shuffle' => 'repeat_one',
                            _ => 'sequence',
                          };
                          _svc.setPlayMode(next);
                        },
                        icon: Icon(
                          switch (_svc.playMode) {
                            'shuffle' => Icons.shuffle_rounded,
                            'repeat_one' => Icons.repeat_one_rounded,
                            _ => Icons.repeat_rounded,
                          },
                        ),
                        label: Text(
                          switch (_svc.playMode) {
                            'shuffle' => '随机播放',
                            'repeat_one' => '单曲循环',
                            _ => '顺序播放',
                          },
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Flexible(
                    child: ListView.builder(
                      controller: controller,
                      itemExtent: itemHeight, // Fixed height for performance and accurate scrolling
                      shrinkWrap: true,
                      itemCount: items.length,
                      itemBuilder: (context, i) {
                        final it = items[i];
                        // If shuffle, items is ordered list.
                        // We need to check if this item corresponds to current playing item.
                        // effectiveQueue returns _queue mapped by _order.
                        // _svc.index is the original index of the current song.
                        // _svc.queue[_svc.index] is the current song.
                        // In shuffled list, we can just compare the item content or better yet:
                        // Compare 'i' with '_svc.orderPos' IF we are sure items == effectiveQueue.
                        
                        // BUT: items was captured in builder(context, __) -> final items = _svc.queue;
                        // wait, I need to change how 'items' is defined in the parent builder first.
                        // Let's assume I changed it there.
                        // If items is effectiveQueue, then 'i' corresponds to orderPos.
                        // So active check is: i == _svc.orderPos (but _orderPos is private/not exposed as getter matching this logic fully?)
                        // _svc.index is exposed. _svc.queue is exposed.
                        // Let's use value key or equality.
                        
                        final currentUrl = _svc.current?.shareUrl ?? '';
                        final isCurrent = it.shareUrl == currentUrl && currentUrl.isNotEmpty;

                        return ListTile(
                          dense: true,
                          contentPadding: EdgeInsets.zero,
                          title: Text(
                            '${it.name} - ${it.artist}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: t.titleMedium?.copyWith(
                              color: isCurrent ? const Color(0xFF2A9D8F) : Colors.black87,
                              fontWeight: isCurrent ? FontWeight.w900 : FontWeight.w700,
                            ),
                          ),
                          trailing: isCurrent
                              ? const Icon(Icons.equalizer_rounded, color: Color(0xFF2A9D8F))
                              : null,
                          onTap: () {
                            if (TogetherService().isConnected && !TogetherService().isHost) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                const SnackBar(content: Text('跟着房主听歌中，无法切歌哦 ~'), duration: Duration(milliseconds: 1000)),
                              );
                              return;
                            }
                            Navigator.of(context).pop();
                            // If items is effectiveQueue, i is orderPos
                            _svc.jumpToOrderPos(i);
                          },
                        );
                      },
                    ),
                  ),
                ],
              ),
            );
          },
        );
      },
    );
  }
}

final Uint8List _transparentPng = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMB/6XnZt0AAAAASUVORK5CYII=',
);
