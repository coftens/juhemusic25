import 'dart:async';
import 'package:flutter/material.dart';

class TypewriterText extends StatefulWidget {
  const TypewriterText(
    this.text, {
    super.key,
    this.style,
    this.duration = const Duration(milliseconds: 50),
    this.cursor = '_',
  });

  final String text;
  final TextStyle? style;
  final Duration duration;
  final String cursor;

  @override
  State<TypewriterText> createState() => _TypewriterTextState();
}

class _TypewriterTextState extends State<TypewriterText> {
  String _displayed = '';
  int _index = 0;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _start();
  }

  @override
  void didUpdateWidget(covariant TypewriterText oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.text != widget.text) {
      _start();
    }
  }

  void _start() {
    _timer?.cancel();
    _displayed = '';
    _index = 0;

    if (widget.text.isEmpty) {
      if (mounted) setState(() {});
      return;
    }

    _timer = Timer.periodic(widget.duration, (timer) {
      if (_index < widget.text.length) {
        setState(() {
          _index++;
          _displayed = widget.text.substring(0, _index);
        });
      } else {
        timer.cancel();
      }
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Text(
      '$_displayed${_index < widget.text.length ? widget.cursor : ""}',
      style: widget.style,
    );
  }
}
