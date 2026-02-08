import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';


import '../api/php_api_client.dart';
import '../app/app_tabs.dart';
import '../audio/player_service.dart';
import '../auth/auth_api.dart';
import '../auth/auth_session.dart';
import '../player/now_playing_page.dart';
import '../storage/user_library.dart';
import '../playlist/playlist_page.dart';
import '../widgets/cached_cover_image.dart';
import 'library_playlist_page.dart';
import '../search/search_page.dart';
import '../search/search_page_route.dart';
import 'settings_page.dart';


class MePage extends StatefulWidget {
  const MePage({super.key});

  @override
  State<MePage> createState() => _MePageState();
}

class _MePageState extends State<MePage> {
  final _lib = UserLibrary.instance;
  final _svc = PlayerService.instance;
  final _api = PhpApiClient();

  bool _loading = true;
  String? _err;
  List<SearchItem> _recents = const [];
  List<SearchItem> _favorites = const [];
  List<PlaylistInfo> _userPlaylists = const [];

  @override
  void initState() {
    super.initState();
    _load();
    AppTabs.index.addListener(_onTabChanged);
  }

  void _onTabChanged() {
    // Me page lives in an IndexedStack; refresh when the tab becomes visible.
    if (!mounted) return;
    if (AppTabs.index.value == 3) {
      _load();
    }
  }

  @override
  void dispose() {
    AppTabs.index.removeListener(_onTabChanged);
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _err = null;
    });
    try {
      final res = await Future.wait([
        _lib.getRecents(),
        _lib.getFavorites(),
        _lib.getPlaylists(),
      ]);
      if (!mounted) return;
      setState(() {
        _recents = res[0] as List<SearchItem>;
        _favorites = res[1] as List<SearchItem>;
        _userPlaylists = res[2] as List<PlaylistInfo>;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _err = e.toString();
      });
    } finally {
      if (!mounted) return;
      setState(() {
        _loading = false;
      });
    }
  }

  Future<void> _pickAvatar() async {
    final picker = ImagePicker();
    final img = await picker.pickImage(source: ImageSource.gallery, imageQuality: 80);
    if (img == null) return;

    /*
    final cropped = await ImageCropper().cropImage(
      sourcePath: img.path,
      compressQuality: 80,
      cropStyle: CropStyle.circle,
    );

    if (cropped == null) return;
    */
    
    // Fallback during debug
    final cropped = img;


    setState(() => _loading = true);
    try {
      final url = await _api.uploadAvatar(cropped.path);
      AuthSession.instance.updateAvatarUrl(url);
      
      // Force reload profile to reflect changes
      if (mounted) {
        // Clear cache if possible, or assume AuthSession update triggers rebuild
        setState(() {}); 
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('头像上传失败：$e')));
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _showMenu() {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
      builder: (context) {
        return SafeArea(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              ListTile(
                leading: const Icon(Icons.logout_rounded, color: Colors.redAccent),
                title: const Text('退出登录', style: TextStyle(color: Colors.redAccent, fontWeight: FontWeight.bold)),
                onTap: () {
                  Navigator.pop(context);
                  AuthApi.instance.logout();
                },
              ),
              const SizedBox(height: 8),
            ],
          ),
        );
      },
    );
  }


  void _playFrom(List<SearchItem> list, int index) {
    if (index < 0 || index >= list.length) return;
    _svc.setQueue(list, startIndex: index);
    NowPlayingPage.push(context, item: list[index]);
  }

  double _sheetRadius = 18.0;
  double _sheetExtent = 0.45; // Track sheet extent for profile scaling

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;

    return Scaffold(
      backgroundColor: Colors.transparent, // Make it transparent to show AppShell background
      body: Stack(
        children: [
          const _Backdrop(),
          SafeArea(
            child: Column(
              children: [
                // ... (Keep existing header code)
                Padding(
                  padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
                  child: Row(
                    children: [
                      IconButton(
                        onPressed: _showMenu,
                        icon: const Icon(Icons.menu_rounded),
                        color: Colors.white,
                      ),
                      const Spacer(),
                      IconButton(
                        onPressed: () {
                          Navigator.of(context).push(
                            SearchPageRoute(
                              child: SearchPage(onBackToHome: () => Navigator.of(context).pop()),
                            ),
                          );
                        },
                        icon: const Icon(Icons.search_rounded),
                        color: Colors.white,
                      ),
                      IconButton(
                        onPressed: _load,
                        icon: const Icon(Icons.refresh_rounded),
                        color: Colors.white,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 8),
                // Center the profile section in the remaining space above the sheet
                Expanded(
                  child: Align(
                    alignment: const Alignment(0, -0.2), // Bias slightly up to fit the "boxed" area
                    child: Builder(builder: (context) {
                      // t0: 0.0 (sheet at 0.38) -> 1.0 (sheet at 1.0)
                      final t0 = ((_sheetExtent - 0.38) / (1.0 - 0.38)).clamp(0.0, 1.0);
                      
                      // Component Specific Scaling
                      final avatarScale = (1.4 - t0 * 0.8).clamp(0.6, 1.4);
                      final textScale = (1.1 - t0 * 0.3).clamp(0.8, 1.1);
                      final buttonsOpacity = (1.0 - t0 * 1.5).clamp(0.0, 1.0);
                      final profileOpacity = (1.0 - (t0 - 0.7) / 0.3).clamp(0.0, 1.0); // Start fading out late

                      return Opacity(
                        opacity: profileOpacity,
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            // Avatar
                            Transform.scale(
                              scale: avatarScale,
                              child: GestureDetector(
                                onTap: _pickAvatar,
                                child: Container(
                                  width: 78,
                                  height: 78,
                                  decoration: BoxDecoration(
                                    color: Colors.white.withOpacity(0.95),
                                    shape: BoxShape.circle,
                                    border: Border.all(color: Colors.white.withOpacity(0.35), width: 2),
                                    boxShadow: [
                                      BoxShadow(color: Colors.black.withOpacity(0.1 * (1-t0)), blurRadius: 10, offset: const Offset(0, 4)),
                                    ],
                                  ),
                                  child: ClipOval(
                                    child: AuthSession.instance.user?.avatarUrl.isNotEmpty == true
                                        ? CachedCoverImage(
                                            imageUrl: AuthSession.instance.user!.avatarUrl,
                                            fit: BoxFit.cover,
                                          )
                                        : const Icon(Icons.person, size: 42, color: Colors.black54),
                                  ),
                                ),
                              ),
                            ),
                            const SizedBox(height: 16),
                            
                            // Username & Bio
                            Transform.scale(
                              scale: textScale,
                              child: Column(
                                children: [
                                  Text(
                                    AuthSession.instance.user?.username ?? 'deoth5',
                                    style: t.headlineSmall?.copyWith(color: Colors.white, fontWeight: FontWeight.w900, letterSpacing: 0.5),
                                  ),
                                  const SizedBox(height: 6),
                                  Text('最近 & 喜欢（云端同步已开启）', 
                                    style: t.bodyMedium?.copyWith(color: Colors.white70, fontWeight: FontWeight.w600)),
                                ],
                              ),
                            ),
                            const SizedBox(height: 20),

                            // Quick Buttons - Fade and slight slide, but NO overall scale to avoid edge clipping
                            Opacity(
                              opacity: buttonsOpacity,
                              child: Transform.translate(
                                offset: Offset(0, 10 * t0),
                                child: Padding(
                                  padding: const EdgeInsets.symmetric(horizontal: 16),
                                  child: Row(
                                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                    children: [
                                      _QuickBtn(icon: Icons.history_rounded, label: '最近', onTap: () {
                                        Navigator.of(context).push(
                                          MaterialPageRoute(builder: (_) => LibraryPlaylistPage(title: '最近播放', items: _recents)),
                                        );
                                      }),
                                      _QuickBtn(icon: Icons.favorite_rounded, label: '喜欢', onTap: () {
                                        Navigator.of(context).push(
                                          MaterialPageRoute(builder: (_) => LibraryPlaylistPage(title: '我喜欢的音乐', items: _favorites)),
                                        );
                                      }),
                                      _QuickBtn(icon: Icons.checkroom_rounded, label: '装扮', onTap: () {
                                        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('敬请期待')));
                                      }),
                                      _QuickBtn(icon: Icons.settings_rounded, label: '设置', onTap: () {
                                        Navigator.of(context).push(
                                          MaterialPageRoute(builder: (_) => const SettingsPage()),
                                        );
                                      }),
                                      _QuickBtn(icon: Icons.grid_view_rounded, label: '', onTap: () {}),
                                    ],
                                  ),
                                ),
                              ),
                            ),
                          ],
                        ),
                      );
                    }),
                  ),
                ),
                // Place a dummy spacer that the sheet will overlap
                SizedBox(height: MediaQuery.of(context).size.height * 0.38 - MediaQuery.of(context).padding.top - 80),
              ],
            ),
          ),

          NotificationListener<DraggableScrollableNotification>(
            onNotification: (notification) {
              final extent = notification.extent;
              // Animate radius to 0 when near top (e.g. > 0.95)
              final newRadius = extent > 0.95 ? 0.0 : 18.0;
              if (newRadius != _sheetRadius || extent != _sheetExtent) {
                setState(() {
                  _sheetRadius = newRadius;
                  _sheetExtent = extent;
                });
              }
              return true;
            },
            child: DraggableScrollableSheet(
              initialChildSize: 0.45,
              minChildSize: 0.38,
              maxChildSize: 1.0, // Allow full screen
              snap: true, // Enable snapping
              snapSizes: const [0.38, 0.45, 1.0], // Snap points
              builder: (context, scrollController) {
                return AnimatedContainer(
                  duration: const Duration(milliseconds: 200),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF2F3F4),
                    borderRadius: BorderRadius.only(
                      topLeft: Radius.circular(_sheetRadius),
                      topRight: Radius.circular(_sheetRadius),
                    ),
                    boxShadow: [
                      BoxShadow(color: Colors.black.withOpacity(0.10), blurRadius: 20, offset: const Offset(0, -4)),
                    ],
                  ),
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
                    child: CustomScrollView(
                      controller: scrollController,
                      slivers: [
                        SliverToBoxAdapter(
                          child: Center(
                            child: Container(
                              width: 44,
                              height: 5,
                              margin: const EdgeInsets.only(bottom: 12, top: 6),
                              decoration: BoxDecoration(
                                color: Colors.black.withOpacity(0.12),
                                borderRadius: BorderRadius.circular(999),
                              ),
                            ),
                          ),
                        ),
                        // ... rest of slivers (same as before)
                        SliverToBoxAdapter(
                          child: Row(
                            children: [
                              Text('音乐', style: t.titleLarge?.copyWith(color: Colors.black87, fontWeight: FontWeight.w900)),
                              const SizedBox(width: 22),
                              Text('播客', style: t.titleMedium?.copyWith(color: Colors.black45, fontWeight: FontWeight.w700)),
                              const SizedBox(width: 22),
                              Text('笔记', style: t.titleMedium?.copyWith(color: Colors.black45, fontWeight: FontWeight.w700)),
                              const Spacer(),
                              Icon(Icons.more_horiz_rounded, color: Colors.black45),
                            ],
                          ),
                        ),
                        SliverToBoxAdapter(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const SizedBox(height: 10),
                              if (_loading)
                                const LinearProgressIndicator(minHeight: 2)
                              else
                                const SizedBox(height: 2),
                              if (_err != null)
                                Padding(
                                  padding: const EdgeInsets.only(top: 10),
                                  child: Text(_err!, style: t.bodyMedium?.copyWith(color: Colors.redAccent)),
                                ),
                              const SizedBox(height: 10),
                            ],
                          ),
                        ),
                        SliverList(
                          delegate: SliverChildListDelegate([
                            Text('我的歌单', style: t.titleMedium?.copyWith(color: Colors.black87, fontWeight: FontWeight.w900)),
                            const SizedBox(height: 10),
                            _LibraryCard(
                              title: '最近播放',
                              count: _recents.length,
                              coverUrl: _recents.isNotEmpty ? _recents.first.coverUrl : '',
                              subtitle: _recents.isEmpty ? '去搜索页点一首歌开始吧' : '继续你的最近播放',
                              onOpen: () {
                                Navigator.of(context).push(
                                  MaterialPageRoute(builder: (_) => LibraryPlaylistPage(title: '最近播放', items: _recents)),
                                );
                              },
                              onPlay: _recents.isEmpty ? null : () => _playFrom(_recents, 0),
                            ),
                            const SizedBox(height: 12),
                            _LibraryCard(
                              title: '我喜欢的音乐',
                              count: _favorites.length,
                              coverUrl: _favorites.isNotEmpty ? _favorites.first.coverUrl : '',
                              subtitle: _favorites.isEmpty ? '在播放页点「喜欢」收藏' : '你的收藏歌单',
                              onOpen: () {
                                Navigator.of(context).push(
                                  MaterialPageRoute(builder: (_) => LibraryPlaylistPage(title: '我喜欢的音乐', items: _favorites)),
                                );
                              },
                              onPlay: _favorites.isEmpty ? null : () => _playFrom(_favorites, 0),
                            ),
                            const SizedBox(height: 18),
                            if (_userPlaylists.isNotEmpty) ...[
                              Text('收藏歌单', style: t.titleMedium?.copyWith(color: Colors.black87, fontWeight: FontWeight.w900)),
                              const SizedBox(height: 10),
                              for (final p in _userPlaylists)
                                Padding(
                                  padding: const EdgeInsets.only(bottom: 12),
                                  child: _LibraryCard(
                                    title: p.name,
                                    count: p.trackCount,
                                    coverUrl: p.coverUrl,
                                    subtitle: p.platform == 'local' ? '自建歌单' : '来自 ${p.platform}',
                                    onOpen: () {
                                      Navigator.of(context).push(
                                        MaterialPageRoute(
                                          builder: (_) => PlaylistPage(
                                            source: p.platform,
                                            id: p.externalId,
                                            title: p.name,
                                            coverUrl: p.coverUrl,
                                          ),
                                        ),
                                      );
                                    },
                                    onPlay: null,
                                  ),
                                ),
                            ],
                          ]),
                        ),
                        // Add extra padding at the bottom so content isn't hidden by mini player
                        const SliverToBoxAdapter(child: SizedBox(height: 160)),
                      ],
                    ),
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}

class _Backdrop extends StatelessWidget {
  const _Backdrop();

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            Color(0xFFBFB4A6),
            Color(0xFF9F8E7A),
            Color(0xFF7F7466),
          ],
        ),
      ),
    );
  }
}

class _QuickBtn extends StatelessWidget {
  const _QuickBtn({required this.icon, required this.label, this.onTap});

  final IconData icon;
  final String label;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Column(
        children: [
          Container(
            width: 56,
            height: 44,
            decoration: BoxDecoration(
              color: Colors.white.withOpacity(0.16),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: Colors.white.withOpacity(0.22)),
            ),
            child: Icon(icon, color: Colors.white, size: 22),
          ),
          if (label.isNotEmpty) ...[
            const SizedBox(height: 8),
            Text(label, style: Theme.of(context).textTheme.labelMedium?.copyWith(color: Colors.white70, fontWeight: FontWeight.w600)),
          ],
        ],
      ),
    );
  }
}

class _LibraryCard extends StatelessWidget {
  const _LibraryCard({
    required this.title,
    required this.count,
    required this.coverUrl,
    required this.subtitle,
    required this.onOpen,
    required this.onPlay,
  });

  final String title;
  final int count;
  final String coverUrl;
  final String subtitle;
  final VoidCallback onOpen;
  final VoidCallback? onPlay;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onOpen,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 12, 12, 12),
          child: Row(
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(14),
                child: SizedBox(
                  width: 64,
                  height: 64,
                  child: CachedCoverImage(
                          imageUrl: coverUrl,
                          fit: BoxFit.cover,
                          placeholder: Container(color: Colors.black12, child: const Icon(Icons.queue_music_rounded, color: Colors.black38)),
                        ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(title, style: t.titleMedium?.copyWith(color: Colors.black87, fontWeight: FontWeight.w900)),
                        ),
                        Text('$count 首', style: t.labelLarge?.copyWith(color: Colors.black45, fontWeight: FontWeight.w700)),
                      ],
                    ),
                    const SizedBox(height: 6),
                    Text(subtitle, maxLines: 1, overflow: TextOverflow.ellipsis, style: t.bodySmall?.copyWith(color: Colors.black54, fontWeight: FontWeight.w600)),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        if (onPlay != null)
                          FilledButton.icon(
                            onPressed: onPlay,
                            icon: const Icon(Icons.play_arrow_rounded, size: 18),
                            label: const Text('播放'),
                            style: FilledButton.styleFrom(
                              backgroundColor: const Color(0xFFE04A3A),
                              foregroundColor: Colors.white,
                              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                            ),
                          )
                        else
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                            decoration: BoxDecoration(
                              color: Colors.black.withOpacity(0.06),
                              borderRadius: BorderRadius.circular(12),
                            ),
                            child: Text('暂无', style: t.labelLarge?.copyWith(color: Colors.black54, fontWeight: FontWeight.w700)),
                          ),
                        const Spacer(),
                        Icon(Icons.chevron_right_rounded, color: Colors.black38),
                      ],
                    )
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
