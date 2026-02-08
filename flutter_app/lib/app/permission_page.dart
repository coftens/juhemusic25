import 'dart:io';

import 'package:flutter/material.dart';
import 'package:permission_handler/permission_handler.dart';

import '../api/api_config.dart';

class PermissionPage extends StatefulWidget {
  const PermissionPage({super.key});

  @override
  State<PermissionPage> createState() => _PermissionPageState();
}

class _PermissionPageState extends State<PermissionPage> {
  bool _requesting = false;

  Future<void> _handlePermissions() async {
    if (_requesting) return;
    setState(() => _requesting = true);

    try {
      // 1. Notifications
      await Permission.notification.request();

      // 2. Storage
      if (Platform.isAndroid) {
         await [
           Permission.storage,
           Permission.audio,
           Permission.photos,
         ].request();
      } else {
        await Permission.storage.request();
      }

      // 3. Network Probe (iOS China specific)
      // Making a simple head request to trigger the iOS "Wireless Data" system dialog
      if (Platform.isIOS) {
        try {
          final client = HttpClient();
          client.connectionTimeout = const Duration(seconds: 2);
          final request = await client.getUrl(Uri.parse('https://www.google.com'));
          final response = await request.close();
          debugPrint('iOS Network Probe status: ${response.statusCode}');
        } catch (e) {
          debugPrint('iOS Network Probe (expected if denied): $e');
        }
      }

      // 4. Mark as accepted
      await ApiConfig.instance.setPermissionsAccepted();
    } finally {
      if (mounted) {
        setState(() => _requesting = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Stack(
        children: [
          // Background
          Container(
            decoration: const BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [
                  Color(0xFF2C3E50), // Deeper, more "App" feel colors
                  Color(0xFF000000),
                ],
              ),
            ),
          ),
          
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 40.0),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  const Spacer(),
                  // Styled Logo / Icon
                  Container(
                    padding: const EdgeInsets.all(20),
                    decoration: BoxDecoration(
                      color: Colors.white.withOpacity(0.1),
                      shape: BoxShape.circle,
                    ),
                    child: const Icon(Icons.music_note_rounded, size: 72, color: Colors.white),
                  ),
                  const SizedBox(height: 32),
                  const Text(
                    '悦往音乐',
                    style: TextStyle(
                      color: Colors.white,
                      fontSize: 28,
                      fontWeight: FontWeight.w900,
                      letterSpacing: 2,
                    ),
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 12),
                  Text(
                    '身临其境的听歌体验',
                    style: TextStyle(
                      color: Colors.white.withOpacity(0.6),
                      fontSize: 16,
                    ),
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 48),
                  
                  // Permission List
                  _buildPermissionItem(
                    Icons.network_check_rounded,
                    '联网权限',
                    '用于在线搜索、播放和下载音乐',
                  ),
                  const SizedBox(height: 20),
                  _buildPermissionItem(
                    Icons.folder_shared_rounded,
                    '存储权限',
                    '用于扫描并播放您设备上的本地音频',
                  ),
                  const SizedBox(height: 20),
                  _buildPermissionItem(
                    Icons.notifications_active_rounded,
                    '通知权限',
                    '用于在通知栏控制播放及显示歌词',
                  ),
                  
                  const Spacer(),
                  ElevatedButton(
                    onPressed: _requesting ? null : _handlePermissions,
                    style: ElevatedButton.styleFrom(
                      backgroundColor: Colors.white,
                      foregroundColor: Colors.black,
                      padding: const EdgeInsets.symmetric(vertical: 18),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(30)),
                      minimumSize: const Size(double.infinity, 60),
                      elevation: 0,
                    ),
                    child: _requesting
                        ? const SizedBox(
                            width: 24,
                            child: LinearProgressIndicator(color: Colors.black, backgroundColor: Colors.transparent),
                          )
                        : const Text('同意并继续', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                  ),
                  const SizedBox(height: 40),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildPermissionItem(IconData icon, String title, String desc) {
    return Row(
      children: [
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: Colors.white.withAlpha(20),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Icon(icon, color: Colors.white70, size: 24),
        ),
        const SizedBox(width: 16),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 4),
              Text(
                desc,
                style: TextStyle(color: Colors.white.withOpacity(0.5), fontSize: 13),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
