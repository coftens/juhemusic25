import 'package:flutter/material.dart';

import '../lyrics/floating_lyrics_controller.dart';

class FloatingLyricsSettingsPage extends StatefulWidget {
  const FloatingLyricsSettingsPage({super.key});

  @override
  State<FloatingLyricsSettingsPage> createState() => _FloatingLyricsSettingsPageState();
}

class _FloatingLyricsSettingsPageState extends State<FloatingLyricsSettingsPage> with WidgetsBindingObserver {
  bool _enabled = false;
  bool _hasPermission = false;
  String _selectedColor = 'butter';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _sync();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _sync();
    }
  }

  Future<void> _sync() async {
    final ctl = FloatingLyricsController.instance;
    final ok = await ctl.checkPermission();
    if (!mounted) return;
    setState(() {
      _enabled = ctl.enabled;
      _hasPermission = ok;
      _selectedColor = ctl.selectedColor;
    });
  }

  Future<void> _toggle(bool v) async {
    await FloatingLyricsController.instance.setEnabled(v);
    await _sync();
  }

  Future<void> _setColor(String colorKey) async {
    await FloatingLyricsController.instance.setColor(colorKey);
    await _sync();
  }

  Future<void> _requestPermission() async {
    await FloatingLyricsController.instance.requestPermission();
  }

  Future<void> _openSystemSettings({String? vendor}) async {
    try {
      await FloatingLyricsController.instance.openOverlaySettings(vendor: vendor);
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('无法跳转设置页面，请手动进入系统设置')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Scaffold(
      backgroundColor: const Color(0xFFF2F3F4),
      appBar: AppBar(
        title: const Text('歌词悬浮窗'),
        backgroundColor: const Color(0xFFF2F3F4),
        elevation: 0,
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 20),
        children: [
          Text('开关', style: t.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
          const SizedBox(height: 8),
          Material(
            color: Colors.white,
            borderRadius: BorderRadius.circular(14),
            child: SwitchListTile(
              secondary: const Icon(Icons.lyrics_rounded),
              title: const Text('启用歌词悬浮窗'),
              subtitle: const Text('后台显示，前台自动隐藏'),
              value: _enabled,
              onChanged: _toggle,
            ),
          ),
          const SizedBox(height: 16),
          Text('字体颜色', style: t.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
          const SizedBox(height: 8),
          Material(
            color: Colors.white,
            borderRadius: BorderRadius.circular(14),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final entry in FloatingLyricsController.colorOptions.entries)
                    InkWell(
                      onTap: () => _setColor(entry.key),
                      borderRadius: BorderRadius.circular(12),
                      child: Container(
                        width: 50,
                        height: 50,
                        decoration: BoxDecoration(
                          color: Color(entry.value),
                          borderRadius: BorderRadius.circular(12),
                          border: _selectedColor == entry.key
                              ? Border.all(color: Colors.blue, width: 3)
                              : null,
                          boxShadow: _selectedColor == entry.key
                              ? [
                                  BoxShadow(
                                    color: Color(entry.value).withOpacity(0.4),
                                    blurRadius: 8,
                                    offset: const Offset(0, 2),
                                  ),
                                ]
                              : null,
                        ),
                        child: _selectedColor == entry.key
                            ? const Icon(Icons.check_rounded, color: Colors.white)
                            : null,
                      ),
                    ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final entry in FloatingLyricsController.colorNames.entries)
                if (_selectedColor == entry.key)
                  Chip(
                    avatar: Container(
                      width: 20,
                      height: 20,
                      decoration: BoxDecoration(
                        color: Color(FloatingLyricsController.colorOptions[entry.key] ?? 0xFFFFEDA8),
                        borderRadius: BorderRadius.circular(4),
                      ),
                    ),
                    label: Text(entry.value),
                  ),
            ],
          ),
          const SizedBox(height: 16),
          Text('权限', style: t.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
          const SizedBox(height: 8),
          Material(
            color: Colors.white,
            borderRadius: BorderRadius.circular(14),
            child: Column(
              children: [
                ListTile(
                  leading: Icon(_hasPermission ? Icons.verified_rounded : Icons.error_outline_rounded, color: _hasPermission ? Colors.green : Colors.orange),
                  title: Text(_hasPermission ? '已获得悬浮窗权限' : '未获得悬浮窗权限'),
                  subtitle: const Text('开启后才能在后台显示歌词悬浮窗'),
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.settings_rounded),
                  title: const Text('去开启权限'),
                  subtitle: const Text('跳转系统悬浮窗设置页面'),
                  onTap: _requestPermission,
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.open_in_new_rounded),
                  title: const Text('打开厂商权限页面（推荐）'),
                  subtitle: const Text('如系统页无入口，可尝试厂商设置页'),
                  onTap: () => _openSystemSettings(),
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),
          Text('厂商快捷入口', style: t.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
          const SizedBox(height: 8),
          _VendorGrid(onTap: _openSystemSettings),
        ],
      ),
    );
  }
}

class _VendorGrid extends StatelessWidget {
  const _VendorGrid({required this.onTap});

  final Future<void> Function({String? vendor}) onTap;

  @override
  Widget build(BuildContext context) {
    final items = const [
      _VendorItem('小米 / 红米', 'xiaomi'),
      _VendorItem('华为 / 荣耀', 'huawei'),
      _VendorItem('OPPO', 'oppo'),
      _VendorItem('vivo / iQOO', 'vivo'),
      _VendorItem('三星', 'samsung'),
      _VendorItem('魅族', 'meizu'),
      _VendorItem('一加', 'oneplus'),
      _VendorItem('其他', ''),
    ];

    return Wrap(
      spacing: 10,
      runSpacing: 10,
      children: [
        for (final it in items)
          InkWell(
            onTap: () => onTap(vendor: it.vendor),
            borderRadius: BorderRadius.circular(12),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
                boxShadow: [
                  BoxShadow(color: Colors.black.withOpacity(0.04), blurRadius: 8, offset: const Offset(0, 2)),
                ],
              ),
              child: Text(it.label, style: const TextStyle(fontWeight: FontWeight.w600)),
            ),
          ),
      ],
    );
  }
}

class _VendorItem {
  const _VendorItem(this.label, this.vendor);

  final String label;
  final String vendor;
}
