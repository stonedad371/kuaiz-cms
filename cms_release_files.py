"""发行签名工具使用的安全文件读写。"""
from __future__ import annotations

import os
import stat
from pathlib import Path


class CmsReleaseFileError(ValueError):
    """发行输入或文件权限不安全。"""


def write_new_file(path_value: str, content: bytes, *, mode: int = 0o600) -> Path:
    """以独占方式新建文件，避免覆盖既有私钥或签名产物。"""
    path = Path(path_value).expanduser().resolve()
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, mode)
    try:
        with os.fdopen(descriptor, "wb") as handle:
            handle.write(bytes(content))
            handle.flush()
            os.fsync(handle.fileno())
    except Exception:
        path.unlink(missing_ok=True)
        raise
    return path


def read_control_file(path_value: str, *, limit: int, private: bool = False) -> bytes:
    """只读普通非符号链接文件；私钥必须禁止其他用户读取。"""
    source = Path(path_value).expanduser()
    if source.is_symlink():
        raise CmsReleaseFileError(f"文件类型或大小不安全：{source}")
    path = source.resolve()
    try:
        info = path.lstat()
    except OSError as exc:
        raise CmsReleaseFileError(f"文件不存在：{path}") from exc
    if not stat.S_ISREG(info.st_mode) or info.st_size > int(limit):
        raise CmsReleaseFileError(f"文件类型或大小不安全：{path}")
    if private and info.st_mode & 0o077:
        raise CmsReleaseFileError("发行私钥权限必须是 0600，不能允许其他用户读取")
    try:
        return path.read_bytes()
    except OSError as exc:
        raise CmsReleaseFileError(f"文件无法读取：{path}") from exc
