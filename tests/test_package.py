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

    def test_native_entrypoint_has_literal_version_metadata(self):
        self.assertEqual(builder.extension_version((ROOT/'Domains'/'Domains.php').read_bytes()), '0.1.0')

    def test_version_comes_only_from_literal_extension_metadata(self):
        decoys = "// version: '9.9.9'\n/* #[ExtensionMeta(version: '8.8.8')] */\n$text = \"version: '7.7.7'\";\n"
        with tempfile.TemporaryDirectory(prefix='domains-package-version-') as temp:
            source = Path(temp)/'Domains'; source.mkdir()
            (source/'LICENSE').write_bytes((ROOT/'LICENSE').read_bytes())
            for declaration in ["#[ExtensionMeta(version: '0.2.6')]",
                                '#[\\App\\Attributes\\ExtensionMeta(name: "Domains", version: "0.2.6",)]',
                                "#[Other(['version' => '1.0.0']), ExtensionMeta(description: 'a,b)', /* note */ version: '0.2.6')]"]:
                with self.subTest(declaration=declaration):
                    (source/'Domains.php').write_text('<?php\n' + decoys + declaration)
                    self.assertEqual(builder.runtime_members(source)[0], '0.2.6')
            for declaration in ["// version: '9.9.9'\n#[ExtensionMeta(version: VERSION)]",
                                "$text = \"version: '9.9.9'\"; #[ExtensionMeta(version: VERSION)]",
                                "#[ExtensionMeta(version: '0.2.6' . '-dev')]",
                                "#[ExtensionMeta(name: \"version: '0.2.6'\")]",
                                "#[Other(version: '0.2.6')]",
                                "// #[ExtensionMeta(version: '0.2.6')]",
                                "$text = \"#[ExtensionMeta(version: '0.2.6')]\";",
                                '$text = "{$items["#[ExtensionMeta(version: \'0.2.6\')]"]}";',
                                "?> #[ExtensionMeta(version: '0.2.6')]",
                                "// ?> #[ExtensionMeta(version: '0.2.6')]",
                                "#[ExtensionMeta(version: '0.2.6', version: '0.2.6')]",
                                "#[ExtensionMeta(version: '0.2.6')] #[ExtensionMeta(version: '0.2.6')]",
                                "#[ExtensionMeta(version: '0.2.6-dev')]",
                                "#[ExtensionMeta(version: '0.2.6')"]:
                with self.subTest(declaration=declaration):
                    (source/'Domains.php').write_text('<?php\n' + declaration)
                    with self.assertRaises(ValueError): builder.runtime_members(source)

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
    def test_one_time_converter_is_excluded(self):
        for source in [ROOT/'scripts/convert-enom-05.php', *(ROOT/'scripts/conversion').rglob('*')]:
            self.assertFalse(source.is_relative_to(ROOT/'Domains'))
        with tempfile.TemporaryDirectory(prefix='domains-converter-package-') as temp:
            root = Path(temp); source = root/'Domains'; source.mkdir()
            for name in ['Domains.php', 'LICENSE']:
                (source/name).write_bytes((ROOT/'Domains'/name).read_bytes())
            scripts = root/'scripts'; scripts.mkdir()
            (scripts/'convert-enom-05.php').write_bytes((ROOT/'scripts/convert-enom-05.php').read_bytes())
            (scripts/'conversion').mkdir(); (scripts/'conversion/source.json').write_bytes((ROOT/'scripts/conversion/source.json').read_bytes())
            manifest = builder.build(source, root/'output')
            with zipfile.ZipFile(root/'output'/manifest['archive']) as package:
                self.assertEqual(set(package.namelist()), {'Domains/Domains.php', 'Domains/LICENSE'})
                self.assertEqual(set(package.namelist()), set(manifest['members']))
            (source/'scripts').mkdir(); (source/'scripts/convert-enom-05.php').write_bytes((scripts/'convert-enom-05.php').read_bytes())
            with self.assertRaisesRegex(ValueError, 'outside the extension runtime layout'):
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
