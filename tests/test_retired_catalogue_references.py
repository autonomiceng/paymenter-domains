# MIT, Copyright (c) 2026 Autonomic Engineering.
"""Every executable PHP fixture must follow the final catalogue ownership boundary."""
from pathlib import Path
import re
import unittest

RETIRED = frozenset("""UpgradeComparison UpgradeConversion UpgradeEvidence CatalogueReader
CatalogueAcceptance CatalogueAcceptances PriceFill PriceReadBudget PriceSync
PublicationEligibility SellAutomation SellRule SellTableSchema""".split())

class RetiredCatalogueReferences(unittest.TestCase):
    def test_no_removed_types_in_php_fixtures(self):
        violations = []
        for path in Path(__file__).parent.rglob('*.php'):
            source = path.read_text()
            for name in RETIRED:
                # Saved historical JSON and deletion assertions are data, never runtime authority.
                executable = re.search(r'\b' + name + r'\s*::|\b(?:new|instanceof)\s+(?:[\\\w]+\\)?' + name + r'\b', source)
                imported = any(re.search(r'\b' + name + r'\b', statement)
                               for statement in re.findall(r'^use\s+[^;]+;', source, re.MULTILINE))
                if executable or imported:
                    violations.append(str(path.relative_to(Path(__file__).parent)) + ': ' + name)
        self.assertEqual([], sorted(violations), '\n'.join(sorted(violations)))

if __name__ == '__main__':
    unittest.main()
