import hashlib
import importlib.util
import json
from pathlib import Path

import pytest

import php_cms_release
import php_cms_source_release


ROOT = Path(__file__).resolve().parents[1]


def _module(name: str, relative: str):
    spec = importlib.util.spec_from_file_location(name, ROOT / relative)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def _write(path: Path, body: bytes, mode: int = 0o644):
    path.write_bytes(body)
    path.chmod(mode)


def _fixture(tmp_path: Path):
    source_builder = _module(
        "build_php_cms_source_release_for_promotion",
        "scripts/build_php_cms_source_release.py",
    )
    installer_builder = _module(
        "build_php_cms_installer_for_promotion",
        "scripts/build_php_cms_installer.py",
    )
    promotion = _module(
        "promote_php_cms_release_for_test",
        "scripts/promote_php_cms_release.py",
    )
    verifier = _module(
        "verify_php_cms_public_release_for_test",
        "scripts/verify_php_cms_public_release.py",
    )
    private, public = php_cms_release.generate_key_pair()
    public_path = tmp_path / "public.pem"
    _write(public_path, public)
    fingerprint = php_cms_release.public_key_fingerprint(public)

    source_archive = tmp_path / "source.zip"
    source_builder.build_source_release(source_archive)
    source_value = source_builder.release_envelope(
        source_archive, issued_at=1_785_900_000)
    source_envelope = tmp_path / "source-envelope.json"
    _write(source_envelope, php_cms_source_release.canonical(source_value) + b"\n")
    source_token = tmp_path / "source.token"
    _write(source_token, (
        php_cms_source_release.sign_envelope(source_value, private) + "\n"
    ).encode())

    installer_value = installer_builder.release_envelope(issued_at=1_785_900_000)
    installer_envelope = tmp_path / "installer-envelope.json"
    _write(installer_envelope, php_cms_release.canonical(installer_value) + b"\n")
    installer_token = tmp_path / "installer.token"
    _write(installer_token, (
        php_cms_release.sign_envelope(installer_value, private) + "\n"
    ).encode())
    return promotion, verifier, {
        "public_key": public_path,
        "expected_fingerprint": fingerprint,
        "source_archive": source_archive,
        "source_envelope": source_envelope,
        "source_token": source_token,
        "installer_envelope": installer_envelope,
        "installer_token": installer_token,
        "published_at": 1_785_900_100,
    }


def test_release_promotion_requires_both_signatures_and_stages_public_records(tmp_path):
    promotion, verifier, values = _fixture(tmp_path)
    output = tmp_path / "promotion"
    result = promotion.promote(output=output, **values)
    version = result["version"]
    release = json.loads((
        output / "releases" / version / "release.json").read_text("utf-8"))
    trust = json.loads((
        output / "trust" / "cms-release-key.json").read_text("utf-8"))
    current = json.loads((output / "releases" / "current.json").read_text("utf-8"))
    index = json.loads((output / "releases" / "index.json").read_text("utf-8"))
    assert release["source"]["sha256"] == hashlib.sha256(
        values["source_archive"].read_bytes()).hexdigest()
    assert release["public_key_fingerprint"] == values["expected_fingerprint"]
    assert trust["public_key_fingerprint"] == values["expected_fingerprint"]
    assert trust["signing_assurance"] == "offline-production"
    assert trust["status"] == "active"
    assert current == release
    assert index["current"] == version
    assert release["source"]["checksums_file"].endswith("/SHA256SUMS")
    assert release["installer"]["availability"] == "public-single-file"
    assert release["installer"]["file"] == f"/releases/{version}/install.php"
    installer = output / "releases" / version / "install.php"
    assert installer.is_file()
    assert release["installer"]["sha256"] == hashlib.sha256(
        installer.read_bytes()).hexdigest()
    verified = verifier.verify(output)
    assert verified["source_sha256"] == release["source"]["sha256"]
    assert verified["release_count"] == 1
    assert not list(output.rglob("*private*"))


def test_release_promotion_rejects_changed_source_archive(tmp_path):
    promotion, _, values = _fixture(tmp_path)
    values["source_archive"].write_bytes(
        values["source_archive"].read_bytes() + b"tampered")
    with pytest.raises(
        php_cms_source_release.CmsSourceReleaseError,
        match="字节数与签名摘要不一致",
    ):
        promotion.promote(output=tmp_path / "promotion", **values)


def test_release_promotion_rejects_unpinned_public_key(tmp_path):
    promotion, _, values = _fixture(tmp_path)
    values["expected_fingerprint"] = "f" * 64
    with pytest.raises(promotion.PromotionError, match="外部固定指纹不一致"):
        promotion.promote(output=tmp_path / "promotion", **values)


def test_public_release_verifier_rejects_changed_download(tmp_path):
    promotion, verifier, values = _fixture(tmp_path)
    output = tmp_path / "promotion"
    result = promotion.promote(output=output, **values)
    archive = (
        output / "releases" / result["version"]
        / f"kuaiz-cms-community-{result['version']}.zip"
    )
    archive.write_bytes(archive.read_bytes() + b"tampered")
    with pytest.raises(
        php_cms_source_release.CmsSourceReleaseError,
        match="字节数与签名摘要不一致",
    ):
        verifier.verify(output)


def test_public_release_verifier_rejects_changed_installer(tmp_path):
    promotion, verifier, values = _fixture(tmp_path)
    output = tmp_path / "promotion"
    result = promotion.promote(output=output, **values)
    installer = output / "releases" / result["version"] / "install.php"
    installer.write_bytes(installer.read_bytes() + b"tampered")
    with pytest.raises(
        verifier.PublicReleaseVerificationError,
        match="公开安装文件校验信息不正确",
    ):
        verifier.verify(output)


def test_release_promotion_rejects_unknown_support_status(tmp_path):
    promotion, _, values = _fixture(tmp_path)
    values["support_status"] = "latest"
    with pytest.raises(promotion.PromotionError, match="支持状态不正确"):
        promotion.promote(output=tmp_path / "promotion", **values)


def test_release_promotion_rejects_supported_online_local_root(tmp_path):
    promotion, _, values = _fixture(tmp_path)
    values["support_status"] = "supported"
    values["signing_assurance"] = "online-local-keychain-developer-preview"
    with pytest.raises(promotion.PromotionError, match="必须使用离线生产签名根"):
        promotion.promote(output=tmp_path / "promotion", **values)


def test_release_promotion_rejects_reusing_the_previous_version(tmp_path):
    promotion, _, values = _fixture(tmp_path)
    previous = tmp_path / "previous"
    promotion.promote(output=previous, **values)
    with pytest.raises(promotion.PromotionError, match="必须高于上一公开版本"):
        promotion.promote(
            output=tmp_path / "promotion",
            previous_public_root=previous,
            **values,
        )


def test_public_release_verifier_rejects_unindexed_file(tmp_path):
    promotion, verifier, values = _fixture(tmp_path)
    output = tmp_path / "promotion"
    result = promotion.promote(output=output, **values)
    (output / "releases" / result["version"] / "extra.txt").write_text("extra")
    with pytest.raises(
        verifier.PublicReleaseVerificationError,
        match="包含未登记文件",
    ):
        verifier.verify(output)
