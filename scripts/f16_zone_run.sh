#!/usr/bin/env bash
# Jalankan SATU zona (yang belum done) dari f16_zones.tsv, foreground <=500s.
set -eu
cd /home/z/my-project/download/zef-framework
DONE=build/f16-zones-done.tsv
touch "$DONE"
ZONE=$(python3 - <<'PY'
done_names = set()
try:
    for ln in open('build/f16-zones-done.tsv'):
        if ln.strip():
            done_names.add(ln.split('|')[0])
except FileNotFoundError:
    pass
for ln in open('scripts/f16_zones.tsv'):
    ln = ln.strip()
    if not ln or ln.startswith('#'):
        continue
    name, filt = ln.split('|', 1)
    if name not in done_names:
        print(f"{name}|{filt}")
        break
PY
)
if [[ -z "$ZONE" ]]; then
  echo "ALL-ZONES-DONE"
  exit 0
fi
NAME="${ZONE%%|*}"
FILTER="${ZONE#*|}"
echo "RUNNING ZONE: $NAME ($FILTER)"
START=$(date +%s)
if timeout 580 scripts/infection_chunk.sh "$NAME" "$FILTER" 0 < /dev/null > "build/f16-zone-$NAME.out" 2>&1; then
  STATUS=OK
else
  STATUS=FAIL
fi
END=$(date +%s)
python3 - "$NAME" "$FILTER" "$STATUS" "$((END-START))" <<'PY'
import json, sys
name, filt, status, dur = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4]
total = killed = escaped = nc = err = syn = to = 0
try:
    import re
    txt = open(f'build/infection-summary-{name}.json').read()
    def grab(label):
        m = re.search(rf'^{label}:\s+(\d+)$', txt, re.MULTILINE)
        return int(m.group(1)) if m else 0
    total = grab('Total')
    killed = grab('Killed by Test Framework') + grab('Killed by Static Analysis')
    escaped = grab('Escaped')
    nc = grab('Not Covered')
    err = grab('Errored')
    syn = grab('Syntax Errors')
    to = grab('Timed Out')
except Exception as e:
    print('  (no summary: %s)' % e)
line = f"{name}|{filt}|{status}|{dur}s|total={total}|killed={killed}|escaped={escaped}|nc={nc}|err={err}|syn={syn}|to={to}"
open('build/f16-zones-done.tsv', 'a').write(line + '\n')
print(line)
PY
