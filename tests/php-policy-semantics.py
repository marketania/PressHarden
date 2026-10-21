#!/usr/bin/env python3
"""Real INI parsing and private temporary configuration; no WordPress bootstrap."""
from pathlib import Path
import json
import os
import fcntl
import pty
import termios
import select
import time
import hashlib
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
class PolicySemantics(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix='press-ini-semantics-');self.addCleanup(self.tmp.cleanup)
        self.base=Path(self.tmp.name);self.site=self.base/'fleet/example.test/public_html'
        for d in ['wp-admin','wp-content','wp-includes']:(self.site/d).mkdir(parents=True)
        for f in ['wp-load.php','wp-settings.php','wp-config.php']:(self.site/f).write_text('<?php // inert\n')
        (self.site/'wp-includes/version.php').write_text('<?php $wp_version="7.1";\n')
        self.ini=self.site/'.user.ini';self.state=self.base/'state'
        self.env={'PATH':'/usr/local/bin:/usr/bin:/bin','HOME':str(self.base),'PHP_INI_SCAN_DIR':'',
                  'PRESSHARDEN_SCAN_ROOT':str(self.base/'fleet'),'PRESSHARDEN_CONFIG_FILE':str(self.base/'absent'),
                  'PRESSHARDEN_STATE_DIR':str(self.state),'PRESSHARDEN_CACHE_DIR':str(self.base/'cache'),
                  'PRESSHARDEN_INTERACTIVE':'0','PRESSHARDEN_NOCOLOR':'1','PRESSHARDEN_PROGRESS':'0',
                  'PRESSHARDEN_PHP_WEB_SAPI':'fpm-fcgi'}
    def apply(self,key='display_errors',value='Off'):
        return subprocess.run(['php',str(ROOT/'lib/php-policy.php'),'set',str(self.state),str(self.site),key,value],env=self.env,text=True,capture_output=True,timeout=15)
    def actual_ini(self,key):
        # Only our inert fixture file is passed to the real PHP INI loader.
        return subprocess.check_output(['php','-c',str(self.ini),'-r','echo json_encode(ini_get($argv[1]));',key],env=self.env,text=True)
    def test_samesite_none_survives_actual_ini_loading(self):
        r=self.apply('session.cookie_samesite','None');self.assertEqual(r.returncode,1,r.stdout+r.stderr)
        self.assertEqual(json.loads(self.actual_ini('session.cookie_samesite')),'None')
    def test_existing_unquoted_none_is_not_a_correct_noop(self):
        self.ini.write_text('session.cookie_samesite = None\n')
        r=self.apply('session.cookie_samesite','None');self.assertEqual(r.returncode,1,r.stdout+r.stderr)
        self.assertIn('CONFIGURED',r.stdout);self.assertEqual(json.loads(self.actual_ini('session.cookie_samesite')),'None')
    def test_repeated_quoted_value_creates_no_extra_backup(self):
        self.apply('session.cookie_samesite','None');before=len(list(self.state.glob('backups/php-policy/*')))
        r=self.apply('session.cookie_samesite','None');self.assertEqual(r.returncode,1,r.stdout+r.stderr)
        self.assertIn('UNCHANGED',r.stdout);self.assertEqual(len(list(self.state.glob('backups/php-policy/*'))),before)
    def test_explicit_disabled_user_ini_never_writes(self):
        self.env['PRESSHARDEN_PHP_USER_INI_FILENAME']=''
        r=self.apply();self.assertEqual(r.returncode,2,r.stdout+r.stderr);self.assertFalse(self.ini.exists())
    def test_nondefault_false_like_filename_is_not_default(self):
        self.env['PRESSHARDEN_PHP_USER_INI_FILENAME']='0'
        r=self.apply();self.assertEqual(r.returncode,2,r.stdout+r.stderr);self.assertFalse(self.ini.exists())
    def test_duplicate_same_value_still_refused(self):
        data='display_errors=Off\ndisplay_errors=Off\n';self.ini.write_text(data)
        r=self.apply();self.assertEqual(r.returncode,2,r.stdout+r.stderr);self.assertEqual(self.ini.read_text(),data)
    def test_target_environment_expression_is_not_silently_replaced(self):
        data='display_errors = ${PH_TEST_SETTING}\n';self.ini.write_text(data)
        self.env['PH_TEST_SETTING']='On';r=self.apply()
        self.assertEqual(r.returncode,2,r.stdout+r.stderr);self.assertEqual(self.ini.read_text(),data)
    def test_array_setting_is_refused_without_warning_leak(self):
        data='display_errors[]=On\n';self.ini.write_text(data);r=self.apply()
        self.assertEqual(r.returncode,2,r.stdout+r.stderr);self.assertEqual(self.ini.read_text(),data)
        self.assertNotIn('Array to string conversion',r.stdout+r.stderr)
    def test_raw_status_does_not_expand_secret_environment(self):
        self.ini.write_text('memory_limit = ${PH_SECRET_SENTINEL}\n');self.env['PH_SECRET_SENTINEL']='do-not-print-secret'
        r=subprocess.run(['php',str(ROOT/'lib/php-policy.php'),'status',str(self.site)],env=self.env,text=True,capture_output=True,timeout=10)
        self.assertNotIn('do-not-print-secret',r.stdout+r.stderr)
    def test_static_php_status_does_not_require_wp_cli(self):
        r=subprocess.run(['bash',str(ROOT/'pressharden'),'php','status','example.test'],env=self.env,text=True,capture_output=True,timeout=10)
        self.assertEqual(r.returncode,0,r.stdout+r.stderr);self.assertIn('UNKNOWN',r.stdout)
    def test_metadata_records_verified_recovery_copy(self):
        data='precision=14\ndisplay_errors=On\n';self.ini.write_text(data);self.ini.chmod(0o640)
        r=self.apply();self.assertEqual(r.returncode,1,r.stdout+r.stderr)
        manifests=list(self.state.glob('backups/php-policy/*/meta.json'));self.assertEqual(len(manifests),1)
        m=json.loads(manifests[0].read_text());self.assertEqual(m['status'],'CONFIGURED_WEB_UNVERIFIED')
        self.assertEqual((manifests[0].parent/'original.user.ini').read_text(),data)
        self.assertEqual(self.ini.stat().st_mode&0o777,0o640)
        self.assertEqual(manifests[0].stat().st_mode&0o777,0o600)
    def test_backup_collision_stops_before_live_change(self):
        data='display_errors=On\n';self.ini.write_text(data);self.state.mkdir(mode=0o700);(self.state/'backups').write_text('collision')
        r=self.apply();self.assertEqual(r.returncode,2,r.stdout+r.stderr);self.assertEqual(self.ini.read_text(),data)
    def test_shadowed_array_directive_is_not_a_noop(self):
        data='display_errors[]=On\ndisplay_errors=Off\n';self.ini.write_text(data)
        r=self.apply();self.assertEqual(r.returncode,2,r.stdout+r.stderr);self.assertEqual(self.ini.read_text(),data)
    def test_preview_precedes_missing_terminal_refusal(self):
        self.ini.write_text('display_errors=On\n');self.env['PRESSHARDEN_INTERACTIVE']='1'
        r=self.apply();self.assertEqual(r.returncode,1,r.stdout+r.stderr);self.assertIn('PLAN:',r.stdout)
        self.assertEqual(self.ini.read_text(),'display_errors=On\n');self.assertFalse(list(self.state.glob('backups/php-policy/*')))
    def test_independent_lock_blocks_then_allows_change(self):
        self.ini.write_text('display_errors=On\n');lockdir=self.state/'php-policy-locks';lockdir.mkdir(parents=True,mode=0o700)
        path=lockdir/(hashlib.sha256(str(self.site).encode()).hexdigest()+'.lock')
        with path.open('w') as lock:
            path.chmod(0o600);fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            r=self.apply();self.assertEqual(r.returncode,2,r.stdout+r.stderr);self.assertEqual(self.ini.read_text(),'display_errors=On\n')
        r=self.apply();self.assertEqual(r.returncode,1,r.stdout+r.stderr);self.assertIn('CONFIGURED',r.stdout)
    def test_terminal_preview_rejects_later_external_edit(self):
        self.ini.write_text('display_errors=On\n');self.env['PRESSHARDEN_INTERACTIVE']='1'
        master,slave=pty.openpty()
        def session():os.setsid();fcntl.ioctl(slave,termios.TIOCSCTTY,0)
        process=subprocess.Popen(['php',str(ROOT/'lib/php-policy.php'),'set',str(self.state),str(self.site),'display_errors','Off'],
                                 env=self.env,stdin=slave,stdout=slave,stderr=slave,preexec_fn=session)
        os.close(slave);output=b''
        try:
            end=time.monotonic()+10
            while b'[y/N]' not in output and time.monotonic()<end:
                if select.select([master],[],[],0.2)[0]:output+=os.read(master,65536)
            self.assertIn(b'PLAN:',output);self.assertIn(b'[y/N]',output)
            data='display_errors=On\nprecision=15\n';self.ini.write_text(data);os.write(master,b'y\n')
            while process.poll() is None and time.monotonic()<end:
                if select.select([master],[],[],0.2)[0]:
                    try:output+=os.read(master,65536)
                    except OSError:break
            self.assertEqual(process.wait(timeout=3),2,output.decode(errors='replace'));self.assertEqual(self.ini.read_text(),data)
        finally:
            if process.poll() is None:process.kill();process.wait()
            os.close(master)
if __name__=='__main__':unittest.main(verbosity=2)
