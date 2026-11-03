"""Share verified build inputs; isolate every installed lifecycle's mutable state."""

# MIT, Copyright (c) 2026 Autonomic Engineering.
from contextlib import contextmanager
import hashlib
import json
import os
from pathlib import Path
import shutil
import tempfile

import build
import native


@contextmanager
def application(fixture, scenario):
    vendor = (fixture / "vendor").resolve(strict=True)
    before = native.hashes(vendor)
    with tempfile.TemporaryDirectory(
        prefix=f"domains-installed-{scenario}-", dir=fixture.parent
    ) as temporary:
        root = Path(temporary)
        app = root / "paymenter"
        shutil.copytree(
            fixture,
            app,
            symlinks=True,
            ignore=shutil.ignore_patterns(
                "vendor", "*.sqlite", "domains-editor-routes.php"
            ),
        )
        (app / "vendor").symlink_to(vendor, target_is_directory=True)
        try:
            yield root, app
        finally:
            if native.hashes(vendor) != before:
                raise RuntimeError(
                    "Installed scenario changed shared vendor dependencies."
                )


def package(source, output):
    # The runner owns this cache and runs installed scenarios exclusively. Other
    # processes/files reuse only immutable ZIPs, never databases or installed apps.
    fingerprint = hashlib.sha256(
        json.dumps(native.hashes(source), sort_keys=True).encode()
    ).hexdigest()
    cache = (
        Path(os.environ["PAYMENTER_TEST_FIXTURE"]).parent
        / "installed-packages"
        / fingerprint
    )
    if cache.exists():
        receipt = json.loads((cache / "manifest.json").read_text())
        build.verify(cache / receipt["archive"], receipt)
    else:
        cache.parent.mkdir(parents=True, exist_ok=True)
        receipt = build.build(source, cache)
    output.symlink_to(cache, target_is_directory=True)
    return receipt
