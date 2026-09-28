#!/usr/bin/env python3
"""Build optional WebP sidecars; original uploads and database stay untouched."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import resource
import subprocess
import tempfile

DEPLOY = Path('/opt/sudi-mall/deploy')
TOOLS = DEPLOY / 'media-tools'
SUFFIX = '.sudi-v1.webp'


def encoder_limits():
    resource.setrlimit(resource.RLIMIT_AS, (384 * 1024 * 1024,) * 2)
    resource.setrlimit(resource.RLIMIT_CPU, (25, 25))


def save_state(path, state):
    staging = path.with_suffix('.tmp')
    staging.write_text(json.dumps(state, sort_keys=True))
    os.replace(staging, path)


def run():
    lock = (DEPLOY / 'media-optimize.lock').open('a')
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        return
    source = subprocess.check_output([
        'docker', 'inspect', '-f',
        '{{range .Mounts}}{{if eq .Destination "/var/www"}}{{.Source}}{{end}}{{end}}',
        'sudi-mall-php-1'], text=True).strip()
    release = Path(source).resolve(strict=True)
    releases = Path('/opt/sudi-mall/releases').resolve(strict=True)
    if not release.is_relative_to(releases) or release.name != 'crmeb':
        raise RuntimeError('Unexpected mall release path')
    uploads = (release / 'public/uploads').resolve(strict=True)
    if not uploads.is_relative_to(release):
        raise RuntimeError('Uploads must remain inside the mall release')
    state_path = DEPLOY / 'media-state.json'
    state = json.loads(state_path.read_text()) if state_path.exists() else {}
    env = dict(os.environ, LD_LIBRARY_PATH=str(TOOLS / 'lib'))
    made = saved = failures = 0
    for source in sorted(uploads.rglob('*')):
        if source.is_symlink() or not source.is_file() or source.suffix.lower() not in ('.jpg', '.jpeg', '.png'):
            continue
        if not source.resolve().is_relative_to(uploads):
            continue
        st = source.stat()
        key = str(source.relative_to(uploads))
        fingerprint = [st.st_size, st.st_mtime_ns]
        dest = Path(str(source) + SUFFIX)
        if dest.is_symlink():
            raise RuntimeError('Refusing linked image sidecar')
        old = state.get(key, {})
        if old.get('fingerprint') == fingerprint and (dest.exists() or old.get('skipped')):
            continue
        # A changed source must never keep serving a stale generated version.
        if dest.exists():
            dest.unlink()
        state.pop(key, None)
        if not 32768 <= st.st_size <= 20 * 1024 * 1024:
            continue
        original = source.read_bytes()
        # Animated PNGs keep their original encoding.
        if source.suffix.lower() == '.png' and b'acTL' in original:
            continue
        digest = hashlib.sha256(original).hexdigest()
        handle, temporary = tempfile.mkstemp(prefix='media-', suffix='.webp', dir=DEPLOY)
        os.close(handle)
        tmp = Path(temporary)
        try:
            result = subprocess.run([
                str(TOOLS / 'bin/cwebp'), '-quiet', '-q', '92', '-m', '4',
                '-sharp_yuv', '-metadata', 'all', str(source), '-o', str(tmp)],
                env=env, timeout=30, preexec_fn=encoder_limits,
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            if result.returncode or not tmp.stat().st_size:
                failures += 1
                continue
            if hashlib.sha256(source.read_bytes()).hexdigest() != digest:
                continue
            entry = {'fingerprint': fingerprint, 'original_sha256': digest}
            if tmp.stat().st_size < st.st_size * .85:
                saved += st.st_size - tmp.stat().st_size
                tmp.chmod(0o644)
                os.replace(tmp, dest)
                made += 1
                entry['webp_bytes'] = dest.stat().st_size
            else:
                entry['skipped'] = True
            state[key] = entry
            save_state(state_path, state)
        except (OSError, subprocess.SubprocessError):
            # One malformed/slow upload must not starve later images.
            failures += 1
        finally:
            if tmp.exists():
                tmp.unlink()
    save_state(state_path, state)
    print(json.dumps({'generated': made, 'bytes_saved': saved, 'encode_failures': failures}))
    if failures:
        raise SystemExit(1)


if __name__ == '__main__':
    run()
