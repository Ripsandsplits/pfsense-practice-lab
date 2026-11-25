#!/bin/sh
# pfSense CE CCDC practice lab 1.0.0
set -eu
PATH=/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin
export PATH
umask 077
case "${1:-help}" in
  help|--help|-h)
    cat <<'USAGE'
pfSense practice lab 1.0.0 -- disposable, isolated VM only

  sh lab.sh preflight --interface lan [--port 46290]
  sh lab.sh seed --interface lan [--port 46290]         # preview
  sh lab.sh seed --interface lan [--port 46290] --apply --lab-confirmed --console-ready
  sh lab.sh status                                    # instructor inventory
  sh lab.sh grade                                     # 0 clear; 2 artifacts remain
  sh lab.sh reset                                     # preview
  sh lab.sh reset --apply
  sh lab.sh verify-reset                              # 0 clear; 2 artifacts remain

Read README.md first. Keep a powered-off VM snapshot and direct console.
State and sensitive backups: /conf/ccdc-practice (root only).
Reset removes recorded exercise resources; revert the VM snapshot for an exact reset.
USAGE
    exit 0 ;;
esac
[ "$(uname -s)" = FreeBSD ] || { echo 'ERROR: run on the disposable pfSense VM.' >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || { echo 'ERROR: root is required.' >&2; exit 1; }
[ -f /etc/inc/config.inc ] && [ -f /conf/config.xml ] || { echo 'ERROR: pfSense installation not found.' >&2; exit 1; }
[ -x /usr/local/bin/php ] && [ -x /usr/bin/lockf ] || { echo 'ERROR: native PHP and lockf are required.' >&2; exit 1; }
LAB_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)
exec /usr/bin/lockf -k -t 0 /var/run/ccdc-practice.lock /usr/local/bin/php -f "$LAB_DIR/lib/native.php" -- "$@"
