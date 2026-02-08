# 部署指南 (Deployment Guide)

你已经将客户端连接地址修改为 `8.159.155.226:3000`。
现在你需要将 `together_server` 文件夹上传到该服务器并运行。

## 步骤 1: 上传代码

请将本地桌面的 `together_server` 文件夹上传到服务器。
你可以使用 FTP 工具 (如 FileZilla) 或 SCP 命令。

**SCP 命令示例 (在本地 CMD/PowerShell 运行):**
```powershell
# 假设你有服务器的 SSH 权限，且用户名是 root
scp -r C:\Users\Coftens\Desktop\xiangmu\music\together_server root@8.159.155.226:/root/
```

## 步骤 2: 服务器端运行

登录到你的服务器，进入文件夹并启动服务。

```bash
# SSH 登录
ssh root@8.159.155.226

# 安装 Node.js (如果还没安装)
# Ubuntu/Debian:
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt-get install -y nodejs

# 进入目录
cd /root/together_server

# 安装依赖
npm install

# 启动服务 (前台运行测试)
node server.js

## 步骤 3: 保持后台运行 (必读)

推荐使用 **PM2** 工具，它可以让服务崩溃后自动重启，并支持开机自启。

### 方法 A: 使用 PM2 (推荐)

1.  **全局安装 PM2**:
    ```bash
    npm install -g pm2
    ```

2.  **启动服务**:
    ```bash
    pm2 start server.js --name "listen-together"
    ```
    *此时服务已经在后台运行了。*

3.  **常用管理命令**:
    *   查看状态: `pm2 status`
    *   查看日志: `pm2 logs listen-together` (按 Ctrl+C 退出日志查看)
    *   停止服务: `pm2 stop listen-together`
    *   重启服务: `pm2 restart listen-together`

4.  **设置开机自启 (可选)**:
    ```bash
    pm2 startup
    pm2 save
    ```

### 方法 B: 使用 nohup (简单但在后台不可控)
如果你不想安装 pm2，可以使用系统自带的 `nohup`：
```bash
nohup node server.js > output.log 2>&1 &
```
这将把输出重定向到 `output.log` 并在后台运行。
1.  **防火墙**: 确保服务器的防火墙 (Security Group / UFW) 开放了 **3000** 端口 (TCP)。
2.  **测试**: 服务器启动后，在浏览器访问 `http://8.159.155.226:3000/socket.io/socket.io.js`，如果出现代码，说明服务正常。
