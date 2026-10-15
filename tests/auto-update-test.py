"""Linux integration checks with local Git remotes and mocked external services."""
from pathlib import Path
import fcntl
import os
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'deploy/auto-update.sh'


class AutoUpdateTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='links-autodeploy-test-')
        self.root = Path(self.tmp.name)
        assert self.root.resolve().parent == Path(tempfile.gettempdir()).resolve()
        self.source, self.remote, self.app, self.state, self.bin = [self.root / n for n in ('source', 'remote.git', 'app', 'state', 'bin')]
        self.source.mkdir()
        self.state.mkdir()
        self.bin.mkdir()
        self.env = dict(os.environ, GIT_CONFIG_GLOBAL='/dev/null', GIT_CONFIG_SYSTEM='/dev/null')
        self.git('init', '--bare', str(self.remote), cwd=self.root)
        self.git('init', '-b', 'main', cwd=self.source)
        self.git('config', 'user.email', 'test@example.invalid', cwd=self.source)
        self.git('config', 'user.name', 'Test', cwd=self.source)
        (self.source / '.gitignore').write_text('dist/\n.npm-cache/\nbackend/.env\nbackend/storage/\n')
        (self.source / 'backend').mkdir()
        (self.source / 'backend/code.php').write_text('<?php // v1\n')
        self.commit_remote('v1')
        self.git('clone', '--branch', 'main', str(self.remote), str(self.app), cwd=self.root)
        self.git('config', 'user.email', 'test@example.invalid', cwd=self.app)
        self.git('config', 'user.name', 'Test', cwd=self.app)
        (self.app / 'backend/.env').write_text('TEST_CONFIGURATION=preserve-me\n')
        (self.app / 'backend/storage').mkdir()
        (self.app / 'backend/storage/runtime.txt').write_text('preserve runtime\n')
        (self.app / 'dist/assets').mkdir(parents=True)
        (self.app / 'dist/index.html').write_text('original live HTML')
        (self.app / 'dist/assets/old.js').write_text('old hashed asset')
        mocks = {
            'node': 'echo v24.0.0\n',
            'npm': '''echo "npm $*" >> "$MOCK_LOG"
if [[ "$*" == *"run build"* ]]; then
  [[ "${MOCK_BUILD_FAIL:-0}" == 0 ]] || exit 23
  mkdir -p dist/assets
  cat version.txt > dist/index.html
  cp version.txt "dist/assets/$(cat version.txt).js"
fi
''',
            'systemctl': 'exit 0\n',
            'sudo': '''if [[ "$2" == -l ]]; then exit 0; fi
echo reload >> "$MOCK_LOG"
[[ "${MOCK_RELOAD_FAIL:-0}" == 0 ]] || exit 24
''',
            'curl': '''if [[ "${MOCK_HEALTH_FAIL:-0}" == 1 ]]; then
  echo '{"ok":true,"database":false}'
else
  echo '{"ok":true,"database":true}'
fi
''',
        }
        for name, body in mocks.items():
            p = self.bin / name
            p.write_text('#!/bin/bash\nset -eu\n' + body)
            p.chmod(0o755)
        import grp
        self.env.update(APP_DIR=str(self.app), STATE_DIR=str(self.state), NODE_BIN_DIR=str(self.bin),
                        WEB_GROUP=grp.getgrgid(os.getgid()).gr_name, PHP_FPM_SERVICE='php-test',
                        HEALTH_URL='https://test.invalid/api/health', MOCK_LOG=str(self.root / 'calls.log'))

    def tearDown(self):
        self.tmp.cleanup()

    def git(self, *args, cwd):
        return subprocess.check_output(['git', *args], cwd=cwd, env=self.env, stderr=subprocess.PIPE, text=True).strip()

    def commit_remote(self, version):
        (self.source / 'version.txt').write_text(version)
        self.git('add', '.', cwd=self.source)
        self.git('commit', '-m', version, cwd=self.source)
        if not (self.source / '.git/refs/remotes/origin/main').exists():
            self.git('remote', 'add', 'origin', str(self.remote), cwd=self.source)
        self.git('push', 'origin', 'main', cwd=self.source)
        return self.git('rev-parse', 'HEAD', cwd=self.source)

    def run_update(self, expected=0, **extra):
        r = subprocess.run(['/bin/bash', str(SCRIPT)], env=dict(self.env, **extra), text=True, capture_output=True)
        if expected == 0:
            self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        else:
            self.assertNotEqual(r.returncode, 0, r.stdout + r.stderr)
        return r.stdout + r.stderr

    def revision(self):
        return self.git('rev-parse', 'HEAD', cwd=self.app)

    def assert_preserved(self):
        self.assertEqual((self.app / 'backend/.env').read_text(), 'TEST_CONFIGURATION=preserve-me\n')
        self.assertEqual((self.app / 'backend/storage/runtime.txt').read_text(), 'preserve runtime\n')
        self.assertTrue((self.app / 'dist/assets/old.js').exists())

    def test_update_noop_and_backup(self):
        target = self.commit_remote('v2')
        self.run_update()
        self.assertEqual(self.revision(), target)
        self.assertEqual((self.state / 'deployed-revision').read_text().strip(), target)
        self.assertEqual((self.app / 'dist/index.html').read_text(), 'v2')
        self.assertTrue((self.app / 'dist/assets/v2.js').is_file())
        self.assert_preserved()
        backups = list((self.state / 'backups').iterdir())
        self.assertEqual(len(backups), 1)
        self.assertEqual((backups[0] / 'backend.env').stat().st_mode & 0o777, 0o600)
        calls = (self.root / 'calls.log').read_text()
        self.assertIn('Already deployed', self.run_update())
        self.assertEqual((self.root / 'calls.log').read_text(), calls)

    def test_build_failure_keeps_live_version_then_retries(self):
        old = self.revision()
        target = self.commit_remote('v2')
        self.run_update(expected=1, MOCK_BUILD_FAIL='1')
        self.assertEqual(self.revision(), old)
        self.assertEqual((self.app / 'dist/index.html').read_text(), 'original live HTML')
        self.assertFalse((self.state / 'deployed-revision').exists())
        self.run_update()
        self.assertEqual(self.revision(), target)
        self.assert_preserved()

    def test_dirty_checkout_refuses_overwrite(self):
        old = self.revision()
        self.commit_remote('v2')
        (self.app / 'version.txt').write_text('local edit')
        self.assertIn('local changes', self.run_update(expected=1))
        self.assertEqual(self.revision(), old)
        self.assertEqual((self.app / 'version.txt').read_text(), 'local edit')
        self.assertFalse((self.root / 'calls.log').exists())

    def test_failed_reload_retries_even_when_head_is_current(self):
        target = self.commit_remote('v2')
        self.run_update(expected=1, MOCK_RELOAD_FAIL='1')
        self.assertEqual(self.revision(), target)
        self.assertFalse((self.state / 'deployed-revision').exists())
        self.assertEqual((self.app / 'dist/index.html').read_text(), 'original live HTML')
        self.run_update()
        self.assertEqual((self.state / 'deployed-revision').read_text().strip(), target)
        self.assertEqual((self.app / 'dist/index.html').read_text(), 'v2')
        self.assert_preserved()

    def test_http_200_with_disconnected_database_fails(self):
        self.commit_remote('v2')
        self.assertIn('database must be connected', self.run_update(expected=1, MOCK_HEALTH_FAIL='1'))
        self.assertFalse((self.state / 'deployed-revision').exists())
        self.run_update()
        self.assertTrue((self.state / 'deployed-revision').is_file())

    def test_diverged_branch_refuses_update(self):
        self.git('commit', '--allow-empty', '-m', 'local-only', cwd=self.app)
        old = self.revision()
        self.commit_remote('v2')
        self.assertIn('diverged', self.run_update(expected=1))
        self.assertEqual(self.revision(), old)
        self.assertFalse((self.root / 'calls.log').exists())

    def test_lock_skips_concurrent_deployment(self):
        with (self.state / 'update.lock').open('w') as lock:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.assertIn('Another deployment is running', self.run_update())
        self.assertFalse((self.root / 'calls.log').exists())


if __name__ == '__main__':
    unittest.main(verbosity=2)
