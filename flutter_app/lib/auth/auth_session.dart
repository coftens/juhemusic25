import 'dart:convert';

import 'dart:async';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_config.dart';

class AuthUser {
  AuthUser({required this.id, required this.username, required String avatarUrl}) : _avatarUrl = avatarUrl;

  final int id;
  final String username;
  final String _avatarUrl;

  String get avatarUrl {
    if (_avatarUrl.startsWith('http://') || _avatarUrl.startsWith('https://')) {
      return _avatarUrl;
    }
    if (_avatarUrl.isEmpty) return '';
    // Handle relative paths
    final base = ApiConfig.instance.phpBaseUrl.trim();
    if (base.isEmpty) return _avatarUrl; // Fallback
    final cleanBase = base.endsWith('/') ? base.substring(0, base.length - 1) : base;
    final cleanPath = _avatarUrl.startsWith('/') ? _avatarUrl : '/$_avatarUrl';
    return '$cleanBase$cleanPath';
  }

  AuthUser copyWith({String? avatarUrl}) {
    return AuthUser(
      id: id,
      username: username,
      avatarUrl: avatarUrl ?? _avatarUrl,
    );
  }

  static AuthUser? fromJson(Map<String, dynamic> j) {
    final id = (j['id'] as num?)?.toInt() ?? 0;
    final username = (j['username'] as String?) ?? '';
    final avatarUrl = (j['avatar_url'] as String?) ?? '';
    if (id <= 0 || username.isEmpty) return null;
    return AuthUser(id: id, username: username, avatarUrl: avatarUrl);
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'username': username,
        'avatar_url': _avatarUrl,
      };
}

class SavedAccount {
  SavedAccount({
    required this.user,
    required this.accessToken,
    required this.refreshToken,
    required this.lastLoginAt,
  });

  final AuthUser user;
  final String accessToken;
  final String refreshToken;
  final int lastLoginAt;

  static SavedAccount? fromJson(Map<String, dynamic> j) {
    final user = AuthUser.fromJson((j['user'] as Map?)?.cast<String, dynamic>() ?? const {});
    if (user == null) return null;
    final access = (j['access_token'] as String?) ?? '';
    final refresh = (j['refresh_token'] as String?) ?? '';
    final lastLoginAt = (j['last_login_at'] as num?)?.toInt() ?? 0;
    if (access.isEmpty || refresh.isEmpty) return null;
    return SavedAccount(
      user: user,
      accessToken: access,
      refreshToken: refresh,
      lastLoginAt: lastLoginAt,
    );
  }

  Map<String, dynamic> toJson() => {
        'user': user.toJson(),
        'access_token': accessToken,
        'refresh_token': refreshToken,
        'last_login_at': lastLoginAt,
      };
}

class AuthSession extends ChangeNotifier {
  AuthSession._();

  static final instance = AuthSession._();

  static const _kAccess = 'auth.access_token.v1';
  static const _kRefresh = 'auth.refresh_token.v1';
  static const _kDevice = 'auth.device_id.v1';
  static const _kUser = 'auth.user.v1';
  static const _kAccounts = 'auth.accounts.v1';

  String _accessToken = '';
  String _refreshToken = '';
  String _deviceId = '';
  AuthUser? _user;
  List<SavedAccount> _accounts = [];

  String get accessToken => _accessToken;
  String get refreshToken => _refreshToken;
  String get deviceId => _deviceId;
  AuthUser? get user => _user;
  bool get isAuthed => _accessToken.isNotEmpty && _refreshToken.isNotEmpty;
  List<SavedAccount> get accounts => List.unmodifiable(_accounts);

  Future<void> load() async {
    final sp = await SharedPreferences.getInstance();
    _accessToken = (sp.getString(_kAccess) ?? '').trim();
    _refreshToken = (sp.getString(_kRefresh) ?? '').trim();
    _deviceId = (sp.getString(_kDevice) ?? '').trim();
    final rawUser = (sp.getString(_kUser) ?? '').trim();
    if (rawUser.isNotEmpty) {
      try {
        final j = jsonDecode(rawUser);
        if (j is Map) {
          _user = AuthUser.fromJson(j.cast<String, dynamic>());
        }
      } catch (_) {
        // ignore
      }
    }
    if (_deviceId.isEmpty) {
      _deviceId = '${DateTime.now().millisecondsSinceEpoch}-${Object().hashCode}';
      await sp.setString(_kDevice, _deviceId);
    }
    final rawAccounts = (sp.getString(_kAccounts) ?? '').trim();
    if (rawAccounts.isNotEmpty) {
      try {
        final j = jsonDecode(rawAccounts);
        if (j is List) {
          _accounts = j
              .whereType<Map>()
              .map((e) => SavedAccount.fromJson(e.cast<String, dynamic>()))
              .whereType<SavedAccount>()
              .toList();
        }
      } catch (_) {
        _accounts = [];
      }
    }
    final u = _user;
    if (u != null && _accessToken.isNotEmpty && _refreshToken.isNotEmpty) {
      _upsertAccount(user: u, accessToken: _accessToken, refreshToken: _refreshToken);
      await _persistAccounts();
    }
    notifyListeners();
  }

  Future<void> setAuth({required String accessToken, required String refreshToken, required AuthUser user}) async {
    final sp = await SharedPreferences.getInstance();
    _accessToken = accessToken.trim();
    _refreshToken = refreshToken.trim();
    _user = user;
    await sp.setString(_kAccess, _accessToken);
    await sp.setString(_kRefresh, _refreshToken);
    await sp.setString(_kUser, jsonEncode(user.toJson()));
    _upsertAccount(user: user, accessToken: _accessToken, refreshToken: _refreshToken);
    await _persistAccounts();
    notifyListeners();
  }

  Future<void> switchToAccount(SavedAccount account) async {
    final sp = await SharedPreferences.getInstance();
    _accessToken = account.accessToken;
    _refreshToken = account.refreshToken;
    _user = account.user;
    await sp.setString(_kAccess, _accessToken);
    await sp.setString(_kRefresh, _refreshToken);
    await sp.setString(_kUser, jsonEncode(account.user.toJson()));
    _upsertAccount(user: account.user, accessToken: account.accessToken, refreshToken: account.refreshToken);
    await _persistAccounts();
    notifyListeners();
  }

  Future<void> clear() async {
    final sp = await SharedPreferences.getInstance();
    _accessToken = '';
    _refreshToken = '';
    _user = null;
    await sp.remove(_kAccess);
    await sp.remove(_kRefresh);
    await sp.remove(_kUser);
    notifyListeners();
  }

  void _upsertAccount({required AuthUser user, required String accessToken, required String refreshToken}) {
    final now = DateTime.now().millisecondsSinceEpoch;
    final idx = _accounts.indexWhere((a) => a.user.id == user.id);
    if (idx >= 0) {
      _accounts.removeAt(idx);
    }
    _accounts.insert(
      0,
      SavedAccount(
        user: user,
        accessToken: accessToken,
        refreshToken: refreshToken,
        lastLoginAt: now,
      ),
    );
  }

  Future<void> _persistAccounts() async {
    final sp = await SharedPreferences.getInstance();
    final payload = _accounts.map((a) => a.toJson()).toList();
    await sp.setString(_kAccounts, jsonEncode(payload));
  }

  void updateAvatarUrl(String url) {
    final u = _user;
    if (u == null) return;
    _user = u.copyWith(avatarUrl: url);
    unawaited(_persistUser());
    notifyListeners();
  }

  Future<void> _persistUser() async {
    final u = _user;
    if (u == null) return;
    final sp = await SharedPreferences.getInstance();
    await sp.setString(_kUser, jsonEncode(u.toJson()));
  }

  Uri _uri(String path) {
    final b = ApiConfig.instance.phpBaseUrl.trim();
    final u = Uri.parse(b);
    final prefix = u.path.endsWith('/') ? u.path.substring(0, u.path.length - 1) : u.path;
    return u.replace(path: '$prefix$path');
  }

  Future<bool> refresh() async {
    final r = _refreshToken.trim();
    if (r.isEmpty) return false;
    try {
      final resp = await http.post(
        _uri('/api/auth_refresh.php'),
        headers: const {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: jsonEncode({'refresh_token': r, 'device_id': _deviceId}),
      );
      if (resp.statusCode != 200) return false;
      final j = jsonDecode(resp.body);
      if (j is! Map) return false;
      final code = (j['code'] as num?)?.toInt() ?? 500;
      if (code != 200) return false;
      final data = (j['data'] as Map?)?.cast<String, dynamic>() ?? const {};
      final tokens = (data['tokens'] as Map?)?.cast<String, dynamic>() ?? const {};
      final access = (tokens['access_token'] as String?) ?? '';
      final refresh = (tokens['refresh_token'] as String?) ?? '';
      if (access.isEmpty || refresh.isEmpty) return false;
      final sp = await SharedPreferences.getInstance();
      _accessToken = access;
      _refreshToken = refresh;
      await sp.setString(_kAccess, _accessToken);
      await sp.setString(_kRefresh, _refreshToken);
      notifyListeners();
      return true;
    } catch (_) {
      return false;
    }
  }
}
