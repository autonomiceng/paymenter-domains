"""Run real Livewire polling in Chromium against a disposable native app, without networking."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import json
import os
from pathlib import Path
import shutil
import subprocess

ROOT = Path(__file__).resolve().parents[1]


def main():
    node = shutil.which('node')
    assert node, 'Node.js and Playwright are required for the staff polling regression'
    with subprocess.Popen([str(ROOT/'scripts/php-test'), 'tests/Support/staff-sync-browser.php'],
                          cwd=ROOT, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, text=True) as app:
        with subprocess.Popen([node, str(ROOT/'tests/Support/staff-sync-browser.cjs')],
                              cwd=ROOT, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                              stderr=subprocess.PIPE, text=True) as browser:
            try:
                for line in browser.stdout:
                    message = json.loads(line)
                    if message.get('type') == 'passed':
                        print(message['detail'])
                        continue
                    app.stdin.write(line)
                    app.stdin.flush()
                    reply = app.stdout.readline()
                    assert reply, app.stderr.read()
                    browser.stdin.write(reply)
                    browser.stdin.flush()
                assert browser.wait(timeout=5) == 0, browser.stderr.read()
            finally:
                browser.stdin.close()
                if browser.poll() is None:
                    browser.terminate()
                browser.wait(timeout=5)
                app.stdin.close()
        assert app.wait(timeout=5) == 0, app.stderr.read()


if __name__ == '__main__':
    main()
