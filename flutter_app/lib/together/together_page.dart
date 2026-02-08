import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'dart:math';
import 'together_service.dart';

class TogetherPage extends StatefulWidget {
  const TogetherPage({super.key});

  @override
  State<TogetherPage> createState() => _TogetherPageState();
}

class _TogetherPageState extends State<TogetherPage> {
  final _service = TogetherService();
  final _idCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    _service.statusNotifier.addListener(_update);
    _service.init(); // Ensure initialized
    _service.requestSync(); // Force update user list
  }

  @override
  void dispose() {
    _service.statusNotifier.removeListener(_update);
    _idCtrl.dispose();
    super.dispose();
  }

  void _update() {
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final status = _service.statusNotifier.value;
    final roomId = _service.roomId;
    final isHost = _service.isHost;

    return Scaffold(
      appBar: AppBar(
        title: const Text('一起听 (Listen Together)'),
        backgroundColor: Colors.white,
        foregroundColor: Colors.black,
        elevation: 0,
      ),
      backgroundColor: const Color(0xFFF2F3F4),
      body: Padding(
        padding: const EdgeInsets.all(20.0),
        child: Column(
          children: [
            // Status Card
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withOpacity(0.05),
                    blurRadius: 10,
                    offset: const Offset(0, 4),
                  ),
                ],
              ),
              child: Column(
                children: [
                  const Icon(Icons.music_note_rounded, size: 48, color: Colors.redAccent),
                  const SizedBox(height: 12),
                  Text(
                    status,
                    style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                  ),
                  if (roomId != null) ...[
                    const SizedBox(height: 8),
                    GestureDetector(
                      onTap: () {
                        Clipboard.setData(ClipboardData(text: roomId));
                        ScaffoldMessenger.of(context).showSnackBar(
                           const SnackBar(content: Text('Room ID copied!'))
                        );
                      },
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                        decoration: BoxDecoration(
                          color: Colors.grey[100],
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Text(
                          'ID: $roomId (Copy)',
                          style: TextStyle(
                            fontSize: 20, 
                            fontWeight: FontWeight.w900,
                            letterSpacing: 2.0,
                            color: Colors.blue[800]
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      isHost 
                        ? '你是房主 (Host)\n控制播放进度，其他用户将跟随你' 
                        : '你是听众 (Guest)\n请放松，音乐将自动同步',
                      textAlign: TextAlign.center,
                      style: const TextStyle(color: Colors.grey),
                    ),
                  ]
                ],
                ),
              ),
            
            if (roomId != null) ...[
              const SizedBox(height: 20),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Icon(Icons.people_alt_rounded, size: 20, color: Colors.blueGrey),
                        const SizedBox(width: 8),
                        const Text('在线用户 (Connected Users)', style: TextStyle(fontWeight: FontWeight.bold)),
                        IconButton(
                          icon: const Icon(Icons.refresh, size: 16, color: Colors.blueGrey),
                          onPressed: () => _service.requestSync(),
                          padding: EdgeInsets.zero,
                          constraints: const BoxConstraints(),
                          tooltip: '刷新列表',
                        ),
                        const Spacer(),
                        ValueListenableBuilder(
                          valueListenable: _service.usersNotifier,
                          builder: (context, users, _) => Text('${users.length} 人', style: const TextStyle(color: Colors.grey, fontSize: 12)),
                        ),
                      ],
                    ),
                    const Divider(),
                    ValueListenableBuilder(
                      valueListenable: _service.usersNotifier,
                      builder: (context, users, _) {
                        print('TogetherPage: [DEBUG] Building User List: $users (${users.length})');
                        if (users.isEmpty) return const Text('正在获取用户列表...', style: TextStyle(color: Colors.grey, fontSize: 12));
                        return Wrap(
                          spacing: 8,
                          runSpacing: 4,
                          children: users.map((u) {
                            final isMe = u == _service.statusNotifier.value; // Not accurate but Socket ID comparison needed?
                            // Actually TogetherService doesn't store 'my' socket ID explicitly yet. 
                            // But server broadcasts host ID.
                            final isRoomHost = u == _service.hostSocketId;
                            
                            return Chip(
                              avatar: Icon(isRoomHost ? Icons.star_rounded : Icons.person_outline_rounded, 
                                size: 16, 
                                color: isRoomHost ? Colors.orange : Colors.blueGrey
                              ),
                              label: Text(u.substring(max(0, u.length - 6)), style: const TextStyle(fontSize: 12)),
                              backgroundColor: isRoomHost ? Colors.orange[50] : Colors.grey[100],
                              side: BorderSide.none,
                            );
                          }).toList(),
                        );
                      },
                    ),
                  ],
                ),
              ),
            ],

            const SizedBox(height: 30),

            if (roomId == null) ...[
              SizedBox(
                width: double.infinity,
                height: 50,
                child: ElevatedButton.icon(
                  onPressed: () {
                    _service.createRoom();
                  },
                  icon: const Icon(Icons.add_circle_outline),
                  label: const Text('创建房间 (Create Room)'),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.redAccent,
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                ),
              ),
              
              const SizedBox(height: 20),
              const Row(children: [
                  Expanded(child: Divider()),
                  Padding(padding: EdgeInsets.symmetric(horizontal: 10), child: Text('OR')),
                  Expanded(child: Divider()),
              ]),
              const SizedBox(height: 20),

              TextField(
                controller: _idCtrl,
                keyboardType: TextInputType.number,
                decoration: InputDecoration(
                  labelText: '输入房间号 ID',
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                  prefixIcon: const Icon(Icons.meeting_room),
                ),
              ),
              const SizedBox(height: 12),
              SizedBox(
                width: double.infinity,
                height: 50,
                child: FilledButton.tonalIcon(
                  onPressed: () {
                    final id = _idCtrl.text.trim();
                    if (id.isNotEmpty) {
                      _service.joinRoom(id);
                    }
                  },
                  icon: const Icon(Icons.login),
                  label: const Text('加入房间 (Join Room)'),
                  style: FilledButton.styleFrom(
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                ),
              ),
            ] else ...[
               SizedBox(
                width: double.infinity,
                height: 50,
                child: OutlinedButton.icon(
                  onPressed: () {
                    _idCtrl.clear();
                    _service.leaveRoom();
                  },
                  icon: const Icon(Icons.exit_to_app),
                  label: const Text('离开房间 (Leave Room)'),
                  style: OutlinedButton.styleFrom(
                    foregroundColor: Colors.red,
                    side: const BorderSide(color: Colors.red),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                ),
              ),
            ]
          ],
        ),
      ),
    );
  }
}
