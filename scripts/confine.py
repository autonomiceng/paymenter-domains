"""Run a command that can write only inside its case root: confine.py [--nested] ROOT -- COMMAND [ARG ...].

--nested keeps CAP_SETFCAP, which a command needs only to confine another command (the runner's own tests)."""
# MIT, Copyright (c) 2026 Autonomic Engineering.
import ctypes
import errno
import os
import re
import sys

MS_BIND, MS_REC, MS_NOSUID, MS_NODEV = 0x1000, 0x4000, 0x2, 0x4
MOUNT_ATTR_RDONLY, AT_RECURSIVE, AT_FDCWD, SYS_MOUNT_SETATTR = 0x1, 0x8000, -100, 442
PR_CAPBSET_DROP, CAP_SETFCAP, CAPABILITY_VERSION_3 = 24, 31, 0x20080522
# Kernel views that hold no files a later command could read; the test cgroup under /sys stays writable for budgets.
KERNEL = ('/proc', '/sys')
LIBC = ctypes.CDLL(None, use_errno=True)


class MountAttr(ctypes.Structure):
    _fields_ = [(name, ctypes.c_uint64) for name in ('attr_set', 'attr_clr', 'propagation', 'userns_fd')]


class CapabilityHeader(ctypes.Structure):
    _fields_ = [('version', ctypes.c_uint32), ('pid', ctypes.c_int)]


class CapabilitySets(ctypes.Structure):
    _fields_ = [(name, ctypes.c_uint32) for name in ('effective', 'permitted', 'inheritable')]


def checked(result: int, action: str, path: str) -> None:
    if result != 0:
        error = ctypes.get_errno()
        raise OSError(error, f'{action} {path}: {os.strerror(error)}')


def mount(source: str, target: str, kind: str | None, flags: int, data: str | None = None) -> None:
    encode = lambda value: None if value is None else os.fsencode(value)
    checked(LIBC.mount(encode(source), encode(target), encode(kind), ctypes.c_ulong(flags), encode(data)), 'mount', target)


def read_only(path: str, value: bool, *, recursive: bool = False) -> None:
    attr = MountAttr(attr_set=MOUNT_ATTR_RDONLY if value else 0, attr_clr=0 if value else MOUNT_ATTR_RDONLY)
    checked(LIBC.syscall(SYS_MOUNT_SETATTR, AT_FDCWD, os.fsencode(path), AT_RECURSIVE if recursive else 0,
                         ctypes.byref(attr), ctypes.sizeof(attr)), 'mount_setattr', path)


def effective_capabilities() -> int:
    with open('/proc/self/status') as status:
        return next(int(line.split()[1], 16) for line in status if line.startswith('CapEff:'))


def clear_capability(capability: int) -> None:
    """Remove CAPABILITY from the effective, permitted and inheritable sets; the kernel clears it from the ambient
    set with them."""
    header, sets = CapabilityHeader(CAPABILITY_VERSION_3, 0), (CapabilitySets*2)()
    checked(LIBC.capget(ctypes.byref(header), sets), 'capget', str(capability))
    word, mask = sets[capability // 32], ~(1 << capability % 32) & 0xffffffff
    for name in ('effective', 'permitted', 'inheritable'):
        setattr(word, name, getattr(word, name) & mask)
    checked(LIBC.capset(ctypes.byref(header), sets), 'capset', str(capability))


def writable_mounts() -> list[str]:
    """Writable mount points outside the kernel views, from this namespace's mount table."""
    found = []
    with open('/proc/self/mountinfo') as table:
        for line in table:
            fields = line.split(' - ')[0].split()
            point = re.sub(r'\\([0-7]{3})', lambda escape: chr(int(escape[1], 8)), fields[4])
            if 'rw' in fields[5].split(',') and not any(point == top or point.startswith(top+'/') for top in KERNEL):
                found.append(point)
    return found


def confine(root: str, *, nested: bool) -> None:
    """In new user and mount namespaces with this identity and these capabilities: every other mount read-only,
    and a private /dev/shm. Only a NESTED command keeps CAP_SETFCAP."""
    uid, gid, kept = os.geteuid(), os.getegid(), effective_capabilities()
    if not nested:
        kept &= ~(1 << CAP_SETFCAP)
    os.unshare(os.CLONE_NEWUSER | os.CLONE_NEWNS)
    for name, value in (('setgroups', 'deny'), ('uid_map', f'{uid} {uid} 1'), ('gid_map', f'{gid} {gid} 1')):
        with open('/proc/self/'+name, 'w') as control:
            control.write(value)
    for point in writable_mounts():
        try:
            read_only(point, True)
        except OSError as error:
            # The command cannot reach a mount point that this process cannot reach either.
            if error.errno not in (errno.ENOENT, errno.EACCES):
                raise
    if os.path.isdir('/dev/shm'):
        mount('tmpfs', '/dev/shm', 'tmpfs', MS_NOSUID | MS_NODEV, 'mode=1777')
    mount(root, root, None, MS_BIND | MS_REC)
    read_only(root, False, recursive=True)
    os.chdir(os.getcwd())
    # The new namespace grants every capability; the command keeps only those it had, so it cannot undo the mounts.
    with open('/proc/sys/kernel/cap_last_cap') as last:
        for capability in range(int(last.read())+1):
            if not kept >> capability & 1:
                checked(LIBC.prctl(PR_CAPBSET_DROP, capability, 0, 0, 0), 'drop capability', str(capability))
    if not nested:
        clear_capability(CAP_SETFCAP)


if __name__ == '__main__':
    nested = sys.argv[1:2] == ['--nested']
    args = sys.argv[1+nested:]
    if len(args) < 3 or args[1] != '--' or not os.path.isabs(args[0]):
        raise SystemExit('Usage: confine.py [--nested] ROOT -- COMMAND [ARG ...]')
    confine(args[0], nested=nested)
    os.execvp(args[2], args[2:])
