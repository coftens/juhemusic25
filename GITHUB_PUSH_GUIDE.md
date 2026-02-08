# GitHub 推送教程

本教程将指导你如何将代码推送到 GitHub 仓库。

---

## 📋 目录

1. [前置准备](#前置准备)
2. [首次推送](#首次推送)
3. [日常推送流程](#日常推送流程)
4. [常见场景处理](#常见场景处理)
5. [问题排查](#问题排查)
6. [进阶技巧](#进阶技巧)

---

## 前置准备

### 1. 安装 Git

下载安装：https://git-scm.com/download/win

验证安装：
```bash
git --version
```

### 2. 配置 Git 用户信息

```bash
# 设置用户名
git config --global user.name "你的用户名"

# 设置邮箱
git config --global user.email "your.email@example.com"

# 查看当前配置
git config --list
```

### 3. 生成 SSH 密钥（推荐）

```bash
# 生成 SSH 密钥
ssh-keygen -t rsa -b 4096 -C "your.email@example.com"

# 查看公钥内容
cat ~/.ssh/id_rsa.pub
```

将公钥添加到 GitHub：
1. 登录 GitHub → Settings → SSH and GPG keys
2. 点击 "New SSH key"
3. 粘贴公钥内容并保存

---

## 首次推送

### 场景 1：本地已有项目，需要推送到新建的 GitHub 仓库

```bash
# 1. 初始化 Git 仓库（如果还没有）
git init

# 2. 添加远程仓库地址
git remote add origin https://github.com/用户名/仓库名.git
# 或使用 SSH（推荐）
git remote add origin git@github.com:用户名/仓库名.git

# 3. 查看远程仓库
git remote -v

# 4. 添加所有文件到暂存区
git add .

# 5. 提交到本地仓库
git commit -m "Initial commit"

# 6. 推送到 GitHub（首次需要设置上游分支）
git push -u origin master
# 或如果主分支是 main
git push -u origin main
```

### 场景 2：克隆现有的 GitHub 仓库

```bash
# 克隆仓库
git clone https://github.com/用户名/仓库名.git

# 进入项目目录
cd 仓库名

# 现在可以直接修改文件并推送
```

---

## 日常推送流程

这是你最常用的推送流程：

### 方法 1：标准三步流程

```bash
# 1. 查看当前状态（可选）
git status

# 2. 添加修改的文件到暂存区
git add .                    # 添加所有修改
git add 文件名.txt           # 添加指定文件
git add 目录名/              # 添加指定目录

# 3. 提交到本地仓库
git commit -m "描述本次修改的内容"

# 4. 推送到 GitHub
git push
```

### 方法 2：一行命令推送（适合快速提交）

```bash
# 添加、提交、推送一气呵成
git add -A ; git commit -m "提交说明" ; git push
```

### 提交信息规范

建议的 commit 信息格式：

```bash
git commit -m "Add: 新增功能描述"          # 新增功能
git commit -m "Fix: 修复问题描述"          # 修复 bug
git commit -m "Update: 更新内容描述"       # 更新功能
git commit -m "Improve: 改进内容描述"      # 改进优化
git commit -m "Remove: 删除内容描述"       # 删除功能
git commit -m "Refactor: 重构内容描述"     # 代码重构
```

示例：
```bash
git commit -m "Add: AR live lyrics feature and sync updates"
git commit -m "Fix: add Flutter app lib/ source files (update .gitignore)"
git commit -m "Update: Android APK link to official v7.20.0 release"
```

---

## 常见场景处理

### 1. 查看当前状态

```bash
# 查看工作区状态
git status

# 简洁格式查看
git status --short
```

输出说明：
- `??` 未跟踪的新文件
- `M` 已修改的文件
- `A` 新添加到暂存区的文件
- `D` 已删除的文件

### 2. 撤销修改

```bash
# 撤销工作区的修改（未 add）
git checkout -- 文件名

# 撤销暂存区的修改（已 add 但未 commit）
git reset HEAD 文件名

# 撤销上一次 commit（保留修改）
git reset --soft HEAD^

# 完全撤销上一次 commit（删除修改）
git reset --hard HEAD^
```

### 3. 查看提交历史

```bash
# 查看详细历史
git log

# 查看简洁历史
git log --oneline

# 查看最近 5 条记录
git log -5 --oneline

# 查看图形化分支历史
git log --oneline --graph --all
```

### 4. 强制推送（谨慎使用）

```bash
# 强制覆盖远程仓库（会丢失远程的其他提交）
git push -f origin master

# 更安全的强制推送（确保不会覆盖其他人的提交）
git push --force-with-lease
```

### 5. 删除远程文件但保留本地文件

```bash
# 删除远程跟踪，但保留本地文件
git rm --cached 文件名

# 删除整个目录
git rm -r --cached 目录名/

# 提交并推送
git commit -m "Remove: 文件名 from remote repository"
git push
```

### 6. 添加被 .gitignore 忽略的文件

```bash
# 强制添加被忽略的文件
git add -f 文件名

# 强制添加整个目录
git add -f 目录名/
```

### 7. 批量推送所有更改

```bash
# 添加所有更改（包括新文件、修改、删除）
git add -A

# 提交并推送
git commit -m "Bulk commit: 描述所有更改"
git push
```

---

## 问题排查

### 问题 1：推送被拒绝（rejected）

**错误信息：**
```
! [rejected]        master -> master (fetch first)
error: failed to push some refs
```

**原因：** 远程仓库有你本地没有的提交

**解决方法：**
```bash
# 1. 先拉取远程更新
git pull origin master

# 2. 如果有冲突，解决冲突后
git add .
git commit -m "Merge: 解决冲突"

# 3. 重新推送
git push
```

### 问题 2：.gitignore 不生效

**原因：** 文件已经被 Git 跟踪

**解决方法：**
```bash
# 1. 清除 Git 缓存
git rm -r --cached .

# 2. 重新添加所有文件（会应用新的 .gitignore）
git add .

# 3. 提交
git commit -m "Fix: apply .gitignore rules"
git push
```

### 问题 3：文件显示为子模块（submodule）

**症状：** 目录显示为 160000 模式，克隆后目录为空

**解决方法：**
```bash
# 1. 移除子模块引用
git rm --cached 目录名

# 2. 删除 .gitmodules 文件（如果存在）
rm .gitmodules

# 3. 重新添加为普通目录
git add 目录名/

# 4. 提交并推送
git commit -m "Fix: convert submodule to regular directory"
git push
```

### 问题 4：权限被拒绝（Permission denied）

**错误信息：**
```
Permission denied (publickey)
```

**解决方法：**
```bash
# 1. 检查 SSH 密钥
ssh -T git@github.com

# 2. 如果没有配置，生成并添加 SSH 密钥
ssh-keygen -t rsa -b 4096 -C "your.email@example.com"

# 3. 将公钥添加到 GitHub
cat ~/.ssh/id_rsa.pub
```

或改用 HTTPS：
```bash
# 更改远程仓库地址为 HTTPS
git remote set-url origin https://github.com/用户名/仓库名.git
```

### 问题 5：需要输入用户名密码（HTTPS）

**GitHub 已停止支持密码认证，需要使用 Personal Access Token**

1. 生成 Token：GitHub → Settings → Developer settings → Personal access tokens
2. 推送时使用 Token 作为密码
3. 或配置凭据助手：
```bash
# Windows
git config --global credential.helper wincred

# 下次推送时输入用户名和 Token，之后会自动保存
```

---

## 进阶技巧

### 1. 创建和推送分支

```bash
# 创建新分支
git branch 分支名

# 切换到新分支
git checkout 分支名

# 或一步创建并切换
git checkout -b 分支名

# 推送分支到远程
git push -u origin 分支名

# 查看所有分支
git branch -a
```

### 2. 合并分支

```bash
# 切换到主分支
git checkout master

# 合并指定分支到当前分支
git merge 分支名

# 推送合并结果
git push
```

### 3. 标签管理

```bash
# 创建标签
git tag v1.0.0

# 创建带注释的标签
git tag -a v1.0.0 -m "Release version 1.0.0"

# 推送标签到远程
git push --tags

# 查看所有标签
git tag -l
```

### 4. 查看差异

```bash
# 查看工作区和暂存区的差异
git diff

# 查看暂存区和最后一次提交的差异
git diff --cached

# 查看两个分支的差异
git diff branch1 branch2
```

### 5. 暂存工作进度

```bash
# 暂存当前修改
git stash

# 查看暂存列表
git stash list

# 恢复暂存
git stash pop

# 恢复指定暂存
git stash apply stash@{0}
```

### 6. 批量操作技巧

```bash
# 查看简洁状态并查看最后一次提交
git status --short ; git log -1 --oneline

# 添加、提交、推送一条龙
git add -A && git commit -m "提交信息" && git push

# 拉取、合并、推送
git pull && git add . && git commit -m "Merge updates" && git push
```

### 7. 检查哪些规则导致文件被忽略

```bash
# 检查文件被哪个 .gitignore 规则忽略
git check-ignore -v 文件路径

# 示例
git check-ignore -v flutter_app/lib/main.dart
```

---

## 🎯 快速参考

### 最常用命令

```bash
# 查看状态
git status

# 日常推送三部曲
git add .
git commit -m "提交说明"
git push

# 快速推送（一行）
git add -A ; git commit -m "提交说明" ; git push

# 查看历史
git log --oneline

# 拉取更新
git pull

# 撤销修改
git checkout -- 文件名
```

### .gitignore 常用规则

创建 `.gitignore` 文件：

```gitignore
# 忽略所有 .log 文件
*.log

# 忽略 node_modules 目录
node_modules/

# 忽略根目录的 lib 文件夹（但不忽略子目录的 lib）
/lib/

# 忽略所有 .env 文件
.env
.env.local

# 忽略构建输出
build/
dist/

# 忽略 IDE 配置
.vscode/
.idea/

# 但强制包含某些文件
!important.log
```

---

## 📚 相关资源

- [Git 官方文档](https://git-scm.com/doc)
- [GitHub 官方帮助](https://docs.github.com)
- [Git 可视化学习](https://learngitbranching.js.org/)
- [常见 .gitignore 模板](https://github.com/github/gitignore)

---

## 💡 实战案例（基于本项目）

### 案例 1：修复 flutter_app 从子模块转为普通目录

```bash
# 问题：flutter_app 目录克隆后为空
git ls-files -s flutter_app  # 显示 160000（子模块标识）

# 解决步骤
git rm --cached flutter_app
git add flutter_app/
git commit -m "Fix: convert flutter_app from submodule to regular directory"
git push
```

### 案例 2：修复 .gitignore 导致源代码未被跟踪

```bash
# 问题：lib/ 目录被忽略
git check-ignore -v flutter_app/lib/main.dart
# 输出：.gitignore:5:lib/    flutter_app/lib/main.dart

# 解决步骤
# 1. 修改 .gitignore，将 lib/ 改为 /lib/
# 2. 添加被忽略的文件
git add flutter_app/lib/
git commit -m "Fix: add Flutter app lib/ source files (update .gitignore)"
git push
```

### 案例 3：推送 AR 歌词功能及所有更改

```bash
# 查看所有未跟踪的文件
git status --short

# 批量添加所有更改
git add -A

# 提交并推送
git commit -m "Add: AR live lyrics feature and sync updates"
git push
```

---

**提示：** 推送前记得先用 `git status` 检查一下要提交的内容，确保没有敏感信息（如密码、密钥等）。

**祝你使用愉快！** 🚀
