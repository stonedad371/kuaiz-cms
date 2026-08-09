#!/usr/bin/env python3
"""Validate the static cms.kuaiz.net website before an atomic deployment."""
from __future__ import annotations

import argparse
import sys
import xml.etree.ElementTree as ET
from dataclasses import dataclass, field
from html.parser import HTMLParser
from pathlib import Path, PurePosixPath
from urllib.parse import unquote, urljoin, urlsplit


ORIGIN = "https://cms.kuaiz.net"
PAGES = {
    "index.html": "/",
    "docs/index.html": "/docs/",
    "download/index.html": "/download/",
    "releases/index.html": "/releases/",
    "security/index.html": "/security/",
    "404.html": "/404.html",
}
SITEMAP_PATHS = {path for path in PAGES.values() if path != "/404.html"}
DEPLOYED_ASSETS = {
    "/contracts/cms-manifest.json",
    "/contracts/extension-manifest-v1.json",
    "/contracts/theme-manifest-v2.json",
    "/license.txt",
}
DYNAMIC_RELEASE_PREFIXES = ("/trust/", "/releases/")


class CmsSiteValidationError(ValueError):
    """The CMS website contains an invalid page or broken local reference."""


@dataclass
class Page:
    relative: str
    url_path: str
    title_parts: list[str] = field(default_factory=list)
    descriptions: list[str] = field(default_factory=list)
    canonicals: list[str] = field(default_factory=list)
    ids: set[str] = field(default_factory=set)
    duplicate_ids: set[str] = field(default_factory=set)
    hrefs: list[str] = field(default_factory=list)
    assets: list[str] = field(default_factory=list)
    h1_count: int = 0
    image_errors: list[str] = field(default_factory=list)
    anchor_errors: list[str] = field(default_factory=list)


class PageParser(HTMLParser):
    def __init__(self, page: Page) -> None:
        super().__init__(convert_charrefs=True)
        self.page = page
        self.in_title = False

    def handle_starttag(self, tag: str, attrs_list: list[tuple[str, str | None]]) -> None:
        attrs = dict(attrs_list)
        element_id = attrs.get("id")
        if element_id:
            if element_id in self.page.ids:
                self.page.duplicate_ids.add(element_id)
            self.page.ids.add(element_id)
        if tag == "title":
            self.in_title = True
        elif tag == "h1":
            self.page.h1_count += 1
        elif tag == "meta" and attrs.get("name", "").lower() == "description":
            self.page.descriptions.append(attrs.get("content") or "")
        elif tag == "link" and "canonical" in (attrs.get("rel") or "").lower().split():
            self.page.canonicals.append(attrs.get("href") or "")

        if tag == "a":
            href = attrs.get("href")
            if href:
                self.page.hrefs.append(href)
            elif not any(name.startswith("data-release-") for name in attrs):
                self.page.anchor_errors.append("链接缺少 href")
        if tag == "img" and "alt" not in attrs:
            self.page.image_errors.append(attrs.get("src") or "<missing src>")
        if tag in {"img", "script", "source"} and attrs.get("src"):
            self.page.assets.append(attrs["src"] or "")
        if tag == "link" and attrs.get("href") and (
            {item.lower() for item in (attrs.get("rel") or "").split()}
            & {"stylesheet", "icon", "manifest", "preload"}
        ):
            self.page.assets.append(attrs["href"] or "")

    def handle_endtag(self, tag: str) -> None:
        if tag == "title":
            self.in_title = False

    def handle_data(self, data: str) -> None:
        if self.in_title:
            self.page.title_parts.append(data)


def _safe_local_path(value: str, page: Page) -> tuple[str, str] | None:
    if not value or value.startswith("//") or "\\" in value:
        raise CmsSiteValidationError(f"{page.relative}: 本地地址格式不安全：{value!r}")
    split = urlsplit(value)
    if split.scheme:
        if split.scheme not in {"https", "mailto"}:
            raise CmsSiteValidationError(
                f"{page.relative}: 不允许的链接协议：{split.scheme}"
            )
        return None
    joined = urlsplit(urljoin(f"{ORIGIN}{page.url_path}", value))
    if joined.netloc != "cms.kuaiz.net":
        return None
    path = unquote(joined.path)
    pure = PurePosixPath(path)
    if not path.startswith("/") or ".." in pure.parts:
        raise CmsSiteValidationError(f"{page.relative}: 本地路径不安全：{value}")
    return path, joined.fragment


def _disk_target(root: Path, path: str) -> Path:
    relative = path.removeprefix("/")
    if not relative or path.endswith("/"):
        relative = f"{relative}index.html"
    return root / relative


def _is_runtime_release_path(path: str) -> bool:
    return any(path.startswith(prefix) for prefix in DYNAMIC_RELEASE_PREFIXES)


def validate(root: Path) -> dict[str, object]:
    directory = Path(root).expanduser().resolve()
    if directory.is_symlink() or not directory.is_dir():
        raise CmsSiteValidationError("CMS 官网目录不存在或类型不安全")

    pages: dict[str, Page] = {}
    for relative, url_path in PAGES.items():
        source = directory / relative
        if source.is_symlink() or not source.is_file():
            raise CmsSiteValidationError(f"站点页面缺失：{relative}")
        page = Page(relative=relative, url_path=url_path)
        parser = PageParser(page)
        try:
            parser.feed(source.read_text(encoding="utf-8"))
            parser.close()
        except (UnicodeDecodeError, OSError) as exc:
            raise CmsSiteValidationError(f"页面无法读取：{relative}") from exc
        title = " ".join("".join(page.title_parts).split())
        if not title or "快智 CMS" not in title:
            raise CmsSiteValidationError(f"{relative}: title 缺失或不包含产品名")
        if page.h1_count != 1:
            raise CmsSiteValidationError(f"{relative}: 必须且只能有一个 h1")
        if page.duplicate_ids:
            raise CmsSiteValidationError(
                f"{relative}: ID 重复：{', '.join(sorted(page.duplicate_ids))}"
            )
        if page.image_errors:
            raise CmsSiteValidationError(f"{relative}: 图片缺少 alt")
        if page.anchor_errors:
            raise CmsSiteValidationError(f"{relative}: 存在没有目标的链接")
        if relative != "404.html":
            if len(page.descriptions) != 1 or not page.descriptions[0].strip():
                raise CmsSiteValidationError(f"{relative}: 必须有一个页面描述")
            expected_canonical = f"{ORIGIN}{url_path}"
            if page.canonicals != [expected_canonical]:
                raise CmsSiteValidationError(
                    f"{relative}: canonical 应为 {expected_canonical}"
                )
        pages[url_path] = page

    for page in pages.values():
        for value in page.assets + page.hrefs:
            local = _safe_local_path(value, page)
            if local is None:
                continue
            path, fragment = local
            if path in DEPLOYED_ASSETS or _is_runtime_release_path(path):
                continue
            target = _disk_target(directory, path)
            if target.is_symlink() or not target.is_file():
                raise CmsSiteValidationError(
                    f"{page.relative}: 本地链接不存在：{value}"
                )
            if fragment:
                target_path = "/" + target.relative_to(directory).as_posix()
                if target_path.endswith("/index.html"):
                    target_path = target_path.removesuffix("index.html")
                elif target_path == "/index.html":
                    target_path = "/"
                target_page = pages.get(target_path)
                if target_page is None or fragment not in target_page.ids:
                    raise CmsSiteValidationError(
                        f"{page.relative}: 页面锚点不存在：{value}"
                    )

    sitemap = directory / "sitemap.xml"
    try:
        tree = ET.parse(sitemap)
    except (ET.ParseError, OSError) as exc:
        raise CmsSiteValidationError("sitemap.xml 无法读取") from exc
    namespace = {"s": "http://www.sitemaps.org/schemas/sitemap/0.9"}
    sitemap_urls = {
        (node.text or "").strip()
        for node in tree.findall("s:url/s:loc", namespace)
    }
    expected_urls = {f"{ORIGIN}{path}" for path in SITEMAP_PATHS}
    if sitemap_urls != expected_urls:
        raise CmsSiteValidationError("sitemap.xml 页面集合与公开页面不一致")

    robots = (directory / "robots.txt").read_text(encoding="utf-8")
    if f"Sitemap: {ORIGIN}/sitemap.xml" not in robots:
        raise CmsSiteValidationError("robots.txt 没有公布正确站点地图")
    for required in ("styles.css", "subpage.css", "release.js", "favicon.svg"):
        path = directory / required
        if path.is_symlink() or not path.is_file() or not path.stat().st_size:
            raise CmsSiteValidationError(f"站点资源缺失：{required}")

    return {
        "ok": True,
        "pages": len(PAGES),
        "sitemap_urls": len(sitemap_urls),
        "root": str(directory),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="部署前验证 cms.kuaiz.net 静态站点")
    parser.add_argument("root", type=Path)
    args = parser.parse_args()
    try:
        result = validate(args.root)
    except (CmsSiteValidationError, OSError) as exc:
        print(f"错误：{exc}", file=sys.stderr)
        return 2
    print(
        f"CMS 官网校验通过：{result['pages']} 个页面，"
        f"{result['sitemap_urls']} 个站点地图地址"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
