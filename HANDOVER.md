# Project Handover: Yuewang Music (悦往音乐)

**Date:** 2026-02-05
**Current Version:** v7.19.0

## 1. Project Overview
**Yuewang Music (formerly Juhe Music)** is a Flutter-based music aggregation app.
The most recent major feature addition is **"Listen Together" (一起听)**, which allows users to create rooms and synchronize music playback in real-time.

## 2. Technology Stack
- **Client**: Flutter (Dart)
  - Location: `flutter_app/`
  - Core Player: `just_audio`, `just_audio_background`
  - State Management: `Provider` / `ValueNotifier`
- **Together Server**: Node.js (Socket.IO)
  - Location: `together_server/`
  - Purpose: Manages rooms and relays playback state between Host and Guests.
- **Backend**: PHP (Music API)
  - Location: `api/` (External/Legacy)

## 3. Key Features & Recent Changes

### 3.1 Listen Together (一起听)
*   **Architecture**: Socket.IO based real-time communication.
*   **Key Files**:
    *   `lib/together/together_service.dart`: Core client logic. Handles socket connection, state syncing, and heartbeat.
    *   `lib/together/together_page.dart`: The room UI.
    *   `together_server/server.js`: The Node.js server handling `join_room`, `host_action`, `guest_sync`.
*   **Logic**:
    *   **Host**: Broadcasts `play`, `pause`, `seek`, and `change_song` events. Sends a heartbeat every 5s.
    *   **Guest**: Listens to events. **Input controls are locked**. Auto-advance is **disabled** (`PlayerService.disableAutoAdvance = true`) to force synchronization with the host.

### 3.2 Branding
*   **Name**: Changed from "Juhe Music" to "悦往音乐" in the UI (Sidebar).
*   **Logo**: Located in `logo/` and `release_page/`.

### 3.3 Versioning
*   **Current Version**: `7.19.0` (Matches iOS version).
*   **Critical**: When updating version, ensure **BOTH** `pubspec.yaml` AND `lib/app/update_checker.dart` are updated. Mismatches cause infinite update loops.

## 4. Workarounds / "Gotchas"
1.  **Guest Auto-Next**: We deliberately disabled the player's internal auto-advance for guests. The guest player pauses at the end of a track and waits for the Host to send a "change song" command.
    *   *See Code*: `PlayerService._handleTrackCompleted`
2.  **iOS Build**: We use a **No-Codesign** workflow on Codemagic to generate an unsigned `.ipa`, which is likely resigned later or used on jailbroken/Testing devices.
    *   *Config*: `flutter_app/codemagic.yaml`

## 5. Build & Deployment

### 5.1 Android
```bash
cd flutter_app
flutter build apk --release
# Output: flutter_app/build/app/outputs/flutter-apk/app-release.apk
```

### 5.2 iOS (Codemagic)
The project is configured for Codemagic. Trigger the **ios-release-workflow**.
*   It builds an unsigned `.ipa`.
*   Artifacts are emailed to `zhaozheng982@gmail.com` or downloaded from Codemagic.

### 5.3 Together Server
Deployed on `8.159.155.226`.
```bash
cd together_server
npm install
node server.js
```
*   *Note*: Ensure firewall allows port `3000`.

### 5.4 Release Page
A static HTML download page is in `release_page/`.
*   Open `release_page/index.html` to test.
*   Contains links to the verify v7.19.0 APK and IPA.

## 6. Next Steps for New AI
1.  **Monitor "Listen Together"**: Watch for desync issues on poor networks. The current implementation uses simple timestamp-based latency compensation.
2.  **iOS Signing**: If proper App Store distribution is needed, `codemagic.yaml` needs to be reconfigured with valid certificates and profiles.
