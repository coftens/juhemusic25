#!/bin/bash
set -e

# Ensure we're running on macOS
if [[ "$(uname)" != "Darwin" ]]; then
  echo "❌ This script must be run on macOS."
  exit 1
fi

# Ensure version is provided
if [ -z "$1" ]; then
  echo "❌ Usage: $0 <version>"
  exit 1
fi

readonly version="$1"
readonly app_name="MusicLyricApp"
readonly output_root="publish"
readonly targets=("osx-x64" "osx-arm64")

any_processed=false

for target in "${targets[@]}"; do
  archive_mid_path="$output_root/${app_name}-${version}-${target}-mid.tar.gz"

  if [ ! -f "$archive_mid_path" ]; then
    echo "⚠️  Archive not found for $target, skipping..."
    continue
  fi

  echo -e "\n📦 Processing: $target"

  extract_dir="$output_root/$target"
  mkdir -p "$extract_dir"
  tar -xzf "$archive_mid_path" -C "$extract_dir"

  echo "🍎 Creating .app bundle..."
  app_bundle="$output_root/${app_name}-${version}-${target}.app"
  contents_dir="$app_bundle/Contents"
  macos_dir="$contents_dir/MacOS"
  resources_dir="$contents_dir/Resources"

  mkdir -p "$macos_dir" "$resources_dir"
  cp -R "$extract_dir"/* "$macos_dir/"
  chmod +x "$macos_dir/$app_name"

  # 复制图标文件到 Resources 目录
  icon_source="$extract_dir/Resources/app-logo.icns"
  if [ -f "$icon_source" ]; then
    cp "$icon_source" "$resources_dir/"
    echo "🎨 Copied icon to $resources_dir"
  else
    echo "⚠️ Icon file not found at $icon_source"
  fi

  # Create Info.plist with icon reference
  cat > "$contents_dir/Info.plist" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN"
 "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>CFBundleExecutable</key>
  <string>$app_name</string>
  <key>CFBundleIdentifier</key>
  <string>com.github.jitwxs.$app_name</string>
  <key>CFBundleName</key>
  <string>$app_name</string>
  <key>CFBundleVersion</key>
  <string>$version</string>
  <key>CFBundlePackageType</key>
  <string>APPL</string>
  <key>CFBundleIconFile</key>
  <string>app-logo.icns</string>
</dict>
</plist>
EOF

  echo "🔐 Signing .app with ad-hoc identity..."
  if ! codesign --force --deep --timestamp=none --sign - "$app_bundle"; then
    echo "❌ codesign failed. Ensure Xcode Command Line Tools are installed."
    exit 1
  fi

  final_archive="$output_root/${app_name}-${version}-${target}.tar.gz"
  echo "🗜️  Compressing .app to $final_archive..."
  tar -czf "$final_archive" -C "$output_root" "$(basename "$app_bundle")"

  echo "🧹 Cleaning up intermediate files..."
  rm -rf "$app_bundle" "$extract_dir" "$archive_mid_path"

  any_processed=true
done

if [[ "$any_processed" == true ]]; then
  echo -e "\n✅ All done! Final archives available in '$output_root/'"
else
  echo "❌ No valid archives found. Nothing was processed."
fi
