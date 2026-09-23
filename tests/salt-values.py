#!/usr/bin/env python3
"""Read-only salt JSON compatibility/validation; only inert fixture values."""
from pathlib import Path
import copy
import hashlib
import json
import subprocess

helper = Path(__file__).resolve().parents[1] / 'lib/salt-values.php'
keys = ['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT']
rows = [{'name': key, 'value': hashlib.sha512(key.encode()).hexdigest(), 'type': 'constant'} for key in keys]

def run(data, expected=0, group='auth'):
    payload = data if isinstance(data, str) else json.dumps(data)
    result = subprocess.run(['php', str(helper), group], input=payload, text=True, capture_output=True, timeout=10)
    assert result.returncode == expected, (expected, result.returncode, result.stderr)
    for row in rows:
        assert row['value'] not in result.stdout + result.stderr
    if expected:
        assert result.stdout == '' and 'values suppressed' in result.stderr
    else:
        return json.loads(result.stdout)

current = run(rows)
legacy = run([{'key': row['name'], 'value': row['value']} for row in rows])
assert current == legacy
run(rows[:-1], 2)
bad = copy.deepcopy(rows); bad[-1] = bad[0]; run(bad, 2)
bad = copy.deepcopy(rows); bad[0]['key'] = 'DB_PASSWORD'; run(bad, 2)
bad = copy.deepcopy(rows); bad[0]['type'] = 'variable'; run(bad, 2)
bad = copy.deepcopy(rows); bad[0]['name'] = 'DB_PASSWORD'; run(bad, 2)
bad = copy.deepcopy(rows); bad[0]['value'] = 'short'; run(bad, 2)
bad = copy.deepcopy(rows); bad[0]['value'] = bad[1]['value']; run(bad, 2)
bad = copy.deepcopy(rows); bad[0]['value'] = False; run(bad, 2)
run('not json', 2); run(' ' * 65537, 2); run(rows, 2, 'unknown')
assert len(run([{'name': 'WP_CACHE_KEY_SALT', 'value': 'a' * 64, 'type': 'constant'}], group='cache')) == 1
print('Current and legacy WP-CLI salt fields, strict group validation and value suppression PASS')
