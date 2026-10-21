#!/usr/bin/env python3
"""Exercise the real CLI against inert WordPress providers and local INI files."""
from pathlib import Path
import errno
import json
import os
import pty
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
KEYS = '''file_mods editor core_updates plugin_updates plugin_updates_count theme_updates theme_updates_count updater updater_blockers cron recovery environment development debug debug_log debug_display savequeries script_debug force_ssl_admin wp_cache revisions trash_days autosave_interval wp_memory_limit wp_max_memory_limit db_charset db_collate home_override siteurl_override cookie_domain fs_method allow_repair unfiltered_uploads unfiltered_html http_block_external'''.split()


class CLIUsability(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='ph-cli-'); self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name); self.fleet = self.base/'fleet'; self.bin = self.base/'bin'; self.bin.mkdir()
        for label in ('a.test', 'b.test'):
            site = self.fleet/label/'public_html'
            for name in ('wp-admin', 'wp-content', 'wp-includes'): (site/name).mkdir(parents=True)
            for name in ('wp-settings.php', 'wp-load.php', 'wp-config.php'): (site/name).write_text('<?php // inert fixture\n')
            (site/'wp-includes/version.php').write_text('<?php $wp_version="7.1";\n')
            (site/'.user.ini').write_text('display_errors = Off\n')
        policy = dict.fromkeys(KEYS, 'DISABLED')
        policy.update(file_mods='UNLOCKED', core_updates='MINOR', plugin_updates='ENABLED',
                      plugin_updates_count='2/2', theme_updates='ENABLED', theme_updates_count='1/1',
                      updater='AVAILABLE', updater_blockers='', cron='ENABLED', recovery='ENABLED',
                      environment='PRODUCTION', development='DISABLED', debug_log='INACTIVE',
                      debug_display='INACTIVE', revisions='ENABLED', trash_days='30 DAYS',
                      autosave_interval='60 SEC', wp_memory_limit='128M', wp_max_memory_limit='256M',
                      db_charset='UTF8MB4', db_collate='DEFAULT', fs_method='AUTO')
        (self.base/'fixture.json').write_text(json.dumps({'site':'a.test', 'policy':policy}))
        provider = '''#!PYTHON
import json,os,sys
from pathlib import Path
base=Path(os.environ['PH_TEST_BASE'])
args=sys.argv[1:]
with (base/'calls').open('a') as f:f.write(json.dumps(args)+'\\n')
a=[x for x in args if not x.startswith('--')]
if a[0]=='eval-file':
 row=json.loads((base/'fixture.json').read_text());row['site']=a[2]
 if a[2]==os.environ.get('PH_FAIL_SITE'):sys.exit(9)
 if os.environ.get('PH_WARNING'):print('INERT-PRIVATE-DIAGNOSTIC',file=sys.stderr)
 print(json.dumps(row));sys.exit(0)
if a[:2]==['config','get']:sys.exit(8)
if a[:2]==['config','has']:sys.exit(1)
if a[:2]==['option','get']:print('"enabled"');sys.exit(0)
if len(a)>2 and a[1:3]==['auto-updates','status']:
 print('3' if '--enabled-only' in args else '1');sys.exit(0)
sys.exit(97)
'''.replace('PYTHON', sys.executable)
        (self.bin/'wp').write_text(provider); (self.bin/'wp').chmod(0o755)
        for name in ('curl', 'wget'):
            (self.bin/name).write_text('#!/bin/sh\nprintf network >> "$PH_TEST_BASE/network"\nexit 99\n')
            (self.bin/name).chmod(0o755)
        self.env = dict(os.environ, PATH=str(self.bin)+':'+os.environ['PATH'], HOME=str(self.base),
                        PH_TEST_BASE=str(self.base), PRESSHARDEN_SCAN_ROOT=str(self.fleet),
                        PRESSHARDEN_CONFIG_FILE=str(self.base/'no-config'), PRESSHARDEN_STATE_DIR=str(self.base/'state'),
                        PRESSHARDEN_CACHE_DIR=str(self.base/'cache'), PRESSHARDEN_PROGRESS='0', TERM='xterm')
        for key in ('PRESSHARDEN_COLOR', 'PRESSHARDEN_FORCE_COLOR', 'PRESSHARDEN_NOCOLOR', 'NO_COLOR'):
            self.env.pop(key, None)

    def cli(self, *args, env=None):
        return subprocess.run(['bash',str(ROOT/'pressharden'),*args], env=env or self.env,
                              capture_output=True, timeout=20)

    def calls(self):
        path=self.base/'calls'
        return [json.loads(x) for x in path.read_text().splitlines()] if path.exists() else []

    def test_default_status_collects_once_per_site_without_mutation(self):
        before={p:p.read_bytes() for p in self.fleet.rglob('*') if p.is_file()}
        r=self.cli('status');self.assertEqual(r.returncode,0,r.stdout+r.stderr)
        self.assertIn(b'Web PHP unverified: 2',r.stdout); self.assertIn(b'Need review: 0',r.stdout)
        self.assertEqual(len(self.calls()),2);self.assertTrue(all(c[0]=='eval-file' for c in self.calls()))
        self.assertNotIn(b'CLI value',r.stdout)
        self.assertEqual(before,{p:p.read_bytes() for p in self.fleet.rglob('*') if p.is_file()})

    def test_php_filter_and_check_alias_do_not_invoke_wordpress(self):
        r=self.cli('check','-php','--target','a.test')
        self.assertEqual(r.returncode,0,r.stdout+r.stderr);self.assertEqual(self.calls(),[])
        self.assertIn(b'1 local directive',r.stdout);self.assertNotIn(b'b.test',r.stdout)

    def test_additive_filters_named_target_and_verbose(self):
        r=self.cli('status','--locks','-updates','--target=a.test')
        self.assertEqual(r.returncode,0,r.stdout+r.stderr);self.assertEqual(len(self.calls()),1)
        self.assertIn(b'File changes: UNLOCKED',r.stdout);self.assertIn(b'core MINOR',r.stdout)
        self.assertNotIn(b'PHP: ',r.stdout)
        r=self.cli('status','--verbose','a.test');self.assertEqual(r.returncode,0,r.stdout+r.stderr)
        self.assertIn(b'Local .user.ini',r.stdout);self.assertIn(b'Dashboard editor',r.stdout)

    def test_bad_target_or_option_never_broadens_to_fleet(self):
        for args in [('status',''),('status','missing.test'),('status','-bogus'),
                     ('status','--target',''),('status','--target'),('status','a.test','--target','b.test'),
                     ('status','--target=a.test','--target=b.test'),
                     ('file-mods','--target','on'),('wp-settings','--target','status'),('php','--target','status')]:
            with self.subTest(args=args):
                r=self.cli(*args);self.assertEqual(r.returncode,2,r.stdout+r.stderr)
                self.assertEqual(self.calls(),[])

    def test_help_and_extra_update_arguments_never_start_an_operation(self):
        for args in [('update','--help'),('lock','--help'),('wp-settings','set','debug','enabled','--help'),
                     ('php','set','display_errors','On','--help'),('salts','rotate','auth','--help')]:
            r=self.cli(*args);self.assertEqual(r.returncode,0,r.stdout+r.stderr)
        r=self.cli('update','unexpected');self.assertEqual(r.returncode,2)
        self.assertEqual(self.calls(),[]);self.assertFalse((self.base/'network').exists())

    def test_status_partial_failure_retains_other_site_and_returns_incomplete(self):
        self.env['PH_FAIL_SITE']='a.test';r=self.cli('status')
        self.assertEqual(r.returncode,2,r.stdout+r.stderr)
        self.assertIn(b'b.test',r.stdout);self.assertIn(b'Incomplete: 1',r.stdout)

    def test_provider_warning_is_not_a_successful_or_leaked_snapshot(self):
        self.env['PH_WARNING']='1';r=self.cli('status','-wp')
        self.assertEqual(r.returncode,2);self.assertNotIn(b'INERT-PRIVATE-DIAGNOSTIC',r.stdout+r.stderr)

    def test_review_status_identifies_debug_on_in_production(self):
        path=self.base/'fixture.json';row=json.loads(path.read_text());row['policy']['debug']='ENABLED';path.write_text(json.dumps(row))
        r=self.cli('status','-wp','a.test');self.assertEqual(r.returncode,1,r.stdout+r.stderr)
        self.assertIn(b'debug enabled',r.stdout);self.assertIn(b'Need review: 1',r.stdout)

    def test_linked_php_configuration_is_not_treated_as_empty_or_safe(self):
        site=self.fleet/'a.test/public_html';ini=site/'.user.ini';ini.unlink();ini.symlink_to(self.base/'fixture.json')
        r=self.cli('status','-php','a.test');self.assertEqual(r.returncode,2,r.stdout+r.stderr)
        self.assertIn(b'INCOMPLETE',r.stdout)

    def test_autoload_configuration_is_inert_in_status_and_still_blocks_writes(self):
        site=self.fleet/'a.test/public_html';marker=self.base/'executed';loader=self.base/'private-waf.php'
        loader.write_text('<?php file_put_contents('+json.dumps(str(marker))+', "EXECUTED");')
        data='auto_prepend_file="'+str(loader)+'"\ndisplay_errors=Off\n';(site/'.user.ini').write_text(data)
        r=self.cli('status','-php','a.test')
        self.assertEqual(r.returncode,1,r.stdout+r.stderr);self.assertIn(b'autoload configured',b' '.join(r.stdout.split()))
        self.assertNotIn(str(loader).encode(),r.stdout+r.stderr);self.assertFalse(marker.exists());self.assertEqual(self.calls(),[])
        r=self.cli('php','status','a.test');self.assertEqual(r.returncode,0,r.stdout+r.stderr)
        self.assertIn(b'inspected as text only',r.stdout);self.assertFalse(marker.exists())
        r=self.cli('php','set','display_errors','On','a.test',env=dict(self.env,PRESSHARDEN_INTERACTIVE='0',PRESSHARDEN_PHP_WEB_SAPI='fpm-fcgi'))
        self.assertEqual(r.returncode,2,r.stdout+r.stderr);self.assertFalse(marker.exists())
        self.assertEqual((site/'.user.ini').read_text(),data)

    def test_unreadable_lock_status_and_impossible_update_counts_are_errors(self):
        r=self.cli('lock-status','a.test');self.assertEqual(r.returncode,2,r.stdout+r.stderr)
        self.assertIn(b'NOT CONFIRMED',r.stdout)
        r=self.cli('auto-updates','status','a.test');self.assertEqual(r.returncode,2,r.stdout+r.stderr)
        self.assertIn(b'INCOMPLETE',r.stdout)

    def test_terminal_control_values_are_escaped_in_concise_output(self):
        path=self.base/'fixture.json';row=json.loads(path.read_text());row['policy']['environment']='bad\x1b[2J\u202e';path.write_text(json.dumps(row))
        r=self.cli('status','-wp','a.test');self.assertEqual(r.returncode,0,r.stdout+r.stderr)
        self.assertNotIn(b'\x1b',r.stdout);self.assertNotIn('\u202e'.encode(),r.stdout)

    def test_color_flags_no_color_and_dumb_terminal(self):
        r=self.cli('help','--color=always');self.assertIn(b'\x1b[',r.stdout)
        r=self.cli('help');self.assertNotIn(b'\x1b[',r.stdout)
        for extra in ({'NO_COLOR':'1'},{'PRESSHARDEN_NOCOLOR':'1'}):
            r=self.cli('help','--color=always',env=dict(self.env,**extra));self.assertNotIn(b'\x1b[',r.stdout)
        r=self.cli('help',env=dict(self.env,TERM='dumb'));self.assertNotIn(b'\x1b[',r.stdout)
        r=self.cli('help','--color=always',env=dict(self.env,TERM='dumb'));self.assertIn(b'\x1b[',r.stdout)
        r=self.cli('help','--color=never');self.assertNotIn(b'\x1b[',r.stdout)
        r=self.cli('help','--color=bogus');self.assertEqual(r.returncode,2)

    def test_missing_cli_dependencies_are_rejected_before_install_or_update(self):
        candidate=self.base/'candidate'
        shutil.copytree(ROOT,candidate,ignore=shutil.ignore_patterns('.git','__pycache__'))
        for name in ('lib/cli-ui.sh','checks/status.sh','lib/status-summary.php','lib/wp-policy-collect.sh','lib/wp-policy-input.php'):
            with self.subTest(file=name):
                path=candidate/name;data=path.read_bytes();path.unlink()
                try:
                    r=subprocess.run(['bash','-c','. "$1/lib/update.sh"; _ph_update_validate_source "$2"',
                                      'validator',str(ROOT),str(candidate)],env=self.env,capture_output=True,timeout=15)
                    self.assertEqual(r.returncode,2,r.stdout+r.stderr)
                    self.assertIn(name.encode(),r.stderr)
                    env=dict(self.env,PRESSHARDEN_INSTALL_SOURCE=str(candidate),PRESSHARDEN_INSTALL_PREFIX=str(self.base/'installed'))
                    r=subprocess.run(['bash',str(ROOT/'install.sh')],env=env,capture_output=True,timeout=15)
                    self.assertEqual(r.returncode,2,r.stdout+r.stderr)
                    self.assertFalse((self.base/'installed').exists())
                finally:path.write_bytes(data)

    def test_stderr_tty_does_not_color_redirected_stdout(self):
        master,slave=pty.openpty()
        try:
            r=subprocess.run(['bash',str(ROOT/'pressharden'),'help'],env=self.env,stdout=subprocess.PIPE,stderr=slave,timeout=5)
            self.assertEqual(r.returncode,0);self.assertNotIn(b'\x1b[',r.stdout)
        finally:os.close(slave);os.close(master)

    def test_tty_auto_color_and_force_zero(self):
        master,slave=pty.openpty()
        try:
            p=subprocess.Popen(['bash',str(ROOT/'pressharden'),'help'],env=self.env,stdout=slave,stderr=slave)
            os.close(slave);slave=None;out=b''
            while True:
                try:
                    chunk=os.read(master,65536)
                    if not chunk:break
                    out+=chunk
                except OSError as exc:
                    if exc.errno==errno.EIO:break
                    raise
            self.assertEqual(p.wait(timeout=5),0);self.assertIn(b'\x1b[',out)
        finally:
            if slave is not None:os.close(slave)
            os.close(master)
        r=self.cli('help',env=dict(self.env,PRESSHARDEN_FORCE_COLOR='0'));self.assertNotIn(b'\x1b[',r.stdout)


if __name__=='__main__':unittest.main(verbosity=2)
