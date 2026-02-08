import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';

/// 带缓存的网络图片组件
/// 自动缓存到本地磁盘，切换页面后无需重新下载
class CachedCoverImage extends StatelessWidget {
  const CachedCoverImage({
    super.key,
    required this.imageUrl,
    this.width,
    this.height,
    this.fit = BoxFit.cover,
    this.borderRadius,
    this.placeholder,
    this.errorWidget,
  });

  final String imageUrl;
  final double? width;
  final double? height;
  final BoxFit fit;
  final BorderRadius? borderRadius;
  final Widget? placeholder;
  final Widget? errorWidget;

  @override
  Widget build(BuildContext context) {
    if (imageUrl.isEmpty) {
      return _buildPlaceholder();
    }

    Widget image = CachedNetworkImage(
      imageUrl: imageUrl,
      width: width,
      height: height,
      fit: fit,
      placeholder: (context, url) => placeholder ?? _buildPlaceholder(),
      errorWidget: (context, url, error) => errorWidget ?? _buildPlaceholder(),
      fadeInDuration: const Duration(milliseconds: 200),
      fadeOutDuration: const Duration(milliseconds: 200),
    );

    if (borderRadius != null) {
      image = ClipRRect(
        borderRadius: borderRadius!,
        child: image,
      );
    }

    return image;
  }

  Widget _buildPlaceholder() {
    return Container(
      width: width,
      height: height,
      decoration: BoxDecoration(
        color: Colors.grey[800],
        borderRadius: borderRadius,
      ),
      child: Icon(
        Icons.music_note,
        color: Colors.grey[600],
        size: (width ?? height ?? 48) * 0.4,
      ),
    );
  }
}

/// 获取缓存的 ImageProvider（用于 DecorationImage 等场景）
CachedNetworkImageProvider cachedImageProvider(String url) {
  return CachedNetworkImageProvider(url);
}
