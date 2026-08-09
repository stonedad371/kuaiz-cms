#!/usr/bin/env python3
"""Build one signed, upload-and-open installer for Kuaiz CMS Community."""
from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
import secrets
import sys
import time
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import php_cms_release  # noqa: E402
from php_cms_distribution import CMS_RUNTIME_FILES  # noqa: E402


CMS = ROOT
TEMPLATE = CMS / "installer-template.php"
PAYLOAD_SCHEMA = "kuaiz-cms-embedded-release/v1"
MAX_FILE_BYTES = 8 * 1024 * 1024
MAX_PAYLOAD_BYTES = 32 * 1024 * 1024

SOURCE_FILES = CMS_RUNTIME_FILES


class InstallerBuildError(ValueError):
    """The release source or output target is unsafe."""


def _read_source(relative: str) -> bytes:
    path = CMS / relative
    if path.is_symlink() or not path.is_file():
        raise InstallerBuildError(f"CMS 安装包源文件缺失或类型不安全：{relative}")
    resolved = path.resolve()
    if CMS.resolve() not in resolved.parents:
        raise InstallerBuildError(f"CMS 安装包源文件越界：{relative}")
    body = path.read_bytes()
    if not body or len(body) > MAX_FILE_BYTES:
        raise InstallerBuildError(f"CMS 安装包源文件大小不安全：{relative}")
    return body


def _release_payload() -> tuple[dict, bytes]:
    manifest = json.loads((CMS / "cms-manifest.json").read_text("utf-8"))
    if manifest.get("runtime_profile") != "community-php-sqlite-v1":
        raise InstallerBuildError("CMS runtime profile 不正确")
    files = []
    total = 0
    for relative in SOURCE_FILES:
        body = _read_source(relative)
        total += len(body)
        if total > MAX_PAYLOAD_BYTES:
            raise InstallerBuildError("CMS 安装载荷过大")
        files.append({
            "path": relative,
            "byte_size": len(body),
            "sha256": hashlib.sha256(body).hexdigest(),
            "body_base64": base64.b64encode(body).decode("ascii"),
        })
    payload = {
        "schema": PAYLOAD_SCHEMA,
        "version": manifest["version"],
        "runtime_profile": manifest["runtime_profile"],
        "database_schema_version": manifest["database_schema_version"],
        "files": files,
        "totals": {"file_count": len(files), "byte_size": total},
    }
    payload_json = json.dumps(
        payload, ensure_ascii=False, sort_keys=True, separators=(",", ":")
    ).encode("utf-8")
    return payload, payload_json


def release_envelope(issued_at: int | None = None) -> dict:
    payload, payload_json = _release_payload()
    if TEMPLATE.is_symlink() or not TEMPLATE.is_file():
        raise InstallerBuildError("安装器模板缺失或类型不安全")
    return php_cms_release.create_envelope(
        version=str(payload["version"]),
        database_schema_version=int(payload["database_schema_version"]),
        template_sha256=hashlib.sha256(TEMPLATE.read_bytes()).hexdigest(),
        payload_sha256=hashlib.sha256(payload_json).hexdigest(),
        file_count=int(payload["totals"]["file_count"]),
        content_bytes=int(payload["totals"]["byte_size"]),
        issued_at=int(time.time() if issued_at is None else issued_at),
    )


def build_installer(
    output: Path,
    install_token: str | None,
    release_token: str,
    public_key_pem: bytes | str,
) -> dict:
    payload, payload_json = _release_payload()
    token = install_token or secrets.token_urlsafe(32)
    if not 32 <= len(token.encode("utf-8")) <= 256 or "\x00" in token:
        raise InstallerBuildError("安装表单保护密钥长度不安全")
    payload_sha256 = hashlib.sha256(payload_json).hexdigest()
    payload_base64 = base64.b64encode(payload_json).decode("ascii")
    try:
        signed_envelope = php_cms_release.verify_token(release_token, public_key_pem)
    except php_cms_release.CmsReleaseError as exc:
        raise InstallerBuildError(str(exc)) from exc
    expected = release_envelope(int(signed_envelope["issued_at"]))
    if signed_envelope != expected:
        raise InstallerBuildError("CMS 发行签名与当前模板或源码不一致")
    public_bytes = (
        public_key_pem.encode() if isinstance(public_key_pem, str)
        else bytes(public_key_pem)
    )
    public_key_base64 = base64.b64encode(public_bytes).decode("ascii")
    public_key_fingerprint = php_cms_release.public_key_fingerprint(public_bytes)
    source = TEMPLATE.read_text("utf-8")
    replacements = {
        "__KUAIZ_INSTALL_TOKEN_SHA256__": hashlib.sha256(token.encode("utf-8")).hexdigest(),
        "__KUAIZ_PAYLOAD_JSON_BASE64__": payload_base64,
        "__KUAIZ_PAYLOAD_SHA256__": payload_sha256,
        "__KUAIZ_RELEASE_VERSION__": str(payload["version"]),
        "__KUAIZ_RELEASE_SIGNATURE_TOKEN__": release_token.strip(),
        "__KUAIZ_RELEASE_PUBLIC_KEY_BASE64__": public_key_base64,
        "__KUAIZ_RELEASE_PUBLIC_KEY_FINGERPRINT__": public_key_fingerprint,
    }
    for placeholder, value in replacements.items():
        if source.count(placeholder) != 1:
            raise InstallerBuildError(f"安装器模板占位符异常：{placeholder}")
        source = source.replace(placeholder, value)
    if "__KUAIZ_" in source:
        raise InstallerBuildError("安装器模板仍有未替换占位符")

    output = Path(output).expanduser().resolve()
    if output.exists() and output.is_symlink():
        raise InstallerBuildError("安装器输出不能是符号链接")
    output.parent.mkdir(parents=True, exist_ok=True)
    temporary = output.with_name(f".{output.name}.{secrets.token_hex(6)}.tmp")
    try:
        temporary.write_text(source, encoding="utf-8", newline="\n")
        os.chmod(temporary, 0o600)
        temporary.replace(output)
        os.chmod(output, 0o600)
    finally:
        temporary.unlink(missing_ok=True)
    return {
        "path": str(output),
        "sha256": hashlib.sha256(output.read_bytes()).hexdigest(),
        "byte_size": output.stat().st_size,
        "payload_sha256": payload_sha256,
        "release_issued_at": signed_envelope["issued_at"],
        "release_public_key_fingerprint": public_key_fingerprint,
        "file_count": int(payload["totals"]["file_count"]),
        "version": payload["version"],
    }


def _read_release_file(path: Path, maximum: int, label: str) -> bytes:
    source = Path(path)
    if source.is_symlink() or not source.is_file() or source.stat().st_size > maximum:
        raise InstallerBuildError(f"{label}缺失或类型不安全")
    return source.read_bytes()


def _write_envelope(path: Path, envelope: dict) -> Path:
    output = Path(path).expanduser().resolve()
    if output.exists() and output.is_symlink():
        raise InstallerBuildError("发行摘要输出不能是符号链接")
    output.parent.mkdir(parents=True, exist_ok=True)
    body = php_cms_release.canonical(envelope) + b"\n"
    temporary = output.with_name(f".{output.name}.{secrets.token_hex(6)}.tmp")
    try:
        temporary.write_bytes(body)
        os.chmod(temporary, 0o600)
        temporary.replace(output)
        os.chmod(output, 0o644)
    finally:
        temporary.unlink(missing_ok=True)
    return output


def main() -> int:
    parser = argparse.ArgumentParser(description="构建快智独立 CMS 单文件安装器")
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--output", type=Path, help="输出已签名 PHP 安装文件")
    mode.add_argument("--emit-envelope", type=Path, help="只输出待签名发行摘要")
    parser.add_argument("--issued-at", type=int, default=None, help="发行摘要 Unix 时间")
    parser.add_argument(
        "--install-token",
        default=None,
        help="指定安装表单保护密钥；省略时安全随机生成",
    )
    parser.add_argument("--release-token", type=Path, help="CMS 发行签名令牌文件")
    parser.add_argument("--public-key", type=Path, help="CMS 发行 Ed25519 公钥")
    args = parser.parse_args()
    if args.emit_envelope is not None:
        envelope = release_envelope(args.issued_at)
        output = _write_envelope(args.emit_envelope, envelope)
        result = {
            "action": "emit-envelope",
            "output": str(output),
            "payload_sha256": envelope["payload_sha256"],
            "release_version": envelope["release_version"],
        }
        print(json.dumps(result, ensure_ascii=False, sort_keys=True))
        return 0
    if args.release_token is None or args.public_key is None:
        parser.error("构建安装器必须同时提供 --release-token 和 --public-key")
    release_token = _read_release_file(
        args.release_token, 32 * 1024, "CMS 发行签名令牌"
    ).decode("ascii").strip()
    public_key = _read_release_file(args.public_key, 16 * 1024, "CMS 发行公钥")
    result = build_installer(args.output, args.install_token, release_token, public_key)
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
