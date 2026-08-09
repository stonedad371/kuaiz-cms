"""Signed envelopes for deterministic Kuaiz CMS Community source archives."""
from __future__ import annotations

import base64
import binascii
import hashlib
import json
import re

from cryptography.exceptions import InvalidSignature

import php_cms_release


SOURCE_RELEASE_SCHEMA = "kuaiz-cms-source-archive-signature/v1"
TOKEN_PREFIX = "kzs1"
HEX64_RE = re.compile(r"^[0-9a-f]{64}$")


class CmsSourceReleaseError(ValueError):
    """Source release metadata or signature is invalid."""


def canonical(value: dict) -> bytes:
    return json.dumps(
        value, ensure_ascii=False, sort_keys=True, separators=(",", ":")
    ).encode("utf-8")


def _b64e(value: bytes) -> str:
    return base64.urlsafe_b64encode(value).rstrip(b"=").decode("ascii")


def _b64d(value: str) -> bytes:
    if not re.fullmatch(r"[A-Za-z0-9_-]+", str(value or "")):
        raise CmsSourceReleaseError("CMS 源码包签名编码不正确")
    try:
        return base64.urlsafe_b64decode(value + "=" * (-len(value) % 4))
    except (ValueError, binascii.Error) as exc:
        raise CmsSourceReleaseError("CMS 源码包签名编码不正确") from exc


def create_envelope(
    *,
    version: str,
    archive_sha256: str,
    archive_bytes: int,
    file_count: int,
    issued_at: int,
) -> dict:
    return validate_envelope({
        "schema": SOURCE_RELEASE_SCHEMA,
        "release_version": version,
        "runtime_profile": php_cms_release.RUNTIME_PROFILE,
        "license": "Apache-2.0",
        "archive_sha256": archive_sha256,
        "archive_bytes": archive_bytes,
        "file_count": file_count,
        "issued_at": issued_at,
    })


def validate_envelope(value: dict) -> dict:
    if not isinstance(value, dict) or set(value) != {
        "schema", "release_version", "runtime_profile", "license",
        "archive_sha256", "archive_bytes", "file_count", "issued_at",
    }:
        raise CmsSourceReleaseError("CMS 源码包发行摘要字段不正确")
    if (
        value.get("schema") != SOURCE_RELEASE_SCHEMA
        or not php_cms_release.VERSION_RE.fullmatch(
            str(value.get("release_version") or ""))
        or value.get("runtime_profile") != php_cms_release.RUNTIME_PROFILE
        or value.get("license") != "Apache-2.0"
        or not HEX64_RE.fullmatch(str(value.get("archive_sha256") or ""))
        or type(value.get("archive_bytes")) is not int
        or not 1 <= value["archive_bytes"] <= 64 * 1024 * 1024
        or type(value.get("file_count")) is not int
        or not 1 <= value["file_count"] <= 50_000
        or type(value.get("issued_at")) is not int
        or value["issued_at"] <= 0
    ):
        raise CmsSourceReleaseError("CMS 源码包发行摘要值不正确")
    return value


def sign_envelope(envelope: dict, private_key_pem: bytes | str) -> str:
    payload = canonical(validate_envelope(envelope))
    signature = php_cms_release._private_key(private_key_pem).sign(payload)
    return f"{TOKEN_PREFIX}.{_b64e(payload)}.{_b64e(signature)}"


def verify_token(token: str, public_key_pem: bytes | str) -> dict:
    parts = str(token or "").strip().split(".")
    if len(parts) != 3 or parts[0] != TOKEN_PREFIX:
        raise CmsSourceReleaseError("CMS 源码包签名格式不正确")
    payload = _b64d(parts[1])
    signature = _b64d(parts[2])
    if len(signature) != 64:
        raise CmsSourceReleaseError("CMS 源码包签名格式不正确")
    try:
        value = json.loads(payload)
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise CmsSourceReleaseError("CMS 源码包签名载荷无法读取") from exc
    clean = validate_envelope(value)
    if payload != canonical(clean):
        raise CmsSourceReleaseError("CMS 源码包签名载荷不是规范编码")
    try:
        php_cms_release._public_key(public_key_pem).verify(signature, payload)
    except InvalidSignature as exc:
        raise CmsSourceReleaseError("CMS 源码包发行签名验证失败") from exc
    return clean


def verify_archive(envelope: dict, archive: bytes) -> None:
    clean = validate_envelope(envelope)
    if len(archive) != clean["archive_bytes"]:
        raise CmsSourceReleaseError("CMS 源码包字节数与签名摘要不一致")
    if not hashlib.sha256(archive).hexdigest() == clean["archive_sha256"]:
        raise CmsSourceReleaseError("CMS 源码包 SHA-256 与签名摘要不一致")
