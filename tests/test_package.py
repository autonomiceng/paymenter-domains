# Adapted from Enom tests/test_package.py; MIT, Copyright (c) 2026 Autonomic Engineering.
import importlib.util
from pathlib import Path
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('domains_build', ROOT/'scripts/build.py')
builder = importlib.util.module_from_spec(spec); spec.loader.exec_module(builder)

class PackageTests(unittest.TestCase):
    def test_synthetic_package_is_reproducible_with_verified_member_manifest(self):
        with tempfile.TemporaryDirectory(prefix='domains-package-test-') as temp:
            root = Path(temp)
            source = root/'Domains'; source.mkdir()
            (source/'Domains.php').write_text("<?php #[ExtensionMeta(version: '0.1.0')]")
            (source/'LICENSE').write_bytes((ROOT/'LICENSE').read_bytes())
            (source/'Contracts').mkdir(); (source/'Contracts/Driver.php').write_text('<?php // synthetic')
            first = builder.build(source, root/'first')
            second = builder.build(source, root/'second')
            self.assertEqual(first, second)
            self.assertEqual((root/'first'/first['archive']).read_bytes(), (root/'second'/second['archive']).read_bytes())
            with zipfile.ZipFile(root/'first'/first['archive']) as package:
                self.assertEqual(set(package.namelist()), {'Domains/LICENSE', 'Domains/Domains.php', 'Domains/Contracts/Driver.php'})
                self.assertEqual(set(package.namelist()), set(first['members']))

    def test_foundation_is_not_a_release_package(self):
        with self.assertRaises(ValueError): builder.runtime_members(ROOT/'Domains')

    def test_missing_license_or_development_member_fails(self):
        with tempfile.TemporaryDirectory(prefix='domains-package-boundary-') as temp:
            source = Path(temp)/'Domains'; source.mkdir()
            (source/'Domains.php').write_text("<?php #[ExtensionMeta(version: '0.2.6')]")
            with self.assertRaises(ValueError): builder.runtime_members(source)
            (source/'LICENSE').write_bytes((ROOT/'LICENSE').read_bytes())
            (source/'tests').mkdir(); (source/'tests/secret.php').write_text('<?php')
            with self.assertRaises(ValueError): builder.runtime_members(source)
    def test_license_must_match_repository_bytes(self):
        with tempfile.TemporaryDirectory(prefix='domains-package-license-') as temp:
            source = Path(temp)/'Domains'; source.mkdir()
            (source/'Domains.php').write_text("<?php #[ExtensionMeta(version: '0.2.6')]")
            for license_bytes in [b'MIT License\n', (ROOT/'LICENSE').read_bytes() + b'Extra text\n']:
                with self.subTest(license_bytes=license_bytes):
                    (source/'LICENSE').write_bytes(license_bytes)
                    with self.assertRaisesRegex(ValueError, 'license must match'):
                        builder.runtime_members(source)
    def test_symlink_and_existing_artifact_refused(self):
        with tempfile.TemporaryDirectory(prefix='domains-package-links-') as temp:
            source = Path(temp)/'Domains'; source.mkdir()
            (source/'Domains.php').write_text("<?php #[ExtensionMeta(version: '0.2.6')]")
            (source/'LICENSE').write_bytes((ROOT/'LICENSE').read_bytes())
            (source/'linked.php').symlink_to(source/'Domains.php')
            with self.assertRaises(ValueError): builder.runtime_members(source)
            (source/'linked.php').unlink()
            output = Path(temp)/'output'; builder.build(source, output)
            with self.assertRaises(FileExistsError): builder.build(source, output)
    def test_output_inside_runtime_is_refused_before_creating_a_directory(self):
        with tempfile.TemporaryDirectory(prefix='domains-package-output-') as temp:
            source = Path(temp)/'Domains'; source.mkdir()
            (source/'Domains.php').write_text("<?php #[ExtensionMeta(version: '0.2.6')]")
            (source/'LICENSE').write_bytes((ROOT/'LICENSE').read_bytes())
            with self.assertRaises(ValueError): builder.build(source, source/'dist')
            self.assertFalse((source/'dist').exists())
    def test_symlinked_source_is_refused(self):
        with tempfile.TemporaryDirectory(prefix='domains-package-source-link-') as temp:
            root = Path(temp)
            source = root/'Domains'; source.mkdir()
            alias = root/'alias'; alias.symlink_to(source, target_is_directory=True)
            with self.assertRaisesRegex(ValueError, 'real Domains source'):
                builder.build(alias, root/'output')
            self.assertFalse((root/'output').exists())
    def test_output_inside_source_through_another_path_is_refused(self):
        with tempfile.TemporaryDirectory(prefix='domains-package-source-alias-') as temp:
            root = Path(temp)
            source = root/'real'/'Domains'; source.mkdir(parents=True)
            (source/'Domains.php').write_text("<?php #[ExtensionMeta(version: '0.2.6')]")
            (source/'LICENSE').write_bytes((ROOT/'LICENSE').read_bytes())
            alias = root/'alias'; alias.symlink_to(source.parent, target_is_directory=True)
            with self.assertRaisesRegex(ValueError, 'canonical output directory outside Domains/'):
                builder.build(alias/'Domains', source/'dist')
            self.assertFalse((source/'dist').exists())
    def test_private_filename_in_runtime_directory_refused(self):
        with tempfile.TemporaryDirectory(prefix='domains-package-private-') as temp:
            source = Path(temp)/'Domains'; source.mkdir()
            (source/'Domains.php').write_text("<?php #[ExtensionMeta(version: '0.2.6')]")
            (source/'LICENSE').write_bytes((ROOT/'LICENSE').read_bytes())
            (source/'Registrars').mkdir(); (source/'Registrars/sample.private.php').write_text('<?php')
            with self.assertRaises(ValueError): builder.runtime_members(source)

if __name__ == '__main__':
    unittest.main()
