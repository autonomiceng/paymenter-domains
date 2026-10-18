#!/usr/bin/env python3
"""Documentation baseline check; replaced by the PHP runner with the first test slice."""
from pathlib import Path

root = Path(__file__).resolve().parents[1]
assert not list((root / 'NameSilo').rglob('*.php')), 'PHP requires the offline runner'
print('Documentation baseline passed: 0 PHP suites; no runtime implementation yet.')
