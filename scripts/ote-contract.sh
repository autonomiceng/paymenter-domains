#!/usr/bin/env bash
# MIT, Copyright (c) 2026 Autonomic Engineering.
set -euo pipefail
if [[ "${1:-}" != --self-test ]]; then
  utc_hour=$(date -u +%H)
  if (( 10#$utc_hour >= 18 && 10#$utc_hour < 21 )); then
    echo 'OTE blocked from 18:00 through 20:59:59 UTC.' >&2
    exit 2
  fi
fi
exec python3 "$(dirname "$(realpath "$0")")/ote-contract.py" "$@"
