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

# Marks an app the runner copied for one command and removes with that command.
DISPOSABLE = ".domains-disposable-app"


@contextmanager
def application(fixture, scenario, *, verify_vendor=True, parent=None, disposable=False):
    """A private copy of FIXTURE in PARENT, by default beside the fixture. A DISPOSABLE copy may hold one
    installed lifecycle directly, see lifecycle()."""
    vendor = (fixture / "vendor").resolve(strict=True)
    before = native.admitted_vendor_hashes(vendor) if verify_vendor else None
    with tempfile.TemporaryDirectory(
        prefix=f"domains-installed-{scenario}-", dir=parent or fixture.parent
    ) as temporary:
        root = Path(temporary)
        app = root / "paymenter"
        app.mkdir()
        entries = [str(path) for path in fixture.iterdir() if path.name != 'vendor']
        if entries:
            subprocess.run(['cp', '-a', '--reflink=auto', '--', *entries, str(app)], check=True)
        for directory, _, names in os.walk(app, followlinks=False):
            if Path(directory).is_relative_to(app/'vendor'):
                continue
            for name in names:
                if name.endswith('.sqlite') or name == 'domains-editor-routes.php':
                    (Path(directory)/name).unlink()
        # Native panel tests can leave the worker linked to the current source.
        # An installed lifecycle receives only the ZIP it installs in this app.
        extension = app / "extensions/Servers/Domains"
        if extension.is_symlink():
            extension.unlink()
        (app / "vendor").symlink_to(vendor, target_is_directory=True)
        if disposable:
            (root / DISPOSABLE).write_text(str(app))
        try:
            yield root, app
        finally:
            if verify_vendor and native.admitted_vendor_hashes(vendor) != before:
                raise RuntimeError(
                    "Installed scenario changed shared vendor dependencies."
                )


@contextmanager
def lifecycle(fixture, scenario, *, private_copy=False):
    """The app and private state directory for one installed lifecycle.

    The runner's disposable command app holds the first lifecycle directly: the runner copied it from the worker
    fixture, confines writes to the command root, removes it afterwards and checks the shared vendor at its final
    guard. A second lifecycle in the same command, or PRIVATE_COPY, gets its own copy of the still unused app.
    Any other fixture gets a private copy with this process's own vendor check.
    """
    marker = fixture.parent / DISPOSABLE
    claimed = marker.with_name(DISPOSABLE + ".claimed")
    runner_owned = marker.is_file() or claimed.is_file()
    if runner_owned and claimed.is_file():
        raise RuntimeError("The runner app already holds an installed lifecycle; copy it before that one starts.")
    if runner_owned and marker.read_text() != str(fixture):
        raise RuntimeError("The disposable marker names another app.")
    if private_copy or not runner_owned:
        with application(fixture, scenario, verify_vendor=not runner_owned) as pair:
            yield pair
        return
    marker.rename(claimed)
    with tempfile.TemporaryDirectory(prefix=f"domains-installed-{scenario}-", dir=fixture.parent) as state:
        yield Path(state), fixture


def package(source, output, *, builder=None):
    builder = build if builder is None else builder
    # The runner owns a cache per private fixture. Other
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


def upload(root, package, receipt):
    """Give the native uploader its own copy of a cached ZIP, verified once at that boundary."""
    upload = root / 'upload' / receipt['archive']
    upload.parent.mkdir(exist_ok=True)
    # The uploader consumes the file it installs. Verifying the copy also refuses changed cache bytes.
    shutil.copyfile(root / package / receipt['archive'], upload)
    build.verify(upload, receipt)
    return upload
