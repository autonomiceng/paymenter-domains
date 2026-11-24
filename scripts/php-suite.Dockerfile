# Linux amd64, Python 3.13.18 on Debian trixie.
FROM python:3.13-slim-trixie@sha256:48d884813cf44e927cf64c5529dd630dd3b18338c6dc727991eef9b71e934a20 AS tools
# The base image configures trixie, trixie-updates and trixie-security.
RUN sed -i 's|http://deb.debian.org/|https://deb.debian.org/|g' /etc/apt/sources.list.d/debian.sources

# Install the JS driver and its matching Chromium revision at build time.
ADD --checksum=sha256:a3b87b237e26894c869b7ae8e47e93addc7547c1b161429b4c110f0badd5bd61 https://registry.npmjs.org/playwright/-/playwright-1.60.0.tgz /opt/playwright.tgz
ADD --checksum=sha256:8b1df81ba75e90a41eb5c996350ea7ecc1c915ea643ec55138f91c949c5a25bf https://registry.npmjs.org/playwright-core/-/playwright-core-1.60.0.tgz /opt/playwright-core.tgz
RUN for pkg in playwright playwright-core; do \
        mkdir -p /opt/php-suite/node_modules/$pkg && \
        tar -xf /opt/$pkg.tgz -C /opt/php-suite/node_modules/$pkg --strip-components=1 || exit 1; done && \
    apt-get update && apt-get install -y --no-install-recommends nodejs git time unzip zip && \
    PLAYWRIGHT_BROWSERS_PATH=/opt/php-suite/browsers node /opt/php-suite/node_modules/playwright/cli.js \
        install --with-deps --only-shell chromium && \
    rm -rf /var/lib/apt/lists/*

FROM tools AS php-runtime
RUN apt-get update --error-on=any && apt-get install -y --no-install-recommends \
        php8.4-cli php8.4-common php8.4-curl php8.4-sqlite3 php8.4-mbstring php8.4-intl \
        php8.4-xml php8.4-zip php8.4-gd php8.4-bcmath && \
    php8.4 -n -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 ? 0 : 1);' && \
    php8.4 -n -d extension=curl -d extension=gd -r 'exit(extension_loaded("curl") && extension_loaded("gd") ? 0 : 1);' && \
    rm -rf /var/lib/apt/lists/*

# Keep the disposable runtime used by host tools and the suite's worker integrity checks small.
RUN mkdir -p /opt/php-suite/runtime && cd / && \
    cp -al --parents usr/bin/php8.4 usr/lib/php/20240924/*.so \
        usr/lib/x86_64-linux-gnu/libicu*.so.* usr/lib/x86_64-linux-gnu/libzip.so.* /opt/php-suite/runtime/
