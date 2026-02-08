import 'package:flutter/material.dart';
import 'package:flutter/painting.dart';
import 'package:flutter_cache_manager/flutter_cache_manager.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../app/update_checker.dart';
import '../auth/auth_api.dart';
import '../auth/auth_session.dart';
import 'floating_lyrics_settings_page.dart';

class SettingsPage extends StatefulWidget {
  const SettingsPage({super.key});

  @override
  State<SettingsPage> createState() => _SettingsPageState();
}

class _SettingsPageState extends State<SettingsPage> {
  bool _lyricsFloating = false;
  bool _clearing = false;

  @override
  void initState() {
    super.initState();
    _loadPrefs();
  }

  Future<void> _loadPrefs() async {
    final sp = await SharedPreferences.getInstance();
    if (!mounted) return;
    setState(() {
      _lyricsFloating = sp.getBool('lyrics.floating.enabled.v1') ?? false;
    });
  }


  Future<void> _clearCache() async {
    if (_clearing) return;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('清理缓存'),
        content: const Text('将清理图片与临时缓存，占用空间会下降。'),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('取消')),
          TextButton(onPressed: () => Navigator.of(ctx).pop(true), child: const Text('清理')),
        ],
      ),
    );
    if (ok != true) return;
    setState(() => _clearing = true);
    try {
      await DefaultCacheManager().emptyCache();
      PaintingBinding.instance.imageCache.clear();
      PaintingBinding.instance.imageCache.clearLiveImages();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('缓存已清理')),
      );
    } finally {
      if (mounted) setState(() => _clearing = false);
    }
  }

  Future<void> _switchAccount(SavedAccount account) async {
    await AuthSession.instance.switchToAccount(account);
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('已切换到 ${account.user.username}')),
    );
  }

  Future<void> _logout() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('退出当前账号'),
        content: const Text('退出后将返回到登录页面。'),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('取消')),
          TextButton(onPressed: () => Navigator.of(ctx).pop(true), child: const Text('退出')),
        ],
      ),
    );
    if (ok != true) return;
    await AuthApi.instance.logout();
    if (!mounted) return;
    Navigator.of(context).popUntil((r) => r.isFirst);
  }

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Scaffold(
      backgroundColor: const Color(0xFFF2F3F4),
      appBar: AppBar(
        title: const Text('设置'),
        backgroundColor: const Color(0xFFF2F3F4),
        elevation: 0,
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 20),
        children: [
          Text('通用', style: t.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
          const SizedBox(height: 8),
          Material(
            color: Colors.white,
            borderRadius: BorderRadius.circular(14),
            child: Column(
              children: [
                ListTile(
                  leading: const Icon(Icons.cleaning_services_rounded),
                  title: const Text('清理缓存'),
                  subtitle: const Text('清理图片与临时缓存'),
                  trailing: _clearing
                      ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.chevron_right_rounded),
                  onTap: _clearCache,
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.lyrics_rounded),
                  title: const Text('歌词悬浮窗'),
                  subtitle: Text(_lyricsFloating ? '已开启' : '未开启'),
                  trailing: const Icon(Icons.chevron_right_rounded),
                  onTap: () async {
                    await Navigator.of(context).push(
                      MaterialPageRoute(builder: (_) => const FloatingLyricsSettingsPage()),
                    );
                    await _loadPrefs();
                  },
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),
          Text('账号管理', style: t.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
          const SizedBox(height: 8),
          AnimatedBuilder(
            animation: AuthSession.instance,
            builder: (context, _) {
              final accounts = AuthSession.instance.accounts;
              final current = AuthSession.instance.user;
              return Material(
                color: Colors.white,
                borderRadius: BorderRadius.circular(14),
                child: Column(
                  children: [
                    if (accounts.isEmpty)
                      const ListTile(
                        leading: Icon(Icons.account_circle_outlined),
                        title: Text('暂无已登录账号'),
                        subtitle: Text('登录后会自动记录，支持一键切换'),
                      )
                    else
                      for (final acc in accounts) ...[
                        ListTile(
                          leading: CircleAvatar(
                            backgroundColor: Colors.black12,
                            child: Text(
                              acc.user.username.isNotEmpty ? acc.user.username[0].toUpperCase() : '?',
                              style: const TextStyle(color: Colors.black87, fontWeight: FontWeight.w700),
                            ),
                          ),
                          title: Text(acc.user.username),
                          subtitle: Text(acc.user.id == (current?.id ?? 0) ? '当前账号' : '可切换'),
                          trailing: acc.user.id == (current?.id ?? 0)
                              ? const Text('当前', style: TextStyle(color: Colors.black54, fontWeight: FontWeight.w600))
                              : TextButton(
                                  onPressed: () => _switchAccount(acc),
                                  child: const Text('切换'),
                                ),
                        ),
                        if (acc != accounts.last) const Divider(height: 1),
                      ],
                    const Divider(height: 1),
                    ListTile(
                      leading: const Icon(Icons.logout_rounded, color: Colors.redAccent),
                      title: const Text('退出当前账号', style: TextStyle(color: Colors.redAccent, fontWeight: FontWeight.w700)),
                      onTap: _logout,
                    ),
                  ],
                ),
              );
            },
          ),
          const SizedBox(height: 24),
          Center(
            child: Text(
              '当前版本 $appVersion',
              style: t.bodySmall?.copyWith(color: Colors.black45, fontWeight: FontWeight.w600),
            ),
          ),
          const SizedBox(height: 8),
        ],
      ),
    );
  }
}
