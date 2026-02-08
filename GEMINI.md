# Project Memory: Music App

**Last Updated:** 2026-02-04

## Project Overview
- **Type:** Music Aggregation App (likely "Juhe Music").
- **Stack:** 
  - **Frontend:** Flutter (`flutter_app/`).
  - **Backend:** PHP (`api/`, `php/`), Python scripts (parsers).
- **Key Integrations:** 
  - Netease Cloud Music (wyy)
  - QQ Music
  - Qishui Music
  - Shorebird (referenced in CI config)

## Build & Deployment (iOS)
- **CI Tool:** Codemagic.
- **Current Config (`codemagic.yaml`):** 
  - Defines `ios-release-workflow`.
  - Currently configured for **Unsigned** builds (`no-codesign`, `CODE_SIGNING_ALLOWED=NO`).
  - Manually zips `.app` into `.ipa`.
- **Documentation (`codemagic_build_guide.md`):**
  - specific guide for Windows users.
  - Recommends **Automatic iOS Signing** via App Store Connect API.
  - Mentions Firebase App Distribution.

## Current Context
- User is setting up or debugging the Codemagic build process.
- Discrepancy exists between the "No Codesign" implementation in YAML and the "Automatic Signing" instruction in the guide.
