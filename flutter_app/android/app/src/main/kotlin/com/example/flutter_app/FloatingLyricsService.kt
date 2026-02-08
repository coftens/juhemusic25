package com.example.flutter_app

import android.app.Service
import android.content.Intent
import android.graphics.Color
import android.graphics.PixelFormat
import android.os.Build
import android.os.IBinder
import android.util.TypedValue
import android.view.Gravity
import android.view.View
import android.view.WindowManager
import android.widget.FrameLayout
import android.widget.TextView

class FloatingLyricsService : Service() {
    companion object {
        private var instance: FloatingLyricsService? = null
        private var textColor: Int = 0xFFFFEDA8.toInt()
        private var secondaryAlpha: Int = 0xCC

        fun updateText(text: String) {
            instance?.updateTextInternal(text)
        }

        fun updateColor(color: Int, alpha: Int = 0xCC) {
            textColor = color
            secondaryAlpha = alpha
            instance?.applyColor()
        }

        fun stop(context: android.content.Context) {
            context.stopService(Intent(context, FloatingLyricsService::class.java))
        }
    }

    private var windowManager: WindowManager? = null
    private var root: FrameLayout? = null
    private var primaryText: TextView? = null
    private var secondaryText: TextView? = null
    private var lastText: String = ""

    override fun onCreate() {
        super.onCreate()
        instance = this
        windowManager = getSystemService(WINDOW_SERVICE) as WindowManager
        setupView()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val text = intent?.getStringExtra("text") ?: ""
        val color = intent?.getIntExtra("color", 0xFFFFEDA8.toInt()) ?: 0xFFFFEDA8.toInt()
        updateColor(color)
        updateTextInternal(text)
        return START_STICKY
    }

    override fun onDestroy() {
        super.onDestroy()
        removeView()
        instance = null
    }

    override fun onBind(intent: Intent?): IBinder? {
        return null
    }

    private fun setupView() {
        val ctx = this
        val container = FrameLayout(ctx)
        container.setPadding(dp(16), dp(8), dp(16), dp(8))
        container.setBackgroundColor(Color.TRANSPARENT)
        container.visibility = View.GONE

        val p = TextView(ctx)
        p.setTextColor(textColor)
        p.setTextSize(TypedValue.COMPLEX_UNIT_SP, 14f)
        p.setSingleLine(true)
        p.ellipsize = android.text.TextUtils.TruncateAt.END
        p.alpha = 1f

        val s = TextView(ctx)
        s.setTextColor((secondaryAlpha shl 24) or (textColor and 0xFFFFFF))
        s.setTextSize(TypedValue.COMPLEX_UNIT_SP, 14f)
        s.setSingleLine(true)
        s.ellipsize = android.text.TextUtils.TruncateAt.END
        s.alpha = 0f

        container.addView(s, FrameLayout.LayoutParams(FrameLayout.LayoutParams.WRAP_CONTENT, FrameLayout.LayoutParams.WRAP_CONTENT))
        container.addView(p, FrameLayout.LayoutParams(FrameLayout.LayoutParams.WRAP_CONTENT, FrameLayout.LayoutParams.WRAP_CONTENT))

        primaryText = p
        secondaryText = s
        root = container

        val type = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY
        } else {
            @Suppress("DEPRECATION")
            WindowManager.LayoutParams.TYPE_PHONE
        }

        val params = WindowManager.LayoutParams(
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.WRAP_CONTENT,
            type,
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or
                WindowManager.LayoutParams.FLAG_NOT_TOUCHABLE or
                WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN or
                WindowManager.LayoutParams.FLAG_LAYOUT_NO_LIMITS,
            PixelFormat.TRANSLUCENT
        )
        params.gravity = Gravity.TOP or Gravity.CENTER_HORIZONTAL
        params.y = dp(24)

        windowManager?.addView(container, params)
    }

    private fun removeView() {
        root?.let { view ->
            windowManager?.removeView(view)
        }
        root = null
        primaryText = null
        secondaryText = null
    }

    private fun updateTextInternal(text: String) {
        val container = root ?: return
        if (text.isBlank()) {
            container.visibility = View.GONE
            lastText = ""
            return
        }
        
        container.visibility = View.VISIBLE
        
        val p = primaryText ?: return
        val s = secondaryText ?: return
        
        if (text == lastText) return
        
        lastText = text
        
        s.text = p.text
        s.translationY = 0f
        s.alpha = 1f
        
        p.text = text
        p.translationY = dp(12).toFloat()
        p.alpha = 0f
        
        p.animate().cancel()
        p.animate().translationY(0f).alpha(1f).setDuration(220).start()
        
        s.animate().cancel()
        s.animate()
            .translationY(-dp(12).toFloat())
            .alpha(0f)
            .setDuration(220)
            .withEndAction {
                s.text = ""
                s.translationY = 0f
                s.alpha = 0f
            }
            .start()
    }

    private fun dp(v: Int): Int {
        return (v * resources.displayMetrics.density).toInt()
    }

    private fun applyColor() {
        primaryText?.setTextColor(textColor)
        secondaryText?.setTextColor((secondaryAlpha shl 24) or (textColor and 0xFFFFFF))
    }
}
