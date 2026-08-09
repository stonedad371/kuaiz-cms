#!/usr/bin/env python3
"""Verify a staged or downloaded Kuaiz CMS public release directory."""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path, PurePosixPath


ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import php_cms_release  # noqa: E402
import php_cms_source_release  # noqa: E402


HEX64_RE = re.compile(r"^[0-9a-f]{64}$")
VERSION_RE = php_cms_release.VERSION_RE
SIGNING_ASSURANCE = {
    "offline-production",
    "online-local-keychain-developer-preview",
}


class PublicReleaseVerificationError(ValueError):
    """The public release directory is incomplete, changed, or unsigned."""


def _file(root: Path, relative: str, maximum: int) -> bytes:
    pure = PurePosixPath(relative)
    if pure.is_absolute() or ".." in pure.parts or str(pure) != relative:
        raise PublicReleaseVerificationError(f"公开发行路径不安全：{relative}")
    path = root / relative
    if path.is_symlink() or not path.is_file():
        raise PublicReleaseVerificationError(f"公开发行文件缺失：{relative}")
    body = path.read_bytes()
    if not body or len(body) > maximum:
        raise PublicReleaseVerificationError(f"公开发行文件大小异常：{relative}")
    return body


def _json(root: Path, relative: str) -> dict:
    try:
        value = json.loads(_file(root, relative, 256 * 1024))
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise PublicReleaseVerificationError(
            f"公开发行 JSON 无法读取：{relative}"
        ) from exc
    if not isinstance(value, dict):
        raise PublicReleaseVerificationError(f"公开发行 JSON 类型不正确：{relative}")
    return value


def _relative_url(value: object, prefix: str) -> str:
    if not isinstance(value, str) or not value.startswith(prefix) or "?" in value or "#" in value:
        raise PublicReleaseVerificationError("公开发行文件地址不正确")
    return value.removeprefix("/")


def _verify_release(
    directory: Path,
    release: dict,
    version: str,
    public_key: bytes,
    fingerprint: str,
    signing_assurance: str,
) -> str:
    if (release.get("schema") != "kuaiz-cms-public-release/v1"
            or release.get("version") != version
            or release.get("public_key_fingerprint") != fingerprint
            or release.get("signing_assurance") != signing_assurance
            or release.get("support_status") not in {
                "developer-preview", "release-candidate", "supported"
            }):
        raise PublicReleaseVerificationError(f"版本 {version} 的公开记录不正确")
    source = release.get("source")
    installer = release.get("installer")
    if not isinstance(source, dict) or not isinstance(installer, dict):
        raise PublicReleaseVerificationError(f"版本 {version} 的下载记录不完整")
    archive_relative = _relative_url(source.get("file"), f"/releases/{version}/")
    source_envelope_relative = _relative_url(
        source.get("envelope_file"), f"/releases/{version}/"
    )
    source_token_relative = _relative_url(
        source.get("signature_file"), f"/releases/{version}/"
    )
    installer_envelope_relative = _relative_url(
        installer.get("envelope_file"), f"/releases/{version}/"
    )
    installer_token_relative = _relative_url(
        installer.get("signature_file"), f"/releases/{version}/"
    )
    installer_file_relative = None
    availability = installer.get("availability")
    if availability == "public-single-file":
        installer_file_relative = _relative_url(
            installer.get("file"), f"/releases/{version}/"
        )
        if Path(installer_file_relative).name != "install.php":
            raise PublicReleaseVerificationError("公开安装文件名称不正确")
        installer_file = _file(directory, installer_file_relative, 4 * 1024 * 1024)
        if (installer.get("sha256") != hashlib.sha256(installer_file).hexdigest()
                or installer.get("byte_size") != len(installer_file)):
            raise PublicReleaseVerificationError("公开安装文件校验信息不正确")
    elif availability != "personalized-download-service":
        raise PublicReleaseVerificationError(f"版本 {version} 的安装方式不正确")

    archive = _file(directory, archive_relative, 64 * 1024 * 1024)
    source_envelope = _json(directory, source_envelope_relative)
    source_token = _file(directory, source_token_relative, 32 * 1024).decode("ascii").strip()
    source_clean = php_cms_source_release.validate_envelope(source_envelope)
    if php_cms_source_release.verify_token(source_token, public_key) != source_clean:
        raise PublicReleaseVerificationError("源码包签名与摘要不一致")
    php_cms_source_release.verify_archive(source_clean, archive)
    if source.get("sha256") != hashlib.sha256(archive).hexdigest():
        raise PublicReleaseVerificationError("源码包下载哈希不正确")

    installer_envelope = _json(directory, installer_envelope_relative)
    installer_token = _file(
        directory, installer_token_relative, 32 * 1024
    ).decode("ascii").strip()
    installer_clean = php_cms_release.validate_envelope(installer_envelope)
    if php_cms_release.verify_token(installer_token, public_key) != installer_clean:
        raise PublicReleaseVerificationError("安装器签名与摘要不一致")
    if installer_clean["release_version"] != version:
        raise PublicReleaseVerificationError("安装器与源码包版本不一致")
    if installer.get("payload_sha256") != installer_clean["payload_sha256"]:
        raise PublicReleaseVerificationError("安装器载荷摘要不一致")
    if installer.get("template_sha256") != installer_clean["template_sha256"]:
        raise PublicReleaseVerificationError("安装器模板摘要不一致")

    checksums_relative = _relative_url(
        source.get("checksums_file"), f"/releases/{version}/"
    )
    checksum_lines = _file(directory, checksums_relative, 64 * 1024).decode("ascii").splitlines()
    seen = set()
    for line in checksum_lines:
        if not re.fullmatch(r"[0-9a-f]{64}  [A-Za-z0-9._-]+", line):
            raise PublicReleaseVerificationError("公开校验清单格式不正确")
        digest, name = line.split("  ", 1)
        relative = f"releases/{version}/{name}"
        if name in seen or hashlib.sha256(_file(directory, relative, 64 * 1024 * 1024)).hexdigest() != digest:
            raise PublicReleaseVerificationError("公开校验清单与文件不一致")
        seen.add(name)
    expected = {
        Path(archive_relative).name,
        Path(source_envelope_relative).name,
        Path(source_token_relative).name,
        Path(installer_envelope_relative).name,
        Path(installer_token_relative).name,
    }
    if installer_file_relative is not None:
        expected.add(Path(installer_file_relative).name)
    if seen != expected:
        raise PublicReleaseVerificationError("公开校验清单文件集合不完整")
    actual_entries = {
        path.name for path in (directory / "releases" / version).iterdir()
    }
    if actual_entries != expected | {"release.json", "SHA256SUMS"}:
        raise PublicReleaseVerificationError(f"版本 {version} 包含未登记文件")
    return source_clean["archive_sha256"]


def verify(root: Path) -> dict:
    directory = Path(root).expanduser().resolve()
    if directory.is_symlink() or not directory.is_dir():
        raise PublicReleaseVerificationError("公开发行目录缺失或类型不安全")
    for path in directory.rglob("*"):
        if path.is_symlink() or (path.is_file() and "private" in path.name.lower()):
            raise PublicReleaseVerificationError("公开发行目录包含不安全文件")
    if {path.name for path in directory.iterdir()} != {"releases", "trust"}:
        raise PublicReleaseVerificationError("公开发行目录包含未登记的顶层文件")
    if {path.name for path in (directory / "trust").iterdir()} != {
        "cms-release-key.json", "cms-release-public.pem"
    }:
        raise PublicReleaseVerificationError("公开信任目录包含未登记文件")

    current = _json(directory, "releases/current.json")
    version = str(current.get("version") or "")
    if not VERSION_RE.fullmatch(version):
        raise PublicReleaseVerificationError("当前版本号不正确")
    release = _json(directory, f"releases/{version}/release.json")
    if release != current:
        raise PublicReleaseVerificationError("当前版本与不可变版本记录不一致")
    index = _json(directory, "releases/index.json")
    records = index.get("releases")
    if (index.get("schema") != "kuaiz-cms-public-release-index/v1"
            or index.get("current") != version
            or not isinstance(records, list) or not records):
        raise PublicReleaseVerificationError("版本索引不正确")

    trust = _json(directory, "trust/cms-release-key.json")
    public_key = _file(directory, "trust/cms-release-public.pem", 16 * 1024)
    fingerprint = php_cms_release.public_key_fingerprint(public_key)
    if not HEX64_RE.fullmatch(fingerprint) or trust.get("public_key_fingerprint") != fingerprint:
        raise PublicReleaseVerificationError("发行公钥与官网信任记录不一致")
    signing_assurance = str(trust.get("signing_assurance") or "")
    if (current.get("public_key_fingerprint") != fingerprint
            or trust.get("status") != "active"
            or signing_assurance not in SIGNING_ASSURANCE
            or current.get("signing_assurance") != signing_assurance):
        raise PublicReleaseVerificationError("当前版本没有绑定有效信任根")
    if (current.get("support_status") in {"release-candidate", "supported"}
            and signing_assurance != "offline-production"):
        raise PublicReleaseVerificationError("候选版或受支持版本没有使用离线生产根")

    versions = []
    current_source_sha256 = ""
    last_published_at: int | None = None
    for position, record in enumerate(records):
        if not isinstance(record, dict):
            raise PublicReleaseVerificationError("版本索引条目类型不正确")
        record_version = str(record.get("version") or "")
        if (not VERSION_RE.fullmatch(record_version)
                or record.get("metadata_file") != f"/releases/{record_version}/release.json"
                or record_version in versions):
            raise PublicReleaseVerificationError("版本索引条目不正确或重复")
        immutable = _json(directory, f"releases/{record_version}/release.json")
        published_at = immutable.get("published_at")
        if (record.get("published_at") != published_at
                or record.get("support_status") != immutable.get("support_status")
                or record.get("signing_assurance") != immutable.get("signing_assurance")
                or not isinstance(published_at, int) or published_at <= 0
                or (last_published_at is not None and published_at >= last_published_at)):
            raise PublicReleaseVerificationError("版本索引与不可变记录不一致")
        if position == 0 and immutable != current:
            raise PublicReleaseVerificationError("当前版本必须位于版本索引首位")
        source_sha256 = _verify_release(
            directory,
            immutable,
            record_version,
            public_key,
            fingerprint,
            signing_assurance,
        )
        if record_version == version:
            current_source_sha256 = source_sha256
        versions.append(record_version)
        last_published_at = published_at
    expected_release_entries = {"current.json", "index.json", *versions}
    if {path.name for path in (directory / "releases").iterdir()} != expected_release_entries:
        raise PublicReleaseVerificationError("公开版本目录与版本索引不一致")
    return {
        "ok": True,
        "version": version,
        "support_status": current.get("support_status"),
        "public_key_fingerprint": fingerprint,
        "signing_assurance": signing_assurance,
        "source_sha256": current_source_sha256,
        "release_count": len(versions),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="验证快智 CMS 官网公开发行目录")
    parser.add_argument("root", type=Path)
    args = parser.parse_args()
    try:
        result = verify(args.root)
    except (OSError, ValueError, UnicodeDecodeError) as exc:
        print(f"错误：{exc}", file=sys.stderr)
        return 2
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
