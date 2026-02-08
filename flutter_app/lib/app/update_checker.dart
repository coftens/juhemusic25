import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api/php_api_client.dart';

/// Current app version. Increment this on each release.
const String appVersion = '7.20.0';

class UpdateChecker {
  static bool _hasChecked = false;

  /// Call this once on app startup to check for updates.
  static Future<void> checkAndPrompt(BuildContext context) async {
    if (_hasChecked) return;
    _hasChecked = true;

    final api = PhpApiClient();
    final info = await api.checkVersion();

    // If no version returned or same/older, skip
    if (info.version.isEmpty) return;
    if (!_isNewerVersion(info.version, appVersion)) return;

    if (!context.mounted) return;
    _showUpdateDialog(context, info);
  }

  /// Compare version strings. Returns true if remote > local.
  static bool _isNewerVersion(String remote, String local) {
    try {
      final remoteParts = remote.split('.').map(int.parse).toList();
      final localParts = local.split('.').map(int.parse).toList();

      for (var i = 0; i < remoteParts.length; i++) {
        final r = remoteParts[i];
        final l = i < localParts.length ? localParts[i] : 0;
        if (r > l) return true;
        if (r < l) return false;
      }
      return false;
    } catch (_) {
      return false;
    }
  }

  static void _showUpdateDialog(BuildContext context, VersionInfo info) {
    showDialog(
      context: context,
      barrierDismissible: !info.force,
      builder: (ctx) => _UpdateDialog(info: info),
    );
  }
}

class _UpdateDialog extends StatelessWidget {
  const _UpdateDialog({required this.info});

  final VersionInfo info;

  @override
  Widget build(BuildContext context) {
    return Dialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
      backgroundColor: Colors.white,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(24, 28, 24, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Icon
            Container(
              width: 64,
              height: 64,
              decoration: BoxDecoration(
                gradient: const LinearGradient(
                  colors: [Color(0xFFE04A3A), Color(0xFFFF7E5F)],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
                borderRadius: BorderRadius.circular(18),
                boxShadow: [
                  BoxShadow(
                    color: const Color(0xFFE04A3A).withOpacity(0.3),
                    blurRadius: 16,
                    offset: const Offset(0, 6),
                  ),
                ],
              ),
              child: const Icon(Icons.system_update_rounded, color: Colors.white, size: 32),
            ),
            const SizedBox(height: 20),

            // Title
            Text(
              '发现新版本 ${info.version}',
              style: Theme.of(context).textTheme.titleLarge?.copyWith(
                    fontWeight: FontWeight.w900,
                    color: Colors.black87,
                  ),
            ),
            const SizedBox(height: 12),

            // Changelog
            if (info.changelog.isNotEmpty)
              Container(
                constraints: const BoxConstraints(maxHeight: 120),
                width: double.infinity,
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: Colors.grey.shade100,
                  borderRadius: BorderRadius.circular(14),
                ),
                child: SingleChildScrollView(
                  child: Text(
                    info.changelog,
                    style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                          color: Colors.black54,
                          height: 1.5,
                        ),
                  ),
                ),
              ),
            const SizedBox(height: 20),

            // Update button
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: () => _openDownload(info.downloadUrl),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFFE04A3A),
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                  elevation: 0,
                ),
                child: const Text('立即更新', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
              ),
            ),
            const SizedBox(height: 10),

            // Skip button (only if not forced)
            if (!info.force)
              TextButton(
                onPressed: () => Navigator.of(context).pop(),
                child: Text(
                  '稍后再说',
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: Colors.black45),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Future<void> _openDownload(String url) async {
    final uri = Uri.tryParse(url);
    if (uri != null && await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    }
  }
}
