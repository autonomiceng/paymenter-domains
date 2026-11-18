"""Build the locked PHP image and run a suite inside the caller's Linux cgroup."""
from contextlib import contextmanager
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import uuid

SCRIPTS = Path(__file__).resolve().parent
RECIPE = SCRIPTS / 'php-suite.Dockerfile'
LOCK = SCRIPTS / 'php-runtime-packages.json'
IMAGE_ARCHIVES = Path('/opt/php-suite/archives')
CGROUP = Path('/sys/fs/cgroup')


def runtime(*, native):
    root = IMAGE_ARCHIVES.parent/'runtime'
    if not os.statvfs(root).f_flag & os.ST_RDONLY:
        raise RuntimeError('Suite PHP runtime must be mounted read-only.')
    return root, root/'usr/bin/php8.4', root/'usr/lib/php/20240924/curl.so'


def image_tag():
    digest = hashlib.sha256(RECIPE.read_bytes() + b'\0' + LOCK.read_bytes()).hexdigest()
    return 'domains-php-suite:' + digest


def image_id():
    return subprocess.check_output(['docker', 'image', 'inspect', '--format', '{{.Id}}', image_tag()], text=True).strip()


def build(packages):
    # The Docker build context contains only the recipe, package lock and verified archives.
    with tempfile.TemporaryDirectory(prefix='php-build-') as name:
        context = Path(name)
        shutil.copyfile(RECIPE, context/'Dockerfile')
        shutil.copyfile(LOCK, context/LOCK.name)
        directory = context/'archives'
        directory.mkdir()
        for archive in packages:
            shutil.copyfile(archive, directory/archive.name)
        subprocess.run(['docker', 'build', '--platform', 'linux/amd64', '-t', image_tag(), str(context)], check=True)


@contextmanager
def image_root(bundle):
    image = image_id()
    # Export a fresh immutable image by ID. Docker never runs the suite outside its owner's cgroup.
    cid = subprocess.check_output(['docker', 'create', '--pull=never', image, 'true'], text=True).strip()
    root = bundle/'rootfs'
    try:
        root.mkdir()
        with subprocess.Popen(['docker', 'export', cid], stdout=subprocess.PIPE) as export:
            try:
                subprocess.run(['tar', '--no-same-owner', '--exclude=dev/*', '-xf', '-', '-C', str(root)],
                               stdin=export.stdout, check=True)
            finally:
                export.stdout.close()
            if export.wait():
                raise RuntimeError('PHP suite image export failed.')
    finally:
        subprocess.run(['docker', 'rm', '-v', cid], check=True, stdout=subprocess.DEVNULL)
    if (root/'opt/php-suite/lock.json').read_bytes() != LOCK.read_bytes():
        raise ValueError('Image package lock differs from the checkout.')
    yield root, image


def git_inputs(root):
    """A linked checkout requires both its private index and its common object store."""
    paths = [Path(subprocess.check_output(['git', '-C', str(root), 'rev-parse', '--path-format=absolute', flag],
                                          text=True).strip()) for flag in ('--git-dir', '--git-common-dir')]
    pending = [paths[-1]/'objects']
    seen = set()
    while pending:
        directory = pending.pop()
        if directory in seen:
            continue
        seen.add(directory)
        links = directory/'info/alternates'
        if links.exists():
            for line in links.read_text().splitlines():
                target = (directory/line).resolve(strict=True)
                if not (target/'pack').is_dir():
                    raise ValueError('Invalid Git alternate object directory.')
                paths.append(target)
                pending.append(target)
    return paths


def membership():
    return Path(next(line[3:] for line in Path('/proc/self/cgroup').read_text().splitlines() if line.startswith('0::/')))


def configure(bundle, rootfs, argv, cwd, env, mounts, writable, temporary):
    name = 'php-' + uuid.uuid4().hex
    subprocess.run(['runc', 'spec', '--rootless'], cwd=bundle, check=True)
    path = bundle/'config.json'
    config = json.loads(path.read_text())
    config['root'] = {'path': str(rootfs), 'readonly': True}
    config['process'].update(terminal=False, cwd=str(cwd), args=argv,
                             env=[key+'='+str(value) for key, value in env.items()])
    # An implicit runc cgroup may be a sibling of the caller, outside its memory and swap limits.
    config['linux']['cgroupsPath'] = str(membership()/name)
    config['linux']['namespaces'] = [n for n in config['linux']['namespaces'] if n['type'] != 'cgroup']
    config['linux']['namespaces'].append({'type': 'network'})
    for dest, src in mounts.items():
        config['mounts'].append({'destination': str(dest), 'source': str(src), 'type': 'bind',
                                 'options': ['rbind', 'nosuid', 'nodev', 'rw' if dest in writable else 'ro']})
    for dest in temporary:
        config['mounts'].append({'destination': str(dest), 'source': 'tmpfs', 'type': 'tmpfs',
                                 'options': ['nosuid', 'nodev', 'mode=1777']})
    # Mask local credential files when a developer runs from their ordinary checkout.
    blank = bundle/'empty'
    blank.mkdir()
    blank_file = bundle/'empty-file'
    blank_file.touch()
    for private in (cwd/'.local', cwd/'.env', cwd/'apps/paymenter/.local', cwd/'apps/paymenter/.env'):
        if private.exists():
            config['mounts'].append({'destination': str(private), 'source': str(blank if private.is_dir() else blank_file),
                                     'type': 'bind', 'options': ['bind', 'ro', 'nosuid', 'nodev', 'noexec']})
    path.write_text(json.dumps(config))
    return ['runc', '--root', str(bundle/'state'), '--rootless=true'], name


def run_bundle(bundle, rootfs, argv, cwd, env, mounts, *, writable=(), temporary=()):
    cli, name = configure(bundle, rootfs, argv, cwd, env, mounts, writable, temporary)
    try:
        return subprocess.run([*cli, 'run', '--bundle', str(bundle), name]).returncode
    finally:
        if (bundle/'state'/name).exists():
            subprocess.run([*cli, 'delete', '--force', name], check=True)


def grant_scope():
    """Use the current dedicated scope, or request one in the caller's slice."""
    current = CGROUP/membership().relative_to('/')
    dedicated = (current.name.endswith(('.scope', '.service')) and
                 set((current/'cgroup.procs').read_text().split()) == {str(os.getpid())})
    if dedicated and (current/'memory.max').read_text().strip() != 'max' and (current/'memory.swap.max').read_text().strip() == '0':
        controller = current/'php-controller'
        controller.mkdir()
        (controller/'cgroup.procs').write_text(str(os.getpid()))
        (current/'cgroup.subtree_control').write_text('+memory')
        return current
    # Never escape a caller's limited leaf to acquire a sibling scope.
    parent = next((p for p in (current, *current.parents) if p.name.endswith('.slice')), None)
    leaf = current
    while leaf != parent and leaf.is_relative_to(CGROUP) and leaf != CGROUP:
        if any((leaf/file).is_file() and (leaf/file).read_text().strip() != 'max'
               for file in ('memory.max', 'memory.swap.max')):
            raise RuntimeError('Run the suite directly in its delegated memory scope.')
        leaf = leaf.parent
    parent_slice = parent.name if parent else None
    command = ['systemd-run', '--user', '--scope', '-q', '-p', 'Delegate=yes',
               '-p', 'MemoryMax=2G', '-p', 'MemorySwapMax=0']
    if parent_slice:
        command += ['--slice='+parent_slice]
    os.execvp(command[0], [*command, '--', sys.executable, *sys.argv])


def domains(root, arguments, *, history=None):
    root = root.resolve()
    existing = os.environ.get('DOMAINS_TEST_CGROUP')
    group = Path(existing) if existing else grant_scope()
    group = group.resolve(strict=True)
    if (group == CGROUP or not group.is_relative_to(CGROUP)
            or not (CGROUP/membership().relative_to('/')).is_relative_to(group)
            or 'memory' not in (group/'cgroup.subtree_control').read_text().split()):
        raise RuntimeError('The test cgroup must contain the suite controller.')
    output = Path(tempfile.mkdtemp(prefix='domains-tests-')).resolve()
    receipts = Path(os.environ.get('DOMAINS_TEST_RECEIPTS', output/'resources')).resolve()
    receipts.mkdir(exist_ok=True)
    budget = Path(os.environ['DOMAINS_TEST_BUDGET']) if existing else SCRIPTS/'case_budget.py'
    env = dict(PATH='/usr/local/bin:/usr/bin:/bin', LANG='C.UTF-8', HOME='/tmp', TMPDIR=str(output),
               GIT_OPTIONAL_LOCKS='0', GIT_NO_REPLACE_OBJECTS='1', PYTHONDONTWRITEBYTECODE='1',
               PHP_RUNTIME_ARCHIVE_CACHE=str(IMAGE_ARCHIVES), DOMAINS_TEST_CGROUP=str(group),
               DOMAINS_TEST_BUDGET=str(budget), DOMAINS_TEST_RECEIPTS=str(receipts))
    mounts = {path: path for path in [root, output, group, receipts, budget]}
    if (root/'.git').exists():
        mounts.update({path: path for path in git_inputs(root)})
    if source := os.environ.get('PAYMENTER_TEST_APP'):
        app = Path(source)
        if not app.is_absolute() or app.resolve(strict=True) != app or (app/'.env').exists():
            raise ValueError('Use a canonical pristine development dependency.')
        mounts.update({p: p for p in [app, *git_inputs(app)]})
        env['PAYMENTER_TEST_APP'] = source
    if node := shutil.which('node'):
        mounts[Path('/usr/local/bin/node')] = Path(node).resolve(strict=True)
    for key in ('DOMAINS_TEST_JOBS', 'DOMAINS_UPGRADE_FROM'):
        if key in os.environ:
            env[key] = os.environ[key]
    if history:
        history = Path(history).resolve(strict=True)
        mounts[history] = history
    for key in ('NODE_PATH', 'PLAYWRIGHT_BROWSERS_PATH', 'CHROME_BIN'):
        if os.environ.get(key):
            for name in os.environ[key].split(os.pathsep):
                path = Path(name).absolute()
                mounts[path] = path.resolve(strict=True)
            env[key] = os.environ[key]
    (root/'.cache').mkdir(exist_ok=True)
    try:
        with tempfile.TemporaryDirectory(prefix='php-bundle-') as name:
            bundle = Path(name)
            with image_root(bundle) as (rootfs, image):
                env['PHP_SUITE_IMAGE'] = image
                env['PHP_SUITE_OUTPUT'] = str(output)
                return run_bundle(bundle, rootfs, ['python3', str(root/'scripts/test.py'), *arguments],
                                  root, env, mounts, writable=(output, group, receipts), temporary=('/tmp', root/'.cache'))
    finally:
        receipt = output/'receipt.json'
        if not receipt.exists():
            receipt.write_text(json.dumps(dict(complete=False, phase='container', runtime_unchanged=False))+'\n')
            print('Suite startup or execution failed; receipt: '+str(receipt), file=sys.stderr)


@contextmanager
def session(cwd, mounts, *, writable=(), temporary=()):
    """Keep one image namespace for commands admitted in separate host cgroups."""
    with tempfile.TemporaryDirectory(prefix='php-session-') as directory:
        bundle = Path(directory)
        with image_root(bundle) as (rootfs, image):
            cli, name = configure(bundle, rootfs, ['/bin/sleep', 'infinity'], cwd, {}, mounts, writable, temporary)
            try:
                subprocess.run([*cli, 'run', '--detach', '--pid-file', str(bundle/'pid'),
                                '--bundle', directory, name], check=True)
                pid = int((bundle/'pid').read_text())
                proc = Path('/proc')/str(pid)
                descriptor = bundle/'session.json'
                descriptor.write_text(json.dumps({'pid': pid, 'namespace': (proc/'ns/mnt').stat().st_ino,
                                                  'image': image}))
                yield descriptor, image
            finally:
                if (bundle/'state'/name).exists():
                    subprocess.run([*cli, 'delete', '--force', name], check=True)


def enter(descriptor, argv):
    """Join the verified namespaces; the process and its children keep the caller's cgroup."""
    record = json.loads(Path(descriptor).read_text())
    proc = os.open('/proc/'+str(int(record['pid'])), os.O_DIRECTORY | os.O_RDONLY)
    try:
        # Pin the proc directory before checking its mount namespace. A recycled
        # PID cannot redirect nsenter. No file descriptors are passed to the test.
        target = Path('/proc')/str(os.getpid())/'fd'/str(proc)
        if (target/'ns/mnt').stat().st_ino != record['namespace']:
            raise RuntimeError('PHP suite session expired.')
        namespaces = [('user', 'user'), ('mount', 'mnt'), ('pid', 'pid'), ('net', 'net'), ('ipc', 'ipc'), ('uts', 'uts')]
        args = ['/usr/bin/nsenter', '--preserve-credentials',
                *['--'+flag+'='+str(target/'ns'/ns) for flag, ns in namespaces],
                '--root='+str(target/'root'), '--wdns='+os.getcwd(), '--',
                '/usr/bin/setpriv', '--no-new-privs', '--bounding-set=-all',
                '--inh-caps=-all', '--ambient-caps=-all', '--', *argv]
        return subprocess.run(args).returncode
    finally:
        os.close(proc)


if __name__ == '__main__':
    if sys.argv[1:] == ['image-id']:
        print(image_id())
        sys.exit(0)
    if sys.argv[1:2] == ['delegate'] and len(sys.argv) >= 3:
        os.environ['PHP_SUITE_CGROUP'] = str(grant_scope())
        os.execvpe(sys.argv[2], sys.argv[2:], os.environ)
    if len(sys.argv) < 4 or sys.argv[1] != 'enter':
        raise SystemExit('Usage: php_suite.py enter SESSION COMMAND [ARG ...]')
    sys.exit(enter(sys.argv[2], sys.argv[3:]))
