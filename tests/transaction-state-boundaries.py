#!/usr/bin/env python3
"""Real filesystem/shell checks. No WordPress bootstrap or production configuration."""
from pathlib import Path
import os
import stat
import subprocess
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
class TransactionStateBoundaries(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix='press-config-state-');self.addCleanup(self.tmp.cleanup)
        self.base=Path(self.tmp.name);self.site=self.base/'site';self.site.mkdir()
        self.config=self.site/'wp-config.php';self.original='<?php\n// private inert configuration fixture\n'
        self.config.write_text(self.original);self.config.chmod(0o600)
        self.state=self.base/'state';self.state.mkdir(mode=0o700)
        self.outside=self.site/'public-recovery';self.outside.mkdir(mode=0o755)
        (self.outside/'sentinel').write_text('unrelated data')
        self.wp=self.base/'wp';self.wp.write_text('''#!/usr/bin/env bash
case "$1 $2" in
 'config get') printf 'false\\n';;
 'config list') printf '[]\\n';;
 *) exit 42;;
esac
''');self.wp.chmod(0o755)
    def run_helper(self,engine):
        if engine=='salt':args=['rotate',str(self.state),str(self.site),'example.test','auth',str(self.wp)];helper='salt-transaction.sh'
        else:args=['set',str(self.state),str(self.site),'example.test','DISALLOW_FILE_MODS','bool','true',str(self.wp)];helper='config-transaction-shell.sh'
        return subprocess.run(['bash',str(ROOT/'lib'/helper),*args],capture_output=True,text=True,timeout=15,
                              env={'PATH':'/usr/local/bin:/usr/bin:/bin','HOME':str(self.base)})
    def outside_untouched(self):
        self.assertEqual(self.config.read_text(),self.original)
        self.assertEqual(stat.S_IMODE(self.outside.stat().st_mode),0o755,'unrelated directory permissions were changed')
        self.assertEqual(sorted(p.name for p in self.outside.iterdir()),['sentinel'],'private state escaped to website')
        self.assertEqual((self.outside/'sentinel').read_text(),'unrelated data')
    def linked(self,engine,where,dangling=False):
        root=self.state/'config-transactions'
        if where=='locks':root.mkdir(mode=0o700);link=root/'locks'
        else:link=root
        target=self.outside/'not-created' if dangling else self.outside
        link.symlink_to(target,target_is_directory=True)
        result=self.run_helper(engine)
        self.assertEqual(result.returncode,2,result.stdout+result.stderr)
        self.outside_untouched();self.assertFalse((self.outside/'not-created').exists())
    def test_config_parent_symlink_is_refused(self):self.linked('config','root')
    def test_salt_parent_symlink_is_refused(self):self.linked('salt','root')
    def test_config_lock_parent_symlink_is_refused(self):self.linked('config','locks')
    def test_salt_lock_parent_symlink_is_refused(self):self.linked('salt','locks')
    def test_config_dangling_parent_is_refused(self):self.linked('config','root',True)
    def test_salt_dangling_parent_is_refused(self):self.linked('salt','root',True)
    def test_writable_existing_parent_is_not_silently_repaired(self):
        root=self.state/'config-transactions';root.mkdir();root.chmod(0o777)
        for engine in ('config','salt'):
            with self.subTest(engine=engine):
                result=self.run_helper(engine);self.assertEqual(result.returncode,2)
                self.assertEqual(stat.S_IMODE(root.stat().st_mode),0o777)
                self.assertEqual(list(root.iterdir()),[])
                self.outside_untouched()
    def test_regular_file_parent_is_preserved(self):
        root=self.state/'config-transactions';root.write_text('operator file')
        for engine in ('config','salt'):
            with self.subTest(engine=engine):
                self.assertEqual(self.run_helper(engine).returncode,2)
                self.assertEqual(root.read_text(),'operator file');self.outside_untouched()
    def test_valid_private_state_retains_backup_on_stage_failure(self):
        for engine in ('config','salt'):
            with self.subTest(engine=engine):
                result=self.run_helper(engine);self.assertEqual(result.returncode,2)
                backups=list((self.state/'config-transactions').glob('tx-*/wp-config.php'))
                self.assertTrue(backups)
                for backup in backups:
                    self.assertEqual(backup.read_text(),self.original)
                    self.assertEqual(stat.S_IMODE(backup.stat().st_mode),0o600)
                    self.assertEqual(stat.S_IMODE(backup.parent.stat().st_mode),0o700)
                self.outside_untouched()
    def test_publication_refuses_a_link_created_after_the_exists_check(self):
        # Extract the actual publication function and force a deterministic race
        # just before its subshell umask. The hook is test-only, never shipped in
        # the runtime helper; all paths/data are disposable inert fixtures.
        for name in ('config-transaction-shell.sh','salt-transaction.sh'):
            with self.subTest(engine=name):
                if self.config.is_symlink():self.config.unlink()
                self.config.write_text(self.original)
                (self.outside/'sentinel').write_text('unrelated data')
                body=(ROOT/'lib'/name).read_text().split('_publish_source() {',1)[1].split('\n}\n\n_publish_source',1)[0]
                script=self.base/'race.sh'
                script.write_text("#!/usr/bin/env bash\nset -T\nconfig=$1;outside=$2;source=$3\norig_mode=600;orig_uid=$(id -u);orig_gid=$(id -g)\n_snapshot(){ printf 'unchanged'; }\n_publish_source() {"+body+'\n}\ntrap \'if [[ "$BASH_COMMAND" = "umask 077" && -n "${tmp:-}" ]]; then ln -s -- "$outside" "$tmp"; fi\' DEBUG\nrc=0;_publish_source "$source" unchanged || rc=$?\ntrap - DEBUG\n[ "$rc" -ne 0 ]\n')
                source=self.base/'replacement';source.write_text('new inert config')
                outside=self.outside/'sentinel'
                result=subprocess.run(['bash',str(script),str(self.config),str(outside),str(source)],capture_output=True,text=True,timeout=10)
                self.assertEqual(result.returncode,0,result.stdout+result.stderr)
                self.assertEqual(outside.read_text(),'unrelated data')
                self.assertFalse(self.config.is_symlink())
                self.assertEqual(self.config.read_text(),self.original)
if __name__=='__main__':unittest.main(verbosity=2)
