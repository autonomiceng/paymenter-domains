# Adapted from Enom tests/test_package.py; MIT, Copyright (c) 2026 Autonomic Engineering.
import importlib.util
from pathlib import Path
import tempfile
import unittest
import hashlib
import shutil
import stat
import zipfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('domains_build', ROOT/'scripts/build.py')
builder = importlib.util.module_from_spec(spec); spec.loader.exec_module(builder)

class PackageTests(unittest.TestCase):
    def test_synthetic_package_is_reproducible_with_verified_member_manifest(self):
        with tempfile.TemporaryDirectory(prefix='domains-package-test-') as temp:
            root = Path(temp)
            source = root/'Domains'; source.mkdir()
            (source/'Domains.php').write_text("<?php use Paymenter\\Extensions\\Servers\\Domains\\Contracts\\Driver; #[ExtensionMeta(version: '0.1.0')]")
            (source/'LICENSE').write_bytes((ROOT/'LICENSE').read_bytes())
            (source/'Contracts').mkdir(); (source/'Contracts/Driver.php').write_text('<?php namespace Paymenter\\Extensions\\Servers\\Domains\\Contracts; // synthetic')
            first = builder.build(source, root/'first')
            second = builder.build(source, root/'second')
            self.assertEqual(first, second)
            self.assertEqual((root/'first'/first['archive']).read_bytes(), (root/'second'/second['archive']).read_bytes())
            with zipfile.ZipFile(root/'first'/first['archive']) as package:
                self.assertEqual(set(package.namelist()), {'Domains/LICENSE', 'Domains/Domains.php', 'Domains/Contracts/Driver.php', builder.MANIFEST})
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
        for source in [ROOT/'scripts/convert-enom-05.php', ROOT/'scripts/collect-conversion-evidence.php', *(ROOT/'scripts/conversion').rglob('*')]:
            self.assertFalse(source.is_relative_to(ROOT/'Domains'))
        with tempfile.TemporaryDirectory(prefix='domains-converter-package-') as temp:
            root = Path(temp)
            first = builder.build(ROOT/'Domains', root/'first')
            second = builder.build(ROOT/'Domains', root/'second')
            self.assertEqual(first, second)
            archive = root/'first'/first['archive']
            self.assertEqual(builder.verify(archive, first), first)
            with zipfile.ZipFile(archive) as package:
                self.assertEqual(set(package.namelist()), set(first['members']))
                for name in package.namelist():
                    self.assertFalse(any(p.lower() in {'scripts', 'tests', 'docs', '.github'} or ('conversion' in p.lower() and p != 'ConversionGate.php') for p in Path(name).parts))
                    self.assertNotEqual(Path(name).name.lower(), 'readme.md')
                    self.assertEqual(first['members'][name], hashlib.sha256(package.read(name)).hexdigest())
                for name in ['Mail/DomainNotice.php', 'Presentation/OwnedDomain.php', 'routes/web.php', 'resources/views/search.blade.php', 'database/migrations/2026_10_18_000001_create_domains_foundation.php']:
                    self.assertIn('Domains/'+name, package.namelist())
                self.assertEqual(package.read('Domains/LICENSE'), (ROOT/'LICENSE').read_bytes())
            source = root/'Domains'
            shutil.copytree(ROOT/'Domains', source)
            (source/'Services/conversion').mkdir()
            (source/'Services/conversion/Apply.php').write_bytes((ROOT/'scripts/convert-enom-05.php').read_bytes())
            with self.assertRaisesRegex(ValueError, 'Development or private files'):
                builder.runtime_members(source)

    def test_unreachable_namespaced_trees_cannot_approve_themselves(self):
        with tempfile.TemporaryDirectory(prefix='domains-unreachable-') as temp:
            source = Path(temp)/'Domains'
            shutil.copytree(ROOT/'Domains', source)
            for root, other in [('Tools', 'Helpers'), ('Helpers', 'Tools')]:
                (source/root).mkdir()
                (source/root/'Import.php').write_text('<?php namespace '+builder.PREFIX+root+'; use '+builder.PREFIX+other+'\\Import;')
            with self.assertRaisesRegex(ValueError, 'outside the extension runtime layout'): builder.runtime_members(source)
            _, members = builder.runtime_members(ROOT/'Domains')
            members['Domains/Tools/Import.php'] = ('<?php namespace '+builder.PREFIX+'Tools;').encode()
            with self.assertRaisesRegex(ValueError, 'outside the extension runtime layout'): builder.validate_runtime(members)

    def test_referenced_roots_cannot_be_omitted(self):
        with tempfile.TemporaryDirectory(prefix='domains-runtime-roots-') as temp:
            source = Path(temp)/'Domains'
            shutil.copytree(ROOT/'Domains', source)
            for name in ['Mail', 'Presentation', 'Policies', 'routes', 'resources', 'database']:
                with self.subTest(name=name):
                    parked = Path(temp)/name
                    (source/name).rename(parked)
                    with self.assertRaisesRegex(ValueError, 'Runtime-referenced directory is missing'):
                        builder.runtime_members(source)
                    parked.rename(source/name)

    def test_non_runtime_paths_collisions_size_and_non_utf8_refused(self):
        with tempfile.TemporaryDirectory(prefix='domains-runtime-refusal-') as temp:
            source = Path(temp)/'Domains'
            shutil.copytree(ROOT/'Domains', source)
            for name in ['scripts/run.php', 'tests/probe.php', 'docs/note.php', '.github/ci.php', 'Services/convert-enom-05.php', 'Services/collect-conversion-evidence.php', 'Services/pin-source.php', 'Services/dev-tool.php', 'Services/conversion.php', 'Services/Runtime.PHP', 'Services/bad\udcff.php']:
                with self.subTest(name=name):
                    path = source/name; path.parent.mkdir(exist_ok=True)
                    path.write_bytes(b'<?php')
                    with self.assertRaises(ValueError): builder.runtime_members(source)
                    path.unlink()
                    if path.parent.name in ['scripts', 'tests', 'docs', '.github']: path.parent.rmdir()
            oversized = source/'Services/Oversized.php'
            oversized.write_bytes(b'x' * (builder.MAX_FILE_BYTES + 1))
            with self.assertRaisesRegex(ValueError, 'large'): builder.runtime_members(source)

    def test_archive_tamper_and_unsafe_members_refused_before_install(self):
        with tempfile.TemporaryDirectory(prefix='domains-zip-refusal-') as temp:
            root = Path(temp)
            receipt = builder.build(ROOT/'Domains', root/'clean')
            clean = root/'clean'/receipt['archive']
            for name in ['Domains/Services/Runtime.php', builder.MANIFEST, 'Domains/scripts/conversion/Apply.php', '/Domains/escape.php', 'Domains/../escape.php', 'Domains/Services/runtime.php']:
                with self.subTest(name=name):
                    archive = root/'tampered.zip'
                    with zipfile.ZipFile(clean) as original, zipfile.ZipFile(archive, 'w') as changed:
                        for entry in original.infolist():
                            changed.writestr(entry, b'tampered' if entry.filename == name else original.read(entry))
                        if name not in original.namelist():
                            entry = zipfile.ZipInfo(name); entry.external_attr = (stat.S_IFREG | 0o644) << 16
                            changed.writestr(entry, b'<?php')
                    with self.assertRaises(ValueError): builder.verify(archive)
            with self.assertRaises(ValueError): builder.verify(clean, None)
            # A self-consistent inventory cannot excuse directory case collisions.
            with zipfile.ZipFile(clean) as original:
                members = {n: original.read(n) for n in original.namelist() if n != builder.MANIFEST}
            members['Domains/services/Probe.php'] = b'<?php'
            members[builder.MANIFEST] = builder.json_bytes({'schema_version': 1, 'version': receipt['version'], 'license': 'MIT', 'members': builder.digests(members)})
            with zipfile.ZipFile(root/'collision.zip', 'w') as package:
                for name, data in members.items():
                    entry = zipfile.ZipInfo(name); entry.external_attr = (stat.S_IFREG | 0o644) << 16
                    package.writestr(entry, data)
            with self.assertRaisesRegex(ValueError, 'Case-colliding'): builder.verify(root/'collision.zip')
            wrong = dict(receipt, archive_sha256='0'*64)
            with self.assertRaises(ValueError): builder.verify(clean, wrong)

    def test_file_directory_conflict_is_refused_in_either_order(self):
        with tempfile.TemporaryDirectory(prefix='domains-package-conflict-') as temp:
            root = Path(temp)
            receipt = builder.build(ROOT/'Domains', root/'clean')
            with zipfile.ZipFile(root/'clean'/receipt['archive']) as original:
                members = {n: original.read(n) for n in original.namelist() if n != builder.MANIFEST}
            members['Domains/Services/Runtime.php/Probe.php'] = b'<?php'
            members[builder.MANIFEST] = builder.json_bytes({'schema_version': 1, 'version': receipt['version'], 'license': 'MIT', 'members': builder.digests(members)})
            for reverse in [False, True]:
                with self.subTest(reverse=reverse), zipfile.ZipFile(root/'conflict.zip', 'w') as package:
                    for name in sorted(members, reverse=reverse):
                        entry = zipfile.ZipInfo(name); entry.external_attr = (stat.S_IFREG | 0o644) << 16
                        package.writestr(entry, members[name])
                with self.assertRaisesRegex(ValueError, 'file/directory conflict'): builder.verify(root/'conflict.zip')

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
