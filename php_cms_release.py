"""Independent Ed25519 release envelopes for the Kuaiz CMS installer."""
from __future__ import annotations

import base64
import binascii
import hashlib
import json
import re

from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import (
    Ed25519PrivateKey,
    Ed25519PublicKey,
)


RELEASE_SCHEMA = "kuaiz-cms-release-signature/v1"
TOKEN_PREFIX = "kzc1"
HEX64_RE = re.compile(r"^[0-9a-f]{64}$")
VERSION_RE = re.compile(r"^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-dev)?$")
RUNTIME_PROFILE = "community-php-sqlite-v1"


class CmsReleaseError(ValueError):
    """CMS release metadata, keys or signatures are invalid."""


def canonical(value: dict) -> bytes:
    return json.dumps(
        value, ensure_ascii=False, sort_keys=True, separators=(",", ":")
    ).encode("utf-8")


def _b64e(value: bytes) -> str:
    return base64.urlsafe_b64encode(value).rstrip(b"=").decode("ascii")


def _b64d(value: str) -> bytes:
    if not re.fullmatch(r"[A-Za-z0-9_-]+", str(value or "")):
        raise CmsReleaseError("CMS 发行签名编码不正确")
    try:
        return base64.urlsafe_b64decode(value + "=" * (-len(value) % 4))
    except (ValueError, binascii.Error) as exc:
        raise CmsReleaseError("CMS 发行签名编码不正确") from exc


def generate_key_pair() -> tuple[bytes, bytes]:
    private = Ed25519PrivateKey.generate()
    return (
        private.private_bytes(
            serialization.Encoding.PEM,
            serialization.PrivateFormat.PKCS8,
            serialization.NoEncryption(),
        ),
        private.public_key().public_bytes(
            serialization.Encoding.PEM,
            serialization.PublicFormat.SubjectPublicKeyInfo,
        ),
    )


def _private_key(value: bytes | str) -> Ed25519PrivateKey:
    raw = value.encode() if isinstance(value, str) else bytes(value)
    try:
        key = serialization.load_pem_private_key(raw, password=None)
    except (TypeError, ValueError) as exc:
        raise CmsReleaseError("CMS 发行私钥不正确") from exc
    if not isinstance(key, Ed25519PrivateKey):
        raise CmsReleaseError("CMS 发行签名必须使用独立 Ed25519 私钥")
    return key


def _public_key(value: bytes | str) -> Ed25519PublicKey:
    raw = value.encode() if isinstance(value, str) else bytes(value)
    try:
        key = serialization.load_pem_public_key(raw)
    except (TypeError, ValueError) as exc:
        raise CmsReleaseError("CMS 发行公钥不正确") from exc
    if not isinstance(key, Ed25519PublicKey):
        raise CmsReleaseError("CMS 发行签名必须使用独立 Ed25519 公钥")
    return key


def public_key_fingerprint(value: bytes | str) -> str:
    key = _public_key(value)
    der = key.public_bytes(
        serialization.Encoding.DER,
        serialization.PublicFormat.SubjectPublicKeyInfo,
    )
    return hashlib.sha256(der).hexdigest()


def create_envelope(
    *,
    version: str,
    database_schema_version: int,
    template_sha256: str,
    payload_sha256: str,
    file_count: int,
    content_bytes: int,
    issued_at: int,
) -> dict:
    return validate_envelope({
        "schema": RELEASE_SCHEMA,
        "release_version": version,
        "runtime_profile": RUNTIME_PROFILE,
        "database_schema_version": database_schema_version,
        "template_sha256": template_sha256,
        "payload_sha256": payload_sha256,
        "file_count": file_count,
        "content_bytes": content_bytes,
        "issued_at": issued_at,
    })


def validate_envelope(value: dict) -> dict:
    if not isinstance(value, dict) or set(value) != {
        "schema", "release_version", "runtime_profile",
        "database_schema_version", "template_sha256", "payload_sha256",
        "file_count", "content_bytes", "issued_at",
    }:
        raise CmsReleaseError("CMS 发行摘要字段不正确")
    if (
        value.get("schema") != RELEASE_SCHEMA
        or not VERSION_RE.fullmatch(str(value.get("release_version") or ""))
        or value.get("runtime_profile") != RUNTIME_PROFILE
        or type(value.get("database_schema_version")) is not int
        or not 1 <= value["database_schema_version"] <= 1_000_000
        or not HEX64_RE.fullmatch(str(value.get("template_sha256") or ""))
        or not HEX64_RE.fullmatch(str(value.get("payload_sha256") or ""))
        or type(value.get("file_count")) is not int
        or not 1 <= value["file_count"] <= 50_000
        or type(value.get("content_bytes")) is not int
        or not 1 <= value["content_bytes"] <= 32 * 1024 * 1024
        or type(value.get("issued_at")) is not int
        or value["issued_at"] <= 0
    ):
        raise CmsReleaseError("CMS 发行摘要值不正确")
    return value


def sign_envelope(envelope: dict, private_key_pem: bytes | str) -> str:
    payload = canonical(validate_envelope(envelope))
    signature = _private_key(private_key_pem).sign(payload)
    return f"{TOKEN_PREFIX}.{_b64e(payload)}.{_b64e(signature)}"


def verify_token(token: str, public_key_pem: bytes | str) -> dict:
    parts = str(token or "").strip().split(".")
    if len(parts) != 3 or parts[0] != TOKEN_PREFIX:
        raise CmsReleaseError("CMS 发行签名格式不正确")
    payload = _b64d(parts[1])
    signature = _b64d(parts[2])
    if len(signature) != 64:
        raise CmsReleaseError("CMS 发行签名格式不正确")
    try:
        value = json.loads(payload)
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise CmsReleaseError("CMS 发行签名载荷无法读取") from exc
    clean = validate_envelope(value)
    if payload != canonical(clean):
        raise CmsReleaseError("CMS 发行签名载荷不是规范编码")
    try:
        _public_key(public_key_pem).verify(signature, payload)
    except InvalidSignature as exc:
        raise CmsReleaseError("CMS 发行签名验证失败") from exc
    return clean
