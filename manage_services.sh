#!/bin/bash

##############################################
# Music Server Service Manager
# 音乐服务器服务管理脚本
##############################################

SCRIPT_DIR="/www/wwwroot/juhemusic"
VENV_DIR="$SCRIPT_DIR/.venv"
PYTHON_LOG="$SCRIPT_DIR/server.log"
NODE_LOG="$SCRIPT_DIR/node.log"

# 颜色输出
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "=========================================="
echo "  Music Server Service Manager"
echo "=========================================="
echo ""

# 检查Python服务
check_python() {
    if pgrep -f "python server.py" > /dev/null; then
        echo -e "${GREEN}✓${NC} Python Server is running (PID: $(pgrep -f 'python server.py'))"
        return 0
    else
        echo -e "${RED}✗${NC} Python Server is NOT running"
        return 1
    fi
}

# 检查Node服务
check_node() {
    if pgrep -f "node qq_bridge_server.js" > /dev/null; then
        echo -e "${GREEN}✓${NC} QQ Bridge Server is running (PID: $(pgrep -f 'node qq_bridge_server.js'))"
        return 0
    else
        echo -e "${RED}✗${NC} QQ Bridge Server is NOT running"
        return 1
    fi
}

# 检查端口
check_ports() {
    if netstat -tuln | grep -q ":8002 "; then
        echo -e "${GREEN}✓${NC} Port 8002 is listening"
    else
        echo -e "${YELLOW}⚠${NC} Port 8002 is not open"
    fi
    
    if netstat -tuln | grep -q ":8003 "; then
        echo -e "${GREEN}✓${NC} Port 8003 is listening"
    else
        echo -e "${YELLOW}⚠${NC} Port 8003 is not open"
    fi
}

# 停止Python服务
stop_python() {
    echo -ne "Stopping Python Server... "
    pkill -f "python server.py"
    sleep 2
    if ! pgrep -f "python server.py" > /dev/null; then
        echo -e "${GREEN}OK${NC}"
    else
        echo -e "${RED}FAILED${NC}"
        echo "Force killing..."
        pkill -9 -f "python server.py"
        sleep 1
    fi
}

# 停止Node服务
stop_node() {
    echo -ne "Stopping QQ Bridge Server... "
    fuser -k 8003/tcp 2>/dev/null
    pkill -f "node qq_bridge_server.js"
    sleep 2
    if ! pgrep -f "node qq_bridge_server.js" > /dev/null; then
        echo -e "${GREEN}OK${NC}"
    else
        echo -e "${RED}FAILED${NC}"
        echo "Force killing..."
        pkill -9 -f "node qq_bridge_server.js"
        fuser -k -9 8003/tcp 2>/dev/null
        sleep 1
    fi
}

# 启动Python服务
start_python() {
    echo -ne "Starting Python Server... "
    cd "$SCRIPT_DIR"
    
    # 激活虚拟环境
    if [ -f "$VENV_DIR/bin/activate" ]; then
        source "$VENV_DIR/bin/activate"
    fi
    
    # 启动服务
    nohup python server.py > "$PYTHON_LOG" 2>&1 &
    sleep 3
    
    if pgrep -f "python server.py" > /dev/null; then
        echo -e "${GREEN}OK${NC} (PID: $(pgrep -f 'python server.py'))"
        return 0
    else
        echo -e "${RED}FAILED${NC}"
        echo "Check log: tail -f $PYTHON_LOG"
        return 1
    fi
}

# 启动Node服务
start_node() {
    echo -ne "Starting QQ Bridge Server... "
    cd "$SCRIPT_DIR"
    nohup node qq_bridge_server.js > "$NODE_LOG" 2>&1 &
    sleep 3
    
    if pgrep -f "node qq_bridge_server.js" > /dev/null; then
        echo -e "${GREEN}OK${NC} (PID: $(pgrep -f 'node qq_bridge_server.js'))"
        return 0
    else
        echo -e "${RED}FAILED${NC}"
        echo "Check log: tail -f $NODE_LOG"
        return 1
    fi
}

# 主菜单
show_menu() {
    echo ""
    echo "Select an option:"
    echo "  1) Check status"
    echo "  2) Start services"
    echo "  3) Stop services"
    echo "  4) Restart services"
    echo "  5) View Python log"
    echo "  6) View Node log"
    echo "  0) Exit"
    echo ""
    read -p "Enter choice [0-6]: " choice
}

# 状态检查
status() {
    echo "Checking service status..."
    echo ""
    check_python
    check_node
    echo ""
    check_ports
}

# 如果带参数则直接执行
if [ "$1" == "start" ]; then
    stop_python
    stop_node
    start_python
    start_node
    status
    exit 0
elif [ "$1" == "stop" ]; then
    stop_python
    stop_node
    exit 0
elif [ "$1" == "restart" ]; then
    stop_python
    stop_node
    sleep 1
    start_python
    start_node
    echo ""
    status
    exit 0
elif [ "$1" == "status" ]; then
    status
    exit 0
fi

# 交互式菜单
while true; do
    show_menu
    
    case $choice in
        1)
            status
            ;;
        2)
            start_python
            start_node
            echo ""
            status
            ;;
        3)
            stop_python
            stop_node
            ;;
        4)
            stop_python
            stop_node
            sleep 1
            start_python
            start_node
            echo ""
            status
            ;;
        5)
            echo "Press Ctrl+C to exit log view"
            sleep 2
            tail -f "$PYTHON_LOG"
            ;;
        6)
            echo "Press Ctrl+C to exit log view"
            sleep 2
            tail -f "$NODE_LOG"
            ;;
        0)
            echo "Goodbye!"
            exit 0
            ;;
        *)
            echo -e "${RED}Invalid option${NC}"
            ;;
    esac
done
