#!/usr/bin/env bash
set -euo pipefail

case "${1:-status}" in
  start)
    systemctl start voice-guardian-queue.service voice-guardian-scheduler.timer voice-guardian-discord.service
    ;;
  stop)
    systemctl stop voice-guardian-discord.service voice-guardian-queue.service voice-guardian-scheduler.timer
    ;;
  restart)
    systemctl restart voice-guardian-queue.service voice-guardian-discord.service
    ;;
  status)
    systemctl status voice-guardian-queue.service voice-guardian-scheduler.timer voice-guardian-discord.service --no-pager
    ;;
  logs)
    journalctl -u voice-guardian-discord.service -u voice-guardian-queue.service -f
    ;;
  deploy)
    bash /opt/voice-guardian/scripts/linux/deploy.sh
    ;;
  *)
    echo "Usage: $0 {start|stop|restart|status|logs|deploy}"
    exit 2
    ;;
esac
