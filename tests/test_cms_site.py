import importlib.util
import sys
from pathlib import Path

import pytest


ROOT = Path(__file__).resolve().parents[1]


def _validator():
    path = ROOT / "scripts" / "validate_cms_site.py"
    spec = importlib.util.spec_from_file_location("validate_cms_site_for_test", path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def test_cms_site_has_valid_pages_links_and_sitemap():
    result = _validator().validate(ROOT / "website")
    assert result["pages"] == 6
    assert result["sitemap_urls"] == 5


def test_cms_site_validator_rejects_broken_internal_link(tmp_path):
    validator = _validator()
    site = tmp_path / "site"
    site.mkdir()
    for source in (ROOT / "website").rglob("*"):
        if source.is_file():
            target = site / source.relative_to(ROOT / "website")
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(source.read_bytes())
    home = site / "index.html"
    home.write_text(
        home.read_text("utf-8").replace('href="#why"', 'href="/missing/"', 1),
        encoding="utf-8",
    )
    with pytest.raises(validator.CmsSiteValidationError, match="本地链接不存在"):
        validator.validate(site)
