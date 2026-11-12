"""Share verified build inputs; isolate every installed lifecycle's mutable state."""

# MIT, Copyright (c) 2026 Autonomic Engineering.
from contextlib import contextmanager
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

import build
import native


@contextmanager
def application(fixture, scenario, *, verify_vendor=True):
    vendor = (fixture / "vendor").resolve(strict=True)
    before = native.admitted_vendor_hashes(vendor) if verify_vendor else None
    with tempfile.TemporaryDirectory(
        prefix=f"domains-installed-{scenario}-", dir=fixture.parent
    ) as temporary:
        root = Path(temporary)
        app = root / "paymenter"
        overlay = os.environ.get('DOMAINS_TEST_OVERLAY') == '1' and (fixture/'vendor').is_symlink()
        app.mkdir()
        if overlay:
            upper, work = root/'upper', root/'work'
            upper.mkdir(); work.mkdir()
            subprocess.run(['mount', '-t', 'overlay', 'overlay', '-o',
                            f'lowerdir={fixture},upperdir={upper},workdir={work}', str(app)], check=True, timeout=5)
        else:
            entries = [str(path) for path in fixture.iterdir() if path.name != 'vendor']
            if entries:
                subprocess.run(['cp', '-a', '--reflink=auto', '--', *entries, str(app)], check=True)
        try:
            for directory, _, names in os.walk(app, followlinks=False):
                if Path(directory).is_relative_to(app/'vendor'):
                    continue
                for name in names:
                    if name.endswith('.sqlite') or name == 'domains-editor-routes.php':
                        (Path(directory)/name).unlink()
            if (app/'vendor').is_symlink():
                (app/'vendor').unlink()
            # Native panel tests can leave the worker linked to the current source.
            # An installed lifecycle receives only the ZIP it installs in this app.
            extension = app / "extensions/Servers/Domains"
            if extension.is_symlink():
                extension.unlink()
            (app / "vendor").symlink_to(vendor, target_is_directory=True)
            try:
                yield root, app
            finally:
                if verify_vendor and native.admitted_vendor_hashes(vendor) != before:
                    raise RuntimeError(
                        "Installed scenario changed shared vendor dependencies."
                    )
        finally:
            if overlay:
                subprocess.run(['umount', str(app)], check=True, timeout=5)



def package(source, output, *, builder=None):
    builder = build if builder is None else builder
    # The runner owns this cache and runs installed scenarios exclusively. Other
    # processes/files reuse only immutable ZIPs, never databases or installed apps.
    fingerprint = hashlib.sha256(
        json.dumps(native.hashes(source), sort_keys=True).encode()
        + Path(builder.__file__).read_bytes()
    ).hexdigest()
    cache = (
        Path(os.environ["PAYMENTER_TEST_FIXTURE"]).parent
        / "installed-packages"
        / fingerprint
    )
    if cache.exists():
        receipt = json.loads((cache / "manifest.json").read_text())
        builder.verify(cache / receipt["archive"], receipt)
    else:
        cache.parent.mkdir(parents=True, exist_ok=True)
        receipt = builder.build(source, cache)
    output.symlink_to(cache, target_is_directory=True)
    return receipt
