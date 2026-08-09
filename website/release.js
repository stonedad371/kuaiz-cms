(() => {
  "use strict";

  const text = (name, value) => {
    document.querySelectorAll(`[data-release-${name}]`).forEach((node) => {
      node.textContent = value;
    });
  };
  const show = (selector) => {
    document.querySelectorAll(selector).forEach((node) => {
      node.hidden = false;
    });
  };
  const hide = (selector) => {
    document.querySelectorAll(selector).forEach((node) => {
      node.hidden = true;
    });
  };
  const localPath = (value, prefix) => (
    typeof value === "string" && value.startsWith(prefix) && !value.includes("?")
      && !value.includes("#") ? value : ""
  );
  const versionPattern = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(-dev)?$/;
  const statusLabels = {
    "developer-preview": "开发测试版",
    "release-candidate": "正式版候选",
    supported: "稳定支持版",
  };
  const assuranceLabels = {
    "offline-production": "正式发布级",
    "online-local-keychain-developer-preview": "开发测试级",
  };
  const displayDate = (seconds) => (
    Number.isInteger(seconds)
      ? new Intl.DateTimeFormat("zh-CN", { dateStyle: "long", timeZone: "Asia/Shanghai" })
        .format(new Date(seconds * 1000))
      : "已发布"
  );
  const displayBytes = (bytes) => (
    Number.isInteger(bytes) && bytes > 0
      ? `${new Intl.NumberFormat("zh-CN").format(Math.ceil(bytes / 1024))} KB`
      : "—"
  );
  const loadHistory = (currentVersion) => {
    fetch("/releases/index.json", { cache: "no-store", credentials: "omit" })
      .then((response) => {
        if (!response.ok) throw new Error("no release index");
        return response.json();
      })
      .then((index) => {
        const entries = Array.isArray(index.releases) ? index.releases : [];
        if (index.schema !== "kuaiz-cms-public-release-index/v1"
            || index.current !== currentVersion || entries.length < 1 || entries.length > 100) {
          throw new Error("invalid release index");
        }
        const list = document.querySelector("[data-release-history-list]");
        if (!list) return;
        const seen = new Set();
        const fragment = document.createDocumentFragment();
        entries.forEach((entry, position) => {
          const version = entry && typeof entry.version === "string" ? entry.version : "";
          const metadata = localPath(entry && entry.metadata_file, `/releases/${version}/`);
          if (!versionPattern.test(version) || seen.has(version)
              || metadata !== `/releases/${version}/release.json`
              || !Number.isInteger(entry.published_at)
              || !statusLabels[entry.support_status]
              || !assuranceLabels[entry.signing_assurance]
              || (entry.support_status !== "developer-preview"
                  && entry.signing_assurance !== "offline-production")
              || (position === 0 && version !== currentVersion)) {
            throw new Error("invalid release entry");
          }
          seen.add(version);
          const article = document.createElement("article");
          article.className = "release-history-item";
          const copy = document.createElement("div");
          const title = document.createElement("b");
          title.textContent = version;
          const detail = document.createElement("span");
          detail.textContent = `${statusLabels[entry.support_status]} · ${displayDate(entry.published_at)}`;
          const link = document.createElement("a");
          link.href = metadata;
          link.textContent = "查看技术资料 →";
          copy.append(title, detail);
          article.append(copy, link);
          fragment.append(article);
        });
        list.replaceChildren(fragment);
        show("[data-release-history]");
      })
      .catch(() => {});
  };

  fetch("/releases/current.json", { cache: "no-store", credentials: "omit" })
    .then((response) => {
      if (!response.ok) throw new Error("no signed release");
      return response.json();
    })
    .then((release) => {
      const version = typeof release.version === "string" ? release.version : "";
      const fingerprint = typeof release.public_key_fingerprint === "string"
        ? release.public_key_fingerprint : "";
      const source = release.source && typeof release.source === "object" ? release.source : {};
      const installer = release.installer && typeof release.installer === "object"
        ? release.installer : {};
      const assurance = typeof release.signing_assurance === "string"
        ? release.signing_assurance : "";
      const file = localPath(source.file, `/releases/${version}/`);
      const installerFile = localPath(installer.file, `/releases/${version}/`);
      const installerReady = installer.availability === "public-single-file";
      const metadata = localPath(`/releases/${version}/release.json`, `/releases/${version}/`);
      if (!versionPattern.test(version)
          || !/^[0-9a-f]{64}$/.test(fingerprint)
          || !/^[0-9a-f]{64}$/.test(String(source.sha256 || ""))
          || file !== `/releases/${version}/kuaiz-cms-community-${version}.zip`
          || !installer || !["personalized-download-service", "public-single-file"]
            .includes(installer.availability)
          || (installerReady && (
            installerFile !== `/releases/${version}/install.php`
            || !/^[0-9a-f]{64}$/.test(String(installer.sha256 || ""))
            || !Number.isInteger(installer.byte_size)
            || installer.byte_size < 1
          ))
          || !metadata || !statusLabels[release.support_status]
          || !assuranceLabels[assurance]
          || (release.support_status !== "developer-preview"
              && assurance !== "offline-production")) {
        throw new Error("invalid signed release metadata");
      }
      const status = statusLabels[release.support_status] || "已验证版本";
      const date = displayDate(release.published_at);
      text("version", version);
      text("status", status);
      text("fingerprint", fingerprint);
      text("sha256", source.sha256);
      if (installerReady) {
        text("installer-sha256", installer.sha256);
        text("installer-size", displayBytes(installer.byte_size));
      }
      text("date", date);
      text("assurance", assuranceLabels[assurance]);
      document.querySelectorAll("[data-release-source-link]").forEach((node) => {
        node.setAttribute("href", file);
        node.removeAttribute("aria-disabled");
      });
      document.querySelectorAll("[data-release-metadata-link]").forEach((node) => {
        node.setAttribute("href", metadata);
      });
      if (installerReady) {
        document.querySelectorAll("[data-release-installer-link]").forEach((node) => {
          node.setAttribute("href", installerFile);
          node.setAttribute("download", "install.php");
          node.removeAttribute("aria-disabled");
        });
        show("[data-release-installer-ready]");
      }
      show("[data-release-ready]");
      hide("[data-release-pending]");
      if (release.support_status === "supported") {
        show("[data-release-supported]");
      }
      document.documentElement.dataset.releaseState = release.support_status;
      loadHistory(version);
    })
    .catch(() => {
      document.documentElement.dataset.releaseState = "pending";
    });
})();
