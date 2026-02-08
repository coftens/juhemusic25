// v5.0 Queue Management Methods
// This file contains the new queue management methods for v5.0
// Copy this content and insert at line 432 in player_service.dart (after _loadRecommendations method)

  // ==================== v5.0 队列管理核心方法 ====================
  
  /// 从指定来源初始化播放队列 (限量加载)
  Future<void> initQueueFromSource({
    required QueueSource source,
    String? playlistId,
    List<SearchItem>? initialItems,  // 可选：直接传入初始歌曲列表
  }) async {
    debugPrint('[Queue] Initializing from source: $source, playlistId: $playlistId');
    
    // 1. 清空当前队列
    _queue.clear();
    await _playlist.clear();
    _index = 0;
    _orderPos = 0;
    
    // 2. 根据来源加载初始3首
    List<SearchItem> items = [];
    List<String> sourceIds = [];
    
    if (initialItems != null && initialItems.isNotEmpty) {
      // 如果直接传入了歌曲列表,使用前3首
      items = initialItems.take(3).toList();
      sourceIds = initialItems.map((e) => e.id ?? e.shareUrl).toList();
    } else if (source == QueueSource.qishuiRecommend) {
      // 根据...推荐: 从汽水API获取3首
      items = await _api.getQishuiFeed(count: 3);
      sourceIds = []; // 汽水源无固定ID列表
    } else if (source == QueueSource.dailyRecommend) {
      // 每日推荐: 获取所有每日推荐,但只加载前3首
      final allDaily = await _api.getQishuiFeed(count: 20); // TODO: 改为真正的每日推荐API
      items = allDaily.take(3).toList();
      sourceIds = allDaily.map((e) => e.id ?? e.shareUrl).toList();
    } else if (source == QueueSource.playlist && playlistId != null) {
      // 歌单: 获取歌单的所有歌曲ID,但只加载前3首
      final playlistData = await _api.getPlaylistDetail(playlistId);
      final allTracks = playlistData['tracks'] as List<SearchItem>;
      items = allTracks.take(3).toList();
      sourceIds = allTracks.map((e) => e.id ?? e.shareUrl).toList();
    }
    
    if (items.isEmpty) {
      debugPrint('[Queue] Warning: No items loaded');
      return;
    }
    
    // 3. 初始化上下文
    _queueContext = QueueContext(
      source: source,
      playlistId: playlistId,
      sourceItemIds: sourceIds,
      loadedCount: items.length,
    );
    
    // 4. 加载到队列
    _queue = items;
    await _rebuildOrder(startIndex: 0);
    
    debugPrint('[Queue] Initialized: ${items.length} songs, source: $source');
    notifyListeners();
  }
