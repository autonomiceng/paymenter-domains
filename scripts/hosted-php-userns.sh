#!/usr/bin/env bash
# Admit the binaries used for rootless namespace setup on a fresh hosted VM.
set -euo pipefail

fail() {
    printf '%s\n' "$*" >&2
    exit 1
}

[[ ${GITHUB_ACTIONS:-} == true && ${RUNNER_ENVIRONMENT:-} == github-hosted && ${RUNNER_OS:-} == Linux && $EUID != 0 ]] \
    || fail 'Run this setup as an unprivileged user on a GitHub-hosted Linux runner.'
. /etc/os-release
[[ $ID == ubuntu && $VERSION_ID == 24.04 ]] || fail 'Hosted PHP setup requires Ubuntu 24.04.'

runc_path=$(realpath -e "$(type -P runc)")
case "$runc_path" in
    /usr/bin/runc|/usr/sbin/runc) executables=("$runc_path") ;;
    *) fail "Expected the runner's system runc, found $runc_path" ;;
esac
if (( $# != 0 )); then
    [[ $# == 2 && $1 == --unshare && $2 == /usr/bin/unshare && $(realpath -e "$2") == "$2" ]] \
        || fail 'Usage: hosted-php-userns.sh [--unshare /usr/bin/unshare]'
    executables+=("$2")
fi
for executable in "${executables[@]}"; do
    [[ -f $executable && -x $executable && ! -w $executable && ! -w ${executable%/*} && ! -w /usr \
        && $(stat -c %u "$executable") == 0 ]] || fail "Expected a protected system executable: $executable"
done

read -r restricted < /proc/sys/kernel/apparmor_restrict_unprivileged_userns
printf 'AppArmor unprivileged userns restriction: %s. System runc: %s\n' "$restricted" "$runc_path" >&2
"$runc_path" --version >&2
case "$restricted" in
    0) exit 0 ;;
    1) ;;
    *) fail "Unexpected AppArmor user namespace setting: $restricted" ;;
esac

# Use private copies to avoid overriding a distro profile or changing Docker's runtime.
# AppArmor attaches the profiles by executable path, including runc's namespace setup.
bin_dir=$(sudo -n /usr/bin/mktemp -d /run/domains-php-XXXXXXXX)
sudo -n /usr/bin/install -m 0755 -- "${executables[@]}" "$bin_dir/"
{
    printf 'abi <abi/4.0>,\n'
    for executable in "${executables[@]}"; do
        printf 'profile %s/%s flags=(unconfined) {\n  userns,\n}\n' "$bin_dir" "${executable##*/}"
    done
} | sudo -n /usr/sbin/apparmor_parser --add --skip-cache >&2
sudo -n /usr/bin/chmod 0755 "$bin_dir"
printf '%s\n' "$bin_dir"
