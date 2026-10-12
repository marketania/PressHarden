#!/usr/bin/env python3
"""Real policy summary/CLI with inert snapshots; no WordPress or network."""
from pathlib import Path
import json
import os
import signal
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
KEYS = '''file_mods editor core_updates plugin_updates plugin_updates_count theme_updates theme_updates_count updater updater_blockers cron recovery environment development debug debug_log debug_display savequeries script_debug force_ssl_admin wp_cache revisions trash_days autosave_interval wp_memory_limit wp_max_memory_limit db_charset db_collate home_override siteurl_override cookie_domain fs_method allow_repair unfiltered_uploads unfiltered_html http_block_external'''.split()

def snapshot(site='example.test'):
    policy = dict.fromkeys(KEYS, 'DISABLED')
    policy.update(file_mods='LOCKED', core_updates='MINOR', plugin_updates_count='0/1',
                  theme_updates_count='0/1', updater='BLOCKED', updater_blockers='DISALLOW_FILE_MODS',
                  environment='PRODUCTION', db_charset='UTF8MB4', wp_memory_limit='128M')
    return {'site': site, 'policy': policy}

class PolicyOutput(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='ph-policy-output-'); self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)

    def summary(self, rows, mode='fleet', kind='file'):
        source = self.base / 'rows'
        data = b''.join(json.dumps(row).encode()+b'\n' for row in rows)
        if kind == 'fifo': os.mkfifo(source)
        elif kind == 'symlink':
            real = self.base / 'original'; real.write_bytes(data); source.symlink_to(real)
        elif kind == 'hardlink':
            real = self.base / 'original'; real.write_bytes(data); os.link(real, source)
        elif kind == 'oversized':
            with source.open('wb') as f: f.truncate(16*1024*1024+1)
        else: source.write_bytes(data)
        p = subprocess.Popen(['php', str(ROOT/'lib/wp-policy-summary.php'), str(source), mode],
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True)
        try: out, err = p.communicate(timeout=3)
        except subprocess.TimeoutExpired:
            os.killpg(p.pid, signal.SIGKILL); p.communicate(); self.fail('Unsafe policy source blocked the summary')
        return p.returncode, out, err

    def test_complete_single_and_fleet_keep_existing_contract(self):
        rc, out, err = self.summary([snapshot()], 'single')
        self.assertEqual(rc, 0, err); self.assertIn(b'FIELD\tCore auto-updates\tMINOR', out)
        rc, out, err = self.summary([snapshot(), snapshot('other.test')])
        self.assertEqual(rc, 0, err); self.assertIn(b'COUNT\t2', out); self.assertIn(b'DIFFCOUNT\t0', out)

    def test_missing_field_is_not_mixed_or_consistent(self):
        row = snapshot(); del row['policy']['cron']
        rc, out, err = self.summary([row, snapshot('other.test')])
        self.assertEqual(rc, 2, out+err); self.assertEqual(out, b'')

    def test_missing_count_and_invalid_values_refused_before_any_rows(self):
        for key, value in (('plugin_updates_count', None), ('file_mods', []), ('cron', True), ('db_charset', 'A'*1025)):
            row = snapshot(); row['policy'][key] = value
            with self.subTest(key=key):
                rc, out, err = self.summary([row], 'single'); self.assertEqual(rc, 2, out+err); self.assertEqual(out, b'')

    def test_duplicate_site_is_not_counted_twice(self):
        rc, out, err = self.summary([snapshot(), snapshot()])
        self.assertEqual(rc, 2, out+err); self.assertEqual(out, b'')

    def test_unknown_fields_are_not_accepted_as_normalized_policy(self):
        row = snapshot(); row['policy']['unexpected_private_key'] = 'INERT-MARKER-729'
        rc, out, err = self.summary([row]); self.assertEqual(rc, 2); self.assertEqual(out, b'')
        self.assertNotIn(b'INERT-MARKER-729', out+err)

    def test_single_and_fleet_values_cannot_emit_terminal_sequences(self):
        row = snapshot(); row['policy']['file_mods'] = 'café\x1b[2J\x07\u202efake'
        for mode in ('single','fleet'):
            with self.subTest(mode=mode):
                rc, out, err = self.summary([row], mode); self.assertEqual(rc, 0, err)
                self.assertIn('café'.encode(), out)
                for raw in (b'\x1b', b'\x07', '\u202e'.encode()): self.assertNotIn(raw, out)
                self.assertIn(b'\\x1B', out)

    def test_nul_record_cannot_be_silently_skipped(self):
        source = self.base / 'nul-rows'
        source.write_bytes(json.dumps(snapshot()).encode()+b'\n\0\n')
        r = subprocess.run(['php',str(ROOT/'lib/wp-policy-summary.php'),str(source),'fleet'], capture_output=True, timeout=3)
        self.assertEqual(r.returncode,2); self.assertEqual(r.stdout,b'')

    def test_summary_rejects_linked_source(self):
        rc, out, _ = self.summary([snapshot()], kind='symlink'); self.assertEqual(rc, 2); self.assertEqual(out, b'')

    def test_summary_rejects_hardlinked_source(self):
        rc, out, _ = self.summary([snapshot()], kind='hardlink'); self.assertEqual(rc, 2); self.assertEqual(out, b'')

    def test_summary_rejects_fifo(self):
        rc, out, _ = self.summary([], kind='fifo'); self.assertEqual(rc, 2); self.assertEqual(out, b'')

    def test_summary_rejects_oversized_source(self):
        rc, out, _ = self.summary([], kind='oversized'); self.assertEqual(rc, 2); self.assertEqual(out, b'')

    def cli(self, mode):
        fleet = self.base/'fleet'
        for label in ('example.test','other.test'):
            site = fleet/label/'public_html'
            for d in ('wp-admin','wp-content','wp-includes'): (site/d).mkdir(parents=True, exist_ok=True)
            for name in ('wp-load.php','wp-settings.php','wp-config.php'): (site/name).write_text('<?php // inert\n')
            (site/'wp-includes/version.php').write_text('<?php $wp_version="7.1";\n')
        (self.base/'fixture.json').write_text(json.dumps(snapshot()))
        bindir=self.base/'bin';bindir.mkdir(exist_ok=True)
        (bindir/'wp').write_text(r'''#!/usr/bin/env python3
import json,os,sys
from pathlib import Path
a=[x for x in sys.argv[1:] if not x.startswith('--')]
if a[0]!='eval-file':sys.exit(99)
row=json.loads((Path(os.environ['PH_OUTPUT_WORK'])/'fixture.json').read_text());row['site']=a[2]
m=os.environ['PH_OUTPUT_MODE']
if a[2]=='example.test':
 if m=='extra':row['policy']['unexpected_private_key']='INERT-MARKER-729'
 if m=='missing':del row['policy']['cron']
 if m=='wrong-site':row['site']='other.test'
 if m=='warning':print('WARNING: INERT-MARKER-729',file=sys.stderr)
 if m=='controls':row['policy']['db_charset']='café\x1b[2J\x07\u202efake'
print(json.dumps(row))
''')
        (bindir/'wp').chmod(0o755)
        env=dict(os.environ,PATH=str(bindir)+':/usr/local/bin:/usr/bin:/bin',HOME=str(self.base),
                 PH_OUTPUT_WORK=str(self.base),PH_OUTPUT_MODE=mode,
                 PRESSHARDEN_SCAN_ROOT=str(fleet),PRESSHARDEN_CONFIG_FILE=str(self.base/'no-config'),
                 PRESSHARDEN_STATE_DIR=str(self.base/'state'),PRESSHARDEN_CACHE_DIR=str(self.base/'cache'),
                 PRESSHARDEN_INTERACTIVE='0',PRESSHARDEN_PROGRESS='0',PRESSHARDEN_NOCOLOR='1')
        r=subprocess.run(['bash',str(ROOT/'pressharden'),'wp-settings','all'],env=env,capture_output=True,timeout=15)
        logs=b''.join(p.read_bytes() for p in (self.base/'state').rglob('*.log'))
        return r,logs

    def test_cli_unexpected_fields_do_not_leak_into_private_normalized_log(self):
        r,logs=self.cli('extra');self.assertEqual(r.returncode,2,r.stdout+r.stderr)
        self.assertNotIn(b'INERT-MARKER-729',r.stdout+r.stderr+logs)
        self.assertIn(b'other.test',logs)

    def test_cli_missing_field_does_not_report_fleet_consistency(self):
        r,_=self.cli('missing');self.assertEqual(r.returncode,2,r.stdout+r.stderr)
        self.assertNotIn(b'CONSISTENT',r.stdout)

    def test_cli_checks_returned_site_identity(self):
        r,_=self.cli('wrong-site');self.assertEqual(r.returncode,2,r.stdout+r.stderr)
        self.assertNotIn(b'CONSISTENT',r.stdout)

    def test_cli_provider_warning_is_incomplete_not_silently_dropped(self):
        r,logs=self.cli('warning');self.assertEqual(r.returncode,2,r.stdout+r.stderr)
        self.assertNotIn(b'INERT-MARKER-729',r.stdout+r.stderr+logs)

if __name__=='__main__':unittest.main(verbosity=2)
