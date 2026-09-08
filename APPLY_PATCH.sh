#!/bin/sh
set -eu
python3 "$(dirname "$0")/APPLY_PATCH.py" "${1:-.}"
