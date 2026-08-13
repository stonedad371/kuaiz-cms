import hashlib
import importlib.util
import json
import zipfile
from pathlib import Path

from php_cms_distribution import CMS_SOURCE_FILES


ROOT = Path(__file__).resolve().parents[1]
CMS = ROOT
BUILDER_PATH = ROOT / "scripts" / "build_php_cms_source_release.py"


def _builder_module():
    spec = importlib.util.spec_from_file_location(
        "build_php_cms_source_release", BUILDER_PATH)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def test_source_inventory_is_explicit_and_complete():
    ignored_roots = {
        ".git", ".venv", ".pytest_cache", "artifacts", "__pycache__",
        "node_modules", "playwright-report", "test-results",
    }
    actual = {
        path.relative_to(CMS).as_posix()
        for path in CMS.rglob("*")
        if path.is_file()
        and not any(part in ignored_roots for part in path.relative_to(CMS).parts)
        and path.name != ".DS_Store"
    }
    assert set(CMS_SOURCE_FILES) == actual
    assert all(".." not in Path(name).parts for name in CMS_SOURCE_FILES)
    assert not any(name.endswith((".pem", ".token", ".env"))
                   for name in CMS_SOURCE_FILES)


def test_source_archive_is_reproducible_allowlisted_and_self_describing(tmp_path):
    builder = _builder_module()
    first = tmp_path / "first.zip"
    second = tmp_path / "second.zip"
    result = builder.build_source_release(first)
    result_two = builder.build_source_release(second)

    assert first.read_bytes() == second.read_bytes()
    assert result["sha256"] == result_two["sha256"]
    assert result["license"] == "Apache-2.0"
    assert result["file_count"] == len(CMS_SOURCE_FILES)
    prefix = f"kuaiz-cms-community-{result['version']}/"

    with zipfile.ZipFile(first) as archive:
        names = archive.namelist()
        assert names == [f"{prefix}{name}" for name in CMS_SOURCE_FILES] + [
            f"{prefix}source-manifest.json",
            f"{prefix}SHA256SUMS",
        ]
        source_manifest = json.loads(
            archive.read(f"{prefix}source-manifest.json"))
        assert source_manifest["schema"] == "kuaiz-cms-source-release/v1"
        assert source_manifest["license"] == "Apache-2.0"
        assert source_manifest["totals"]["file_count"] == len(CMS_SOURCE_FILES)
        expected = {}
        for item in source_manifest["files"]:
            body = archive.read(f"{prefix}{item['path']}")
            assert len(body) == item["byte_size"]
            assert hashlib.sha256(body).hexdigest() == item["sha256"]
            expected[item["path"]] = item["sha256"]
        checksum_lines = archive.read(f"{prefix}SHA256SUMS").decode().splitlines()
        assert checksum_lines == [
            f"{expected[name]}  {name}" for name in CMS_SOURCE_FILES
        ]
        assert archive.read(f"{prefix}LICENSE").startswith(
            b"                                 Apache License")


def test_source_builder_rejects_non_zip_output(tmp_path):
    builder = _builder_module()
    try:
        builder.build_source_release(tmp_path / "source.tar")
    except builder.SourceReleaseError as exc:
        assert "必须使用 .zip" in str(exc)
    else:
        raise AssertionError("non-zip source target was accepted")
