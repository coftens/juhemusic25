package com.example.flutter_app

import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.Settings
import com.ryanheise.audioservice.AudioServiceActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : AudioServiceActivity() {
    private val channel = "floating_lyrics"

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, channel).setMethodCallHandler { call, result ->
            when (call.method) {
                "checkPermission" -> {
                    val ok = Build.VERSION.SDK_INT < Build.VERSION_CODES.M || Settings.canDrawOverlays(this)
                    result.success(ok)
                }
                "requestPermission" -> {
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                        val intent = Intent(
                            Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
                            Uri.parse("package:$packageName")
                        )
                        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                        startActivity(intent)
                    }
                    result.success(true)
                }
                "openOverlaySettings" -> {
                    try {
                        val vendor = (call.argument<String>("vendor") ?: "").lowercase()
                        val ok = openVendorOverlaySettings(if (vendor.isNotBlank()) vendor else Build.MANUFACTURER.lowercase())
                        if (!ok && Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                            val intent = Intent(
                                Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
                                Uri.parse("package:$packageName")
                            )
                            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                            try {
                                startActivity(intent)
                            } catch (e: Exception) {
                                // 如果无法启动任何设置页面，静默处理
                            }
                        }
                    } catch (e: Exception) {
                        // 记录异常但不抛出
                        android.util.Log.w("FloatingLyrics", "openOverlaySettings error", e)
                    }
                    result.success(true)
                }
                "start" -> {
                    val text = call.argument<String>("text") ?: ""
                    val colorArg = call.argument<Any>("color")
                    val color = when (colorArg) {
                        is Long -> colorArg.toInt()
                        is Int -> colorArg
                        else -> 0xFFFFEDA8.toInt()
                    }
                    val intent = Intent(this, FloatingLyricsService::class.java)
                    intent.putExtra("text", text)
                    intent.putExtra("color", color)
                    startService(intent)
                    result.success(true)
                }
                "update" -> {
                    val text = call.argument<String>("text") ?: ""
                    FloatingLyricsService.updateText(text)
                    result.success(true)
                }
                "updateColor" -> {
                    val colorArg = call.argument<Any>("color")
                    val color = when (colorArg) {
                        is Long -> colorArg.toInt()
                        is Int -> colorArg
                        else -> 0xFFFFEDA8.toInt()
                    }
                    FloatingLyricsService.updateColor(color)
                    result.success(true)
                }
                "stop" -> {
                    FloatingLyricsService.stop(this)
                    result.success(true)
                }
                else -> result.notImplemented()
            }
        }
    }

    private fun openVendorOverlaySettings(vendor: String): Boolean {
        val intents = when (vendor) {
            "xiaomi", "redmi" -> listOf(
                Intent("miui.intent.action.APP_PERM_EDITOR").setClassName(
                    "com.miui.securitycenter",
                    "com.miui.permcenter.permissions.PermissionsEditorActivity"
                ).putExtra("extra_pkgname", packageName),
                Intent("miui.intent.action.APP_PERM_EDITOR").setClassName(
                    "com.miui.securitycenter",
                    "com.miui.permcenter.permissions.AppPermissionsEditorActivity"
                ).putExtra("extra_pkgname", packageName)
            )
            "huawei", "honor" -> listOf(
                Intent().setClassName(
                    "com.huawei.systemmanager",
                    "com.huawei.permissionmanager.ui.MainActivity"
                ),
                Intent().setClassName(
                    "com.huawei.systemmanager",
                    "com.huawei.systemmanager.addviewmonitor.AddViewMonitorActivity"
                )
            )
            "oppo" -> listOf(
                Intent().setClassName(
                    "com.coloros.safecenter",
                    "com.coloros.safecenter.permission.floatwindow.FloatWindowListActivity"
                ),
                Intent().setClassName(
                    "com.oppo.safe",
                    "com.oppo.safe.permission.floatwindow.FloatWindowListActivity"
                )
            )
            "vivo", "iqoo" -> listOf(
                Intent().setClassName(
                    "com.vivo.permissionmanager",
                    "com.vivo.permissionmanager.activity.SoftPermissionDetailActivity"
                ).putExtra("packagename", packageName),
                Intent().setClassName(
                    "com.iqoo.secure",
                    "com.iqoo.secure.ui.phoneoptimize.FloatWindowManager"
                )
            )
            "meizu" -> listOf(
                Intent().setClassName(
                    "com.meizu.safe",
                    "com.meizu.safe.security.AppSecActivity"
                ).putExtra("packageName", packageName)
            )
            "samsung" -> listOf(
                Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).setData(Uri.parse("package:$packageName"))
            )
            "oneplus" -> listOf(
                Intent().setClassName(
                    "com.oneplus.security",
                    "com.oneplus.security.chainlaunch.view.ChainLaunchAppListActivity"
                ),
                Intent().setClassName(
                    "com.oneplus.security",
                    "com.oneplus.security.settings.AppPermsActivity"
                ).putExtra("packageName", packageName)
            )
            else -> emptyList()
        }

        for (intent in intents) {
            if (intent.resolveActivity(packageManager) != null) {
                intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                startActivity(intent)
                return true
            }
        }
        return false
    }
}
