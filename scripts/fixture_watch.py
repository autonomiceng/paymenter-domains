"""Detect changes to an admitted Linux dependency tree without repeated inventory walks."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import ctypes
import os
from pathlib import Path


class UnchangedTree:
    # Writes, metadata changes, entries added/removed/moved and watched-root changes.
    MASK = 0x00000002 | 0x00000004 | 0x00000008 | 0x00000040 | 0x00000080 | 0x00000100 | 0x00000200 | 0x00000400 | 0x00000800

    def __init__(self, root):
        self.fd = -1
        self.clean = False
        self.root = Path(root)
        try:
            self.identity = self.root.stat().st_dev, self.root.stat().st_ino
            libc = ctypes.CDLL(None, use_errno=True)
            libc.inotify_init1.argtypes = [ctypes.c_int]
            libc.inotify_init1.restype = ctypes.c_int
            libc.inotify_add_watch.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_uint32]
            libc.inotify_add_watch.restype = ctypes.c_int
            self.fd = libc.inotify_init1(os.O_NONBLOCK | os.O_CLOEXEC)
            if self.fd < 0:
                raise OSError(ctypes.get_errno(), 'Cannot watch dependencies')
            # Subscribe before the caller admits bytes. Any concurrent mutation makes
            # the watch permanently dirty, including events during this enumeration.
            for directory, _, _ in os.walk(root, followlinks=False, onerror=self.failed):
                if libc.inotify_add_watch(self.fd, os.fsencode(directory), self.MASK) < 0:
                    raise OSError(ctypes.get_errno(), 'Cannot watch dependency directory')
            self.clean = True
        except (OSError, AttributeError):
            self.close()

    @staticmethod
    def failed(error):
        raise error

    def unchanged(self):
        if not self.clean:
            return False
        try:
            stat = self.root.stat()
            if self.root.is_symlink() or (stat.st_dev, stat.st_ino) != self.identity:
                self.close()
                return False
            # Overflow, unmount and ignored-watch events also invalidate admission.
            # Never discard an event and then reuse the previous clean snapshot.
            if os.read(self.fd, 65536):
                self.close()
                return False
        except BlockingIOError:
            return True
        except OSError:
            self.close()
        return False

    def close(self):
        self.clean = False
        if self.fd >= 0:
            os.close(self.fd)
            self.fd = -1

    def __del__(self):
        self.close()
