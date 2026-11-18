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
printf 'Kernel: %s\n' "$(uname -r)" >&2
"$runc_path" --version >&2
case "$restricted" in
    0) exit 0 ;;
    1) ;;
    *) fail "Unexpected AppArmor user namespace setting: $restricted" ;;
esac

/usr/bin/dpkg-query -W -f='AppArmor package: ${Version}\n' apparmor >&2
sudo -n /usr/sbin/sysctl -e kernel.apparmor_restrict_unprivileged_unconfined \
    kernel.apparmor_restrict_unprivileged_userns_force kernel.apparmor_restrict_unprivileged_userns_complain >&2
# Use private copies to avoid overriding a distro profile or changing Docker's runtime.
exec_parent=/usr/lib
unsafe=$(find / /usr "$exec_parent" -maxdepth 0 \( ! -type d -o ! -uid 0 -o -perm /022 \) -print)
[[ -z $unsafe ]] || fail "Unprotected hosted executable parent: $unsafe"
printf '/run mount (target, type, VFS options): ' >&2
findmnt -n -o TARGET,FSTYPE,VFS-OPTIONS --target /run >&2
options=$(findmnt -n -o VFS-OPTIONS --target "$exec_parent")
printf 'Hosted executable parent: %s VFS options: %s\n' "$exec_parent" "$options" >&2
[[ -n $options && ,$options, != *,noexec,* ]] || fail "Expected an executable mount at $exec_parent"
bin_dir=$(sudo -n /usr/bin/mktemp -d "$exec_parent/domains-php-XXXXXXXX")
sudo -n /usr/bin/install -m 0755 -- "${executables[@]}" "$bin_dir/"
{
    printf 'abi <abi/4.0>,\n'
    for executable in "${executables[@]}"; do
        printf 'profile %s/%s flags=(unconfined) {\n  audit userns,\n}\n' "$bin_dir" "${executable##*/}"
    done
} | sudo -n /usr/sbin/apparmor_parser --add --skip-cache >&2
# Check the kernel's list, including the mode compiled for this runner.
for executable in "${executables[@]}"; do
    profile="$bin_dir/${executable##*/}"
    sudo -n /usr/bin/cat /sys/kernel/security/apparmor/profiles | awk -v profile="$profile" \
        '$1 == profile { print; found = 1 } END { exit !found }' >&2 || fail "Missing AppArmor profile: $profile"
done
sudo -n /usr/bin/chmod 0755 "$bin_dir"
printf 'Hosted preflight uid=%s caller_profile=%s\n' "$EUID" "$(< /proc/self/attr/apparmor/current)" >&2
for executable in "${executables[@]}"; do
    copy="$bin_dir/${executable##*/}"
    printf 'Executing %s --version\n' "$copy" >&2
    "$copy" --version >&2 || fail "Runner cannot execute $copy --version"
done
printf 'Hosted executable directory: %s\n' "$bin_dir" >&2
printf '%s\n' "$bin_dir"
