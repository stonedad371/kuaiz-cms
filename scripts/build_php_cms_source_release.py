#!/usr/bin/env python3
"""Build a deterministic, allowlisted Kuaiz CMS Community source archive."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import secrets
import sys
import zipfile
import time
from pathlib import Path, PurePosixPath


ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from php_cms_distribution import CMS_SOURCE_FILES  # noqa: E402
import php_cms_source_release  # noqa: E402


CMS = ROOT
SOURCE_SCHEMA = "kuaiz-cms-source-release/v1"
MAX_FILE_BYTES = 8 * 1024 * 1024
MAX_TOTAL_BYTES = 32 * 1024 * 1024
FIXED_ZIP_TIME = (2026, 1, 1, 0, 0, 0)


class SourceReleaseError(ValueError):
    """The Community source inventory or archive target is unsafe."""


def _canonical(value: dict) -> bytes:
    return json.dumps(
        value, ensure_ascii=False, sort_keys=True, separators=(",", ":")
    ).encode("utf-8")


def _source(relative: str) -> bytes:
    pure = PurePosixPath(relative)
    if pure.is_absolute() or ".." in pure.parts or str(pure) != relative:
        raise SourceReleaseError(f"CMS 社区版源码路径不安全：{relative}")
    source = CMS / relative
    if source.is_symlink() or not source.is_file():
        raise SourceReleaseError(f"CMS 社区版源码缺失或类型不安全：{relative}")
    resolved = source.resolve()
    if CMS.resolve() not in resolved.parents:
        raise SourceReleaseError(f"CMS 社区版源码越界：{relative}")
    body = source.read_bytes()
    if not body or len(body) > MAX_FILE_BYTES:
        raise SourceReleaseError(f"CMS 社区版源码大小不安全：{relative}")
    return body


def _entry(name: str, body: bytes, mode: int = 0o644) -> tuple[zipfile.ZipInfo, bytes]:
    info = zipfile.ZipInfo(name, date_time=FIXED_ZIP_TIME)
    info.create_system = 3
    info.external_attr = (mode & 0xFFFF) << 16
    info.compress_type = zipfile.ZIP_DEFLATED
    return info, body


def build_source_release(output: Path) -> dict:
    manifest = json.loads((CMS / "cms-manifest.json").read_text("utf-8"))
    if manifest.get("runtime_profile") != "community-php-sqlite-v1":
        raise SourceReleaseError("CMS runtime profile 不正确")
    if manifest.get("license") != "Apache-2.0":
        raise SourceReleaseError("CMS 社区版必须明确使用 Apache-2.0")
    version = str(manifest.get("version") or "")
    prefix = f"kuaiz-cms-community-{version}"

    bodies: list[tuple[str, bytes]] = []
    records: list[dict] = []
    total = 0
    for relative in CMS_SOURCE_FILES:
        body = _source(relative)
        total += len(body)
        if total > MAX_TOTAL_BYTES:
            raise SourceReleaseError("CMS 社区版源码包过大")
        bodies.append((relative, body))
        records.append({
            "path": relative,
            "byte_size": len(body),
            "sha256": hashlib.sha256(body).hexdigest(),
        })

    source_manifest = {
        "schema": SOURCE_SCHEMA,
        "name": manifest["name"],
        "version": version,
        "runtime_profile": manifest["runtime_profile"],
        "database_schema_version": manifest["database_schema_version"],
        "license": manifest["license"],
        "files": records,
        "totals": {"file_count": len(records), "byte_size": total},
    }
    manifest_body = _canonical(source_manifest) + b"\n"
    checksums_body = "".join(
        f"{item['sha256']}  {item['path']}\n" for item in records
    ).encode("utf-8")

    target = Path(output).expanduser().resolve()
    if target.suffix.lower() != ".zip":
        raise SourceReleaseError("CMS 社区版源码包必须使用 .zip")
    if target.exists() and target.is_symlink():
        raise SourceReleaseError("CMS 社区版源码包输出不能是符号链接")
    target.parent.mkdir(parents=True, exist_ok=True)
    temporary = target.with_name(f".{target.name}.{secrets.token_hex(6)}.tmp")
    try:
        with zipfile.ZipFile(
            temporary, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9
        ) as archive:
            for relative, body in bodies:
                info, content = _entry(f"{prefix}/{relative}", body)
                archive.writestr(info, content)
            for relative, body in (
                ("source-manifest.json", manifest_body),
                ("SHA256SUMS", checksums_body),
            ):
                info, content = _entry(f"{prefix}/{relative}", body)
                archive.writestr(info, content)
        os.chmod(temporary, 0o644)
        temporary.replace(target)
        os.chmod(target, 0o644)
    finally:
        temporary.unlink(missing_ok=True)

    return {
        "schema": SOURCE_SCHEMA,
        "path": str(target),
        "version": version,
        "license": manifest["license"],
        "sha256": hashlib.sha256(target.read_bytes()).hexdigest(),
        "byte_size": target.stat().st_size,
        "file_count": len(records),
    }


def release_envelope(archive: Path, issued_at: int | None = None) -> dict:
    source = Path(archive)
    if source.is_symlink() or not source.is_file():
        raise SourceReleaseError("CMS 社区版源码包缺失或类型不安全")
    body = source.read_bytes()
    if not body or len(body) > 64 * 1024 * 1024:
        raise SourceReleaseError("CMS 社区版源码包大小不安全")
    manifest = json.loads((CMS / "cms-manifest.json").read_text("utf-8"))
    return php_cms_source_release.create_envelope(
        version=str(manifest["version"]),
        archive_sha256=hashlib.sha256(body).hexdigest(),
        archive_bytes=len(body),
        file_count=len(CMS_SOURCE_FILES),
        issued_at=int(time.time() if issued_at is None else issued_at),
    )


def write_envelope(archive: Path, output: Path, issued_at: int | None = None) -> dict:
    envelope = release_envelope(archive, issued_at)
    target = Path(output).expanduser().resolve()
    if target.exists() and target.is_symlink():
        raise SourceReleaseError("CMS 源码包发行摘要输出不能是符号链接")
    target.parent.mkdir(parents=True, exist_ok=True)
    temporary = target.with_name(f".{target.name}.{secrets.token_hex(6)}.tmp")
    try:
        temporary.write_bytes(php_cms_source_release.canonical(envelope) + b"\n")
        os.chmod(temporary, 0o644)
        temporary.replace(target)
        os.chmod(target, 0o644)
    finally:
        temporary.unlink(missing_ok=True)
    return envelope


def main() -> int:
    parser = argparse.ArgumentParser(description="构建快智 CMS Community 可复现源码包")
    parser.add_argument("--output", required=True, type=Path, help="输出 .zip 文件")
    parser.add_argument("--emit-envelope", type=Path, help="同时输出待离线签名的源码包摘要")
    parser.add_argument("--issued-at", type=int, default=None, help="发行摘要 Unix 时间")
    args = parser.parse_args()
    try:
        result = build_source_release(args.output)
        if args.emit_envelope is not None:
            envelope = write_envelope(args.output, args.emit_envelope, args.issued_at)
            result["envelope"] = str(args.emit_envelope.expanduser().resolve())
            result["issued_at"] = envelope["issued_at"]
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        print(f"错误：{exc}", file=sys.stderr)
        return 2
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
