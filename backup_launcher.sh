#!/bin/bash
# Script launcher para ejecutar backup en background en Ubuntu/Linux
nohup /usr/bin/php "$(dirname "$0")/backup_worker.php" "$1" "$2" > /dev/null 2>&1 &