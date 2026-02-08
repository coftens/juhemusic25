import 'package:flutter/material.dart';

class SearchPageRoute extends PageRouteBuilder {
  final Widget child;

  SearchPageRoute({required this.child})
      : super(
          opaque: false, // Critical for BackdropFilter to work on the underlying page
          pageBuilder: (context, animation, secondaryAnimation) => child,
          transitionDuration: const Duration(milliseconds: 300),
          reverseTransitionDuration: const Duration(milliseconds: 250),
          transitionsBuilder: (context, animation, secondaryAnimation, child) {
            final curve = CurvedAnimation(
              parent: animation,
              curve: Curves.easeOutQuart,
              reverseCurve: Curves.easeInQuart,
            );

            // A subtle slide from bottom (5% of screen height) combine with fade
            // This feels like a "dialog" or "overlay" appearing elegantly.
            return SlideTransition(
              position: Tween<Offset>(
                begin: const Offset(0, 0.05), 
                end: Offset.zero,
              ).animate(curve),
              child: FadeTransition(
                opacity: curve,
                child: child,
              ),
            );
          },
        );
}
