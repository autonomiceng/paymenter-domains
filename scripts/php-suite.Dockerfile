# Linux amd64, Python 3.13.18 on Debian trixie.
FROM python:3.13-slim-trixie@sha256:48d884813cf44e927cf64c5529dd630dd3b18338c6dc727991eef9b71e934a20
# Freeze the distribution libraries as well as the PHP archives in the lock.
RUN rm /etc/apt/sources.list.d/debian.sources && \
    echo 'deb [check-valid-until=no] https://snapshot.debian.org/archive/debian/20261107T000000Z trixie main' >/etc/apt/sources.list && \
    apt-get update && apt-get install -y --no-install-recommends \
        git time libargon2-1 libcurl4t64 libgd3 libsodium23 libxslt1.1 libonig5 libzip5 unzip zip && \
    rm -rf /var/lib/apt/lists/*
COPY php-runtime-packages.json /opt/php-suite/lock.json
COPY archives/ /opt/php-suite/archives/
RUN cd /opt/php-suite && python3 - <<'PY'
import hashlib, json
from pathlib import Path
for package in json.loads(Path('lock.json').read_text()):
    path = Path('archives') / package['url'].rsplit('/', 1)[1]
    if hashlib.file_digest(path.open('rb'), 'sha256').hexdigest() != package['sha256']:
        raise SystemExit('Incorrect archive: '+path.name)
PY
RUN mkdir /opt/php-suite/runtime && for archive in /opt/php-suite/archives/*.deb; do \
        dpkg-deb -x "$archive" /opt/php-suite/runtime; done && \
    /opt/php-suite/runtime/usr/bin/php8.4 -n -r 'exit(PHP_VERSION === "8.4.27" ? 0 : 1);'
