#!/usr/bin/env python3
"""Offline/isolated control for the independent CMS release signing key."""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import php_cms_release  # noqa: E402
import php_cms_source_release  # noqa: E402
from cms_release_files import (  # noqa: E402
    CmsReleaseFileError,
    read_control_file,
    write_new_file,
)


MAX_ENVELOPE_BYTES = 32 * 1024


class ControlError(ValueError):
    """CMS release signer inputs or key files are unsafe."""


def _private(path: str) -> bytes:
    try:
        raw = read_control_file(path, limit=16 * 1024, private=True)
        php_cms_release._private_key(raw)  # pylint: disable=protected-access
        return raw
    except (CmsReleaseFileError, php_cms_release.CmsReleaseError) as exc:
        raise ControlError("CMS 发行私钥必须是权限 0600 的普通 Ed25519 PEM 文件") from exc


def _public(path: str) -> bytes:
    try:
        raw = read_control_file(path, limit=16 * 1024, private=False)
        php_cms_release._public_key(raw)  # pylint: disable=protected-access
        return raw
    except (CmsReleaseFileError, php_cms_release.CmsReleaseError) as exc:
        raise ControlError("CMS 发行公钥必须是普通 Ed25519 PEM 文件") from exc


def _envelope(path: str) -> dict:
    raw = read_control_file(path, limit=MAX_ENVELOPE_BYTES, private=False)
    try:
        value = json.loads(raw)
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise ControlError("CMS 发行摘要不是有效 JSON") from exc
    try:
        clean = php_cms_release.validate_envelope(value)
    except php_cms_release.CmsReleaseError as exc:
        raise ControlError(str(exc)) from exc
    if raw.strip() != php_cms_release.canonical(clean):
        raise ControlError("CMS 发行摘要必须使用规范 JSON")
    return clean


def _source_envelope(path: str) -> dict:
    raw = read_control_file(path, limit=MAX_ENVELOPE_BYTES, private=False)
    try:
        value = json.loads(raw)
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise ControlError("CMS 源码包发行摘要不是有效 JSON") from exc
    try:
        clean = php_cms_source_release.validate_envelope(value)
    except php_cms_source_release.CmsSourceReleaseError as exc:
        raise ControlError(str(exc)) from exc
    if raw.strip() != php_cms_source_release.canonical(clean):
        raise ControlError("CMS 源码包发行摘要必须使用规范 JSON")
    return clean


def command_keygen(args) -> dict:
    private, public = php_cms_release.generate_key_pair()
    private_path = write_new_file(args.private, private, mode=0o600)
    try:
        public_path = write_new_file(args.public, public, mode=0o644)
    except Exception:
        private_path.unlink(missing_ok=True)
        raise
    return {
        "ok": True,
        "action": "keygen",
        "private_key": str(private_path),
        "public_key": str(public_path),
        "public_key_fingerprint": php_cms_release.public_key_fingerprint(public),
    }


def command_sign(args) -> dict:
    envelope = _envelope(args.input)
    token = php_cms_release.sign_envelope(envelope, _private(args.private_key))
    output = write_new_file(args.output, (token + "\n").encode(), mode=0o600)
    return {
        "ok": True,
        "action": "sign",
        "output": str(output),
        "payload_sha256": envelope["payload_sha256"],
        "release_version": envelope["release_version"],
    }


def command_verify(args) -> dict:
    envelope = _envelope(args.input)
    token = read_control_file(args.token, limit=32 * 1024, private=False).decode().strip()
    verified = php_cms_release.verify_token(token, _public(args.public_key))
    if verified != envelope:
        raise ControlError("CMS 发行签名不属于该摘要")
    return {
        "ok": True,
        "action": "verify",
        "payload_sha256": envelope["payload_sha256"],
        "release_version": envelope["release_version"],
    }


def command_sign_source(args) -> dict:
    envelope = _source_envelope(args.input)
    token = php_cms_source_release.sign_envelope(
        envelope, _private(args.private_key))
    output = write_new_file(args.output, (token + "\n").encode(), mode=0o600)
    return {
        "ok": True,
        "action": "sign-source",
        "output": str(output),
        "archive_sha256": envelope["archive_sha256"],
        "release_version": envelope["release_version"],
    }


def command_verify_source(args) -> dict:
    envelope = _source_envelope(args.input)
    token = read_control_file(
        args.token, limit=32 * 1024, private=False).decode().strip()
    verified = php_cms_source_release.verify_token(token, _public(args.public_key))
    if verified != envelope:
        raise ControlError("CMS 源码包发行签名不属于该摘要")
    return {
        "ok": True,
        "action": "verify-source",
        "archive_sha256": envelope["archive_sha256"],
        "release_version": envelope["release_version"],
    }


def parser() -> argparse.ArgumentParser:
    root = argparse.ArgumentParser(description="快智独立 CMS 发行签名控制面")
    commands = root.add_subparsers(dest="command", required=True)
    keygen = commands.add_parser("keygen")
    keygen.add_argument("--private", required=True)
    keygen.add_argument("--public", required=True)
    keygen.set_defaults(handler=command_keygen)
    sign = commands.add_parser("sign")
    sign.add_argument("--private-key", required=True)
    sign.add_argument("--input", required=True)
    sign.add_argument("--output", required=True)
    sign.set_defaults(handler=command_sign)
    verify = commands.add_parser("verify")
    verify.add_argument("--public-key", required=True)
    verify.add_argument("--input", required=True)
    verify.add_argument("--token", required=True)
    verify.set_defaults(handler=command_verify)
    sign_source = commands.add_parser("sign-source")
    sign_source.add_argument("--private-key", required=True)
    sign_source.add_argument("--input", required=True)
    sign_source.add_argument("--output", required=True)
    sign_source.set_defaults(handler=command_sign_source)
    verify_source = commands.add_parser("verify-source")
    verify_source.add_argument("--public-key", required=True)
    verify_source.add_argument("--input", required=True)
    verify_source.add_argument("--token", required=True)
    verify_source.set_defaults(handler=command_verify_source)
    return root


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    try:
        result = args.handler(args)
    except (
        ControlError,
        CmsReleaseFileError,
        php_cms_release.CmsReleaseError,
        php_cms_source_release.CmsSourceReleaseError,
        OSError,
        UnicodeDecodeError,
    ) as exc:
        print(f"错误：{exc}", file=sys.stderr)
        return 2
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
