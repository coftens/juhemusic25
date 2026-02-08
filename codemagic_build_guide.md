# Codemagic iOS 自动化构建指南

如果你在 Windows 环境下开发，**Codemagic** 是将 Flutter 项目打包成 `.ipa` 文件的最佳方案。它通过云端 Mac 服务器自动完成编译和签名。

## 🛠️ 第一步：前置准备

在使用 Codemagic 之前，你需要准备好以下要素：

1.  **代码仓库**：将你的 Flutter 代码上传到 GitHub、GitLab 或 Bitbucket。
2.  **Apple 开发者账号**：即使使用自动化工具，你也必须拥有一个 Apple 开发者账号（$99/年）来生成签名文件。
3.  **App Store Connect**：确保你已经在 App Store Connect 中创建了对应的 App 条目（哪怕只填了包名）。

---

## 🚀 第二步：配置 Codemagic

1.  **注册与连接**：
    - 访问 [Codemagic.io](https://codemagic.io/) 并使用 GitHub 账号登录。
    - 在仪表盘点击 **Add application**，选择你的音乐 App 仓库。
2.  **选择构建平台**：
    - 在项目设置中，选择 **Workflow Editor**（可视化编辑器，适合新手）。
    - 在 **Build platform** 中勾选 **iOS**。
3.  **配置构建设置**：
    - **Build for**：选择 **Release** 模式。
    - **Flutter version**：选择与你本地一致的版本（推荐 `Stable`）。
    - **Xcode version**：推荐选择最新的稳定版（如 `Xcode 16.x`）。

---

## 🔐 第三步：iOS 签名配置 (核心环节)

这是最容易卡住的一步。Codemagic 提供 **Automatic iOS signing**（自动签名）：

1.  **连接 App Store Connect**：
    - 在 Codemagic 设置中找到 **Team settings** -> **Integrations**。
    - 连接你的 **App Store Connect API Key**。
2.  **在工作流中启用**：
    - 返回你的项目工作流，找到 **Distribution** -> **iOS code signing**。
    - 选择 **Automatic**。
    - Codemagic 会自动从苹果后台下载证书（Certificate）和描述文件（Provisioning Profile）。

---

## 📦 第四步：同步到 Firebase (可选)

如果你想打完包自动发给 Firebase：

1.  在 **Distribution** 选项卡下找到 **Firebase App Distribution**。
2.  输入你的 **Firebase App ID**。
3.  上传你的 **Firebase Service Account JSON**（在 Firebase 项目设置 -> 服务账号中生成）。
4.  指定测试组名称（如 `testers`）。

---

## 🏗️ 第五步：开始构建

点击右上角的 **Start new build**。

- **过程**：Codemagic 会拉取代码 -> 运行 `flutter build ipa` -> 自动签名 -> 生成 `.ipa` 文件。
- **结果**：构建成功后，你可以直接在 Codemagic 页面下载 `.ipa` 文件，或者如果你配置了 Firebase，你的测试人员会自动收到下载通知。

> [!TIP]
> **关于费用**：Codemagic 对个人开发者有免费额度（每月约 500 分钟构建时间），对于单人协作的小项目完全足够。

> [!WARNING]
> **Windows 用户注意**：虽然环境是自动化的，但在首次配置时，你可能仍然需要处理一些 iOS 端的特定文件（如 `Info.plist`）。如果遇到 `CocoaPods` 相关的错误，请确保你的 `ios/Podfile.lock` 已提交到仓库。
