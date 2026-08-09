#!/usr/bin/env python3
"""Verify and stage one public Kuaiz CMS Community release without private keys."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import secrets
import shutil
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))
SCRIPTS = Path(__file__).resolve().parent
if str(SCRIPTS) not in sys.path:
    sys.path.insert(0, str(SCRIPTS))

import php_cms_release  # noqa: E402
import php_cms_source_release  # noqa: E402
from verify_php_cms_public_release import verify as verify_public_release  # noqa: E402
from build_php_cms_installer import build_installer  # noqa: E402
from cms_release_files import read_control_file  # noqa: E402


MAX_METADATA_BYTES = 64 * 1024
HEX64_RE = re.compile(r"^[0-9a-f]{64}$")
SIGNING_ASSURANCE = {
    "offline-production",
    "online-local-keychain-developer-preview",
}


class PromotionError(ValueError):
    """A public CMS release input or destination is unsafe."""


def _json(path: Path, label: str) -> tuple[dict, bytes]:
    raw = read_control_file(str(path), limit=MAX_METADATA_BYTES, private=False)
    try:
        value = json.loads(raw)
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise PromotionError(f"{label}不是有效 JSON") from exc
    return value, raw


def _token(path: Path, label: str) -> str:
    return read_control_file(
        str(path), limit=32 * 1024, private=False).decode("ascii").strip()


def _copy(source: Path, target: Path, mode: int = 0o644) -> None:
    if source.is_symlink() or not source.is_file():
        raise PromotionError(f"发行文件缺失或类型不安全：{source.name}")
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(source, target)
    os.chmod(target, mode)


def _version_order(value: str) -> tuple[int, int, int, int]:
    match = php_cms_release.VERSION_RE.fullmatch(value)
    if match is None:
        raise PromotionError("CMS 版本号不正确")
    core, separator, _ = value.partition("-")
    major, minor, patch = (int(part) for part in core.split("."))
    return major, minor, patch, 0 if separator else 1


def promote(
    *,
    output: Path,
    public_key: Path,
    expected_fingerprint: str,
    source_archive: Path,
    source_envelope: Path,
    source_token: Path,
    installer_envelope: Path,
    installer_token: Path,
    published_at: int,
    support_status: str = "developer-preview",
    previous_public_root: Path | None = None,
    signing_assurance: str = "offline-production",
) -> dict:
    if not HEX64_RE.fullmatch(expected_fingerprint):
        raise PromotionError("固定的 CMS 发行公钥指纹格式不正确")
    public = read_control_file(str(public_key), limit=16 * 1024, private=False)
    fingerprint = php_cms_release.public_key_fingerprint(public)
    if fingerprint != expected_fingerprint:
        raise PromotionError("CMS 发行公钥与外部固定指纹不一致")

    source_value, source_raw = _json(source_envelope, "CMS 源码包发行摘要")
    source_clean = php_cms_source_release.validate_envelope(source_value)
    if source_raw.strip() != php_cms_source_release.canonical(source_clean):
        raise PromotionError("CMS 源码包发行摘要不是规范 JSON")
    source_verified = php_cms_source_release.verify_token(
        _token(source_token, "CMS 源码包签名"), public)
    if source_verified != source_clean:
        raise PromotionError("CMS 源码包签名不属于该发行摘要")
    archive_body = read_control_file(
        str(source_archive), limit=64 * 1024 * 1024, private=False)
    php_cms_source_release.verify_archive(source_clean, archive_body)

    installer_value, installer_raw = _json(
        installer_envelope, "CMS 安装器发行摘要")
    installer_clean = php_cms_release.validate_envelope(installer_value)
    if installer_raw.strip() != php_cms_release.canonical(installer_clean):
        raise PromotionError("CMS 安装器发行摘要不是规范 JSON")
    installer_signature = _token(installer_token, "CMS 安装器发行签名")
    installer_verified = php_cms_release.verify_token(installer_signature, public)
    if installer_verified != installer_clean:
        raise PromotionError("CMS 安装器签名不属于该发行摘要")
    if installer_clean["release_version"] != source_clean["release_version"]:
        raise PromotionError("CMS 源码包与安装器版本不一致")
    if int(published_at) <= 0:
        raise PromotionError("CMS 公开时间不正确")
    if support_status not in {"developer-preview", "release-candidate", "supported"}:
        raise PromotionError("CMS 版本支持状态不正确")
    if signing_assurance not in SIGNING_ASSURANCE:
        raise PromotionError("CMS 签名保障等级不正确")
    if (support_status in {"release-candidate", "supported"}
            and signing_assurance != "offline-production"):
        raise PromotionError("候选版和受支持版本必须使用离线生产签名根")
    version = source_clean["release_version"]

    previous_directory = None
    previous_index_records: list[dict] = []
    previous_trust = None
    if previous_public_root is not None:
        previous_directory = Path(previous_public_root).expanduser().resolve()
        previous_result = verify_public_release(previous_directory)
        if previous_result["public_key_fingerprint"] != fingerprint:
            raise PromotionError("上一公开发行目录使用了不同的生产信任根")
        if _version_order(version) <= _version_order(str(previous_result["version"])):
            raise PromotionError("新 CMS 版本必须高于上一公开版本")
        previous_index, _ = _json(
            previous_directory / "releases" / "index.json", "上一版本索引"
        )
        previous_index_records = list(previous_index["releases"])
        previous_trust, _ = _json(
            previous_directory / "trust" / "cms-release-key.json", "上一信任记录"
        )
        if previous_trust.get("signing_assurance") != signing_assurance:
            raise PromotionError("同一 CMS 发行根不能改变签名保障等级")
        previous_published_at = int(previous_index_records[0]["published_at"])
        if int(published_at) <= previous_published_at:
            raise PromotionError("新 CMS 公开时间必须晚于上一公开版本")

    destination = Path(output).expanduser().resolve()
    if destination.exists():
        raise PromotionError("CMS 发行提升目录必须不存在，不能覆盖旧记录")
    temporary = destination.with_name(
        f".{destination.name}.{secrets.token_hex(6)}.tmp")
    release_dir = temporary / "releases" / version
    trust_dir = temporary / "trust"
    try:
        if previous_directory is not None:
            shutil.copytree(previous_directory, temporary)
        release_dir.mkdir(parents=True, mode=0o755)
        trust_dir.mkdir(parents=True, mode=0o755, exist_ok=True)
        _copy(public_key, trust_dir / "cms-release-public.pem")
        _copy(source_archive, release_dir / f"kuaiz-cms-community-{version}.zip")
        _copy(source_envelope, release_dir / "source-envelope.json")
        _copy(source_token, release_dir / "source-envelope.token")
        _copy(installer_envelope, release_dir / "installer-envelope.json")
        _copy(installer_token, release_dir / "installer-envelope.token")
        installer_build = build_installer(
            release_dir / "install.php",
            None,
            installer_signature,
            public,
        )
        os.chmod(release_dir / "install.php", 0o644)

        trust = previous_trust or {
            "schema": "kuaiz-cms-release-trust/v1",
            "algorithm": "Ed25519",
            "fingerprint_algorithm": "SHA-256-SPKI-DER",
            "public_key_file": "/trust/cms-release-public.pem",
            "public_key_fingerprint": fingerprint,
            "signing_assurance": signing_assurance,
            "status": "active",
            "published_at": int(published_at),
        }
        source_name = f"kuaiz-cms-community-{version}.zip"
        checksums_name = "SHA256SUMS"
        release = {
            "schema": "kuaiz-cms-public-release/v1",
            "version": version,
            "license": "Apache-2.0",
            "runtime_profile": source_clean["runtime_profile"],
            "published_at": int(published_at),
            "support_status": support_status,
            "public_key_fingerprint": fingerprint,
            "signing_assurance": signing_assurance,
            "trust": {
                "metadata_file": "/trust/cms-release-key.json",
                "public_key_file": "/trust/cms-release-public.pem",
            },
            "source": {
                "file": f"/releases/{version}/{source_name}",
                "sha256": source_clean["archive_sha256"],
                "byte_size": source_clean["archive_bytes"],
                "envelope_file": f"/releases/{version}/source-envelope.json",
                "signature_file": f"/releases/{version}/source-envelope.token",
                "checksums_file": f"/releases/{version}/{checksums_name}",
            },
            "installer": {
                "availability": "public-single-file",
                "file": f"/releases/{version}/install.php",
                "sha256": installer_build["sha256"],
                "byte_size": installer_build["byte_size"],
                "payload_sha256": installer_clean["payload_sha256"],
                "template_sha256": installer_clean["template_sha256"],
                "envelope_file": f"/releases/{version}/installer-envelope.json",
                "signature_file": f"/releases/{version}/installer-envelope.token",
            },
        }
        checksums = []
        for name in (
            source_name,
            "source-envelope.json",
            "source-envelope.token",
            "installer-envelope.json",
            "installer-envelope.token",
            "install.php",
        ):
            checksums.append(
                f"{hashlib.sha256((release_dir / name).read_bytes()).hexdigest()}  {name}\n"
            )
        (release_dir / checksums_name).write_text("".join(checksums), encoding="ascii")
        index = {
            "schema": "kuaiz-cms-public-release-index/v1",
            "current": version,
            "updated_at": int(published_at),
            "releases": [{
                "version": version,
                "published_at": int(published_at),
                "support_status": support_status,
                "signing_assurance": signing_assurance,
                "metadata_file": f"/releases/{version}/release.json",
            }, *previous_index_records],
        }
        for target, value in (
            (trust_dir / "cms-release-key.json", trust),
            (release_dir / "release.json", release),
            (temporary / "releases" / "current.json", release),
            (temporary / "releases" / "index.json", index),
        ):
            target.write_bytes(json.dumps(
                value, ensure_ascii=False, sort_keys=True, indent=2
            ).encode("utf-8") + b"\n")
            os.chmod(target, 0o644)
        os.chmod(release_dir / checksums_name, 0o644)
        os.rename(temporary, destination)
    except Exception:
        if temporary.exists():
            shutil.rmtree(temporary)
        raise

    return {
        "ok": True,
        "path": str(destination),
        "version": version,
        "public_key_fingerprint": fingerprint,
        "source_sha256": source_clean["archive_sha256"],
    }


def main() -> int:
    parser = argparse.ArgumentParser(
        description="验签并提升快智 CMS Community 公开发行记录")
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--public-key", required=True, type=Path)
    parser.add_argument("--expected-fingerprint", required=True)
    parser.add_argument("--source-archive", required=True, type=Path)
    parser.add_argument("--source-envelope", required=True, type=Path)
    parser.add_argument("--source-token", required=True, type=Path)
    parser.add_argument("--installer-envelope", required=True, type=Path)
    parser.add_argument("--installer-token", required=True, type=Path)
    parser.add_argument("--published-at", required=True, type=int)
    parser.add_argument(
        "--support-status",
        choices=["developer-preview", "release-candidate", "supported"],
        default="developer-preview",
    )
    parser.add_argument(
        "--previous-public-root",
        type=Path,
        help="已验证的上一版公开发行目录；提供后会保留旧版本并追加索引",
    )
    parser.add_argument(
        "--signing-assurance",
        choices=sorted(SIGNING_ASSURANCE),
        default="offline-production",
        help="签名根隔离等级；非离线根只能发布 Developer Preview",
    )
    args = parser.parse_args()
    try:
        result = promote(**vars(args))
    except (OSError, ValueError, UnicodeDecodeError) as exc:
        print(f"错误：{exc}", file=sys.stderr)
        return 2
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
