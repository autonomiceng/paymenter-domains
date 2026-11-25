"""Build the PHP 8.4 image and run a suite inside the caller's Linux cgroup."""
from contextlib import contextmanager
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import uuid

SCRIPTS = Path(__file__).resolve().parent
RECIPE = SCRIPTS / 'php-suite.Dockerfile'
RUNTIME = Path('/opt/php-suite/runtime')
CGROUP = Path('/sys/fs/cgroup')
# Every suite process sees the same locale, zone and Git configuration, whatever the caller's.
FIXED_INPUTS = {'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8', 'TZ': 'UTC', 'GIT_CONFIG_NOSYSTEM': '1',
                'GIT_CONFIG_GLOBAL': os.devnull, 'GIT_OPTIONAL_LOCKS': '0', 'PYTHONDONTWRITEBYTECODE': '1'}


def host_environment():
    """The caller's environment for Git, Docker and tar on the host, with the fixed inputs and no Git or tar
    overrides, so checkout inspection and image export see the same inputs whatever the caller set."""
    kept = {key: value for key, value in os.environ.items() if not key.startswith('GIT_') and key != 'TAR_OPTIONS'}
    return dict(kept, **FIXED_INPUTS, GIT_NO_REPLACE_OBJECTS='1')


def runtime(*, native):
    root = RUNTIME
    if not os.statvfs(root).f_flag & os.ST_RDONLY:
        raise RuntimeError('Suite PHP runtime must be mounted read-only.')
    return root, root/'usr/bin/php8.4', root/'usr/lib/php/20240924/curl.so'


def installed_versions():
    version = subprocess.check_output([RUNTIME/'usr/bin/php8.4', '-n', '-r', 'echo PHP_VERSION;'], text=True)
    rows = subprocess.check_output(['dpkg-query', '-W', '-f=${db:Status-Status} ${Package} ${Version}\n',
                                    'php*'], text=True)
    packages = {name: release for status, name, release in (row.split(' ', 2) for row in rows.splitlines())
                if status == 'installed'}
    return version, packages


def image_tag():
    digest = hashlib.sha256(RECIPE.read_bytes()).hexdigest()
    return 'domains-php-suite:' + digest


def image_id():
    return subprocess.check_output(['docker', 'image', 'inspect', '--format', '{{.Id}}', image_tag()], text=True,
                                   env=host_environment()).strip()


def build():
    # Refresh apt on explicit builds while reusing the large browser layer.
    with RECIPE.open('rb') as recipe:
        subprocess.run(['docker', 'build', '--platform', 'linux/amd64', '--no-cache-filter', 'php-runtime',
                        '-t', image_tag(), '-'], stdin=recipe, check=True, env=host_environment())


@contextmanager
def image_container(image):
    cid = subprocess.check_output(['docker', 'create', '--pull=never', image, 'true'], text=True, env=host_environment()).strip()
    try:
        yield cid
    finally:
        subprocess.run(['docker', 'rm', '-v', cid], check=True, stdout=subprocess.DEVNULL, env=host_environment())


@contextmanager
def image_root(bundle):
    image = image_id()
    root = bundle/'rootfs'
    with image_container(image) as cid:
        root.mkdir()
        with subprocess.Popen(['docker', 'export', cid], stdout=subprocess.PIPE, env=host_environment()) as export:
            try:
                subprocess.run(['tar', '--no-same-owner', '--exclude=dev/*', '-xf', '-', '-C', str(root)],
                               stdin=export.stdout, check=True, env=host_environment())
            finally:
                export.stdout.close()
            if export.wait():
                raise RuntimeError('PHP suite image export failed.')
    yield root, image


def git_inputs(root):
    """A linked checkout requires both its private index and its common object store."""
    paths = [Path(subprocess.check_output(['git', '-C', str(root), 'rev-parse', '--path-format=absolute', flag],
                                          text=True, env=host_environment()).strip()) for flag in ('--git-dir', '--git-common-dir')]
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
    runc = os.environ.get('DOMAINS_HOSTED_RUNC') or 'runc'
    subprocess.run([runc, 'spec', '--rootless'], cwd=bundle, check=True)
    path = bundle/'config.json'
    config = json.loads(path.read_text())
    config['root'] = {'path': str(rootfs), 'readonly': True}
    config['process'].update(terminal=False, cwd=str(cwd), args=argv,
                             env=[key+'='+str(value) for key, value in env.items()])
    # scripts/confine.py maps this container's root into each command's user namespace, which the kernel allows
    # only to a creator holding CAP_SETFCAP. It acts only inside this rootless container's namespace, and confine.py
    # drops it before any command other than the runner's own tests starts.
    for kind in ('bounding', 'effective', 'permitted'):
        config['process']['capabilities'][kind].append('CAP_SETFCAP')
    # An implicit runc cgroup may be a sibling of the caller, outside its memory and swap limits.
    config['linux']['cgroupsPath'] = str(membership()/name)
    config['linux']['namespaces'] = [n for n in config['linux']['namespaces'] if n['type'] != 'cgroup']
    config['linux']['namespaces'].append({'type': 'network'})
    # Parent mounts must precede their children, including output binds under /tmp.
    extra = [{'destination': str(dest), 'source': str(src), 'type': 'bind',
              'options': ['rbind', 'nosuid', 'nodev', 'rw' if dest in writable else 'ro']}
             for dest, src in mounts.items()]
    extra.extend({'destination': str(dest), 'source': 'tmpfs', 'type': 'tmpfs',
                  'options': ['nosuid', 'nodev', 'mode=1777']} for dest in temporary)
    config['mounts'].extend(sorted(extra, key=lambda mount: len(Path(mount['destination']).parts)))
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
    return [runc, '--root', str(bundle/'state'), '--rootless=true'], name


def log_runc_attachment(pid):
    # The parent has crossed exec. The kernel journal records its child's namespace transition.
    proc = Path('/proc')/str(pid)
    try:
        attr = 'attr/apparmor/current'
        service_label = (Path('/proc/self')/attr).read_text().strip()
        label = (proc/attr).read_text().strip()
        executable = (proc/'exe').readlink()
        print(f'Hosted runc pid={pid} uid={os.geteuid()} exe={executable} profile={label} '
              f'service_profile={service_label}', file=sys.stderr, flush=True)
    except OSError as error:
        print(f'Cannot inspect hosted runc pid={pid}: {error}', file=sys.stderr)


def run_bundle(bundle, rootfs, argv, cwd, env, mounts, *, writable=(), temporary=()):
    cli, name = configure(bundle, rootfs, argv, cwd, env, mounts, writable, temporary)
    try:
        command = [*cli, 'run', '--bundle', str(bundle), name]
        if not os.environ.get('DOMAINS_HOSTED_RUNC'):
            return subprocess.run(command).returncode
        with subprocess.Popen(command) as process:
            try:
                log_runc_attachment(process.pid)
                return process.wait()
            except BaseException:
                process.kill()
                raise
    finally:
        if (bundle/'state'/name).exists():
            subprocess.run([*cli, 'delete', '--force', name], check=True)


def grant_scope():
    """Use the current dedicated scope, or request one in the caller's slice."""
    current = CGROUP/membership().relative_to('/')
    dedicated = (current.name.endswith(('.scope', '.service')) and
                 set((current/'cgroup.procs').read_text().split()) == {str(os.getpid())})
    if dedicated and not all((current/file).is_file() for file in ('memory.max', 'memory.swap.max')):
        raise RuntimeError('The requested systemd scope needs a delegated memory controller with swap limits.')
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
    output = None
    phase = 'setup'
    try:
        root = root.resolve()
        existing = os.environ.get('DOMAINS_TEST_CGROUP')
        group = Path(existing) if existing else grant_scope()
        group = group.resolve(strict=True)
        if (group == CGROUP or not group.is_relative_to(CGROUP)
                or not (CGROUP/membership().relative_to('/')).is_relative_to(group)
                or 'memory' not in (group/'cgroup.subtree_control').read_text().split()):
            raise RuntimeError('The test cgroup must contain the suite controller.')
        output = Path(tempfile.mkdtemp(prefix='domains-tests-')).resolve()
        budget = root/'scripts/case_budget.py'
        # The runner's own home; each command gets a private one inside its case root.
        home = output/'home'
        home.mkdir(mode=0o700)
        env = dict(FIXED_INPUTS, PATH='/usr/local/bin:/usr/bin:/bin', HOME=str(home), TMPDIR=str(output),
                   GIT_NO_REPLACE_OBJECTS='1', DOMAINS_TEST_CGROUP=str(group),
                   DOMAINS_TEST_BUDGET=str(budget))
        # A delegated wide run supplies its own per-category limits. The repository
        # controller enforces both profiles and writes the same per-command receipts.
        if existing and Path(os.environ['DOMAINS_TEST_BUDGET']) != budget:
            env['DOMAINS_TEST_LIMITS'] = 'wide'
        mounts = {path: path for path in [root, output, group]}
        if (root/'.git').exists():
            mounts.update({path: path for path in git_inputs(root)})
        if source := os.environ.get('PAYMENTER_TEST_APP'):
            app = Path(source)
            if not app.is_absolute() or app.resolve(strict=True) != app or (app/'.env').exists():
                raise ValueError('Use a canonical pristine development dependency.')
            mounts.update({p: p for p in [app, *git_inputs(app)]})
            env['PAYMENTER_TEST_APP'] = source
        for key in ('DOMAINS_TEST_JOBS', 'DOMAINS_TEST_FIXTURE_COSTS', 'DOMAINS_TEST_MONEY_SCALE_FULL_MATRIX'):
            if key in os.environ:
                env[key] = os.environ[key]
        if history:
            history = Path(history).resolve(strict=True)
            if not history.is_file():
                raise ValueError('Timing receipt must be a regular file.')
            mounts[history] = history
            arguments = [*arguments, '--timings-from', str(history)]
        env.update(NODE_PATH='/opt/php-suite/node_modules', PLAYWRIGHT_BROWSERS_PATH='/opt/php-suite/browsers')
        phase = 'container'
        with tempfile.TemporaryDirectory(prefix='php-bundle-') as name:
            bundle = Path(name)
            with image_root(bundle) as (rootfs, image):
                env['PHP_SUITE_IMAGE'] = image
                env['PHP_SUITE_OUTPUT'] = str(output)
                # The checkout and /tmp stay read-only; each command can write only inside its case root under the output.
                code = run_bundle(bundle, rootfs, ['python3', str(root/'scripts/test.py'), *arguments],
                                  root, env, mounts, writable=(output, group))
        receipt = output/'receipt.json'
        if not code and (not receipt.is_file() or json.loads(receipt.read_text()).get('complete') is not True):
            raise RuntimeError('Container exited successfully without a complete suite receipt: '+str(receipt))
        return code
    finally:
        # Scope creation can re-exec. Allocate here only for failures before setup had an output.
        if output is None:
            output = Path(tempfile.mkdtemp(prefix='domains-tests-')).resolve()
        receipt = output/'receipt.json'
        if not receipt.exists():
            receipt.write_text(json.dumps(dict(complete=False, phase=phase, runtime_unchanged=False))+'\n')
            print('Suite startup or execution failed; receipt: '+str(receipt), file=sys.stderr)


@contextmanager
def session(cwd, mounts, *, writable=(), temporary=(), pass_env=()):
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
                                                  'image': image, 'pass_env': list(pass_env)}))
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
        env = {key: os.environ[key] for key in record['pass_env'] if key in os.environ}
        env.update(FIXED_INPUTS, PATH='/usr/local/bin:/usr/bin:/bin', HOME='/tmp')
        return subprocess.run(args, env=env).returncode
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
