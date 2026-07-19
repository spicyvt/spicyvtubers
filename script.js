/* ==========================================================================
   Spicy VTubers — script
   ========================================================================== */

(() => {
  "use strict";

  // ---------------------------------------------------------------------
  // Data source: accounts.json (edit that file to add/update creators)
  // ---------------------------------------------------------------------

  const DATA_URL = "accounts.json";

  // ---------------------------------------------------------------------
  // Rendering
  // ---------------------------------------------------------------------

  const tbody = document.getElementById("creator-tbody");
  const searchInput = document.getElementById("search-input");
  const resultCount = document.getElementById("result-count");
  const emptyState = document.getElementById("empty-state");
  const platformFilterEl = document.getElementById("platform-filter");

  const channelModalOverlay = document.getElementById("channel-modal-overlay");
  const channelModalAvatarWrap = document.getElementById("channel-modal-avatar-wrap");
  const channelModalTitle = document.getElementById("channel-modal-title");
  const channelModalName = document.getElementById("channel-modal-name");
  const channelModalPlatform = document.getElementById("channel-modal-platform");
  const channelModalConfirm = document.getElementById("channel-modal-confirm");
  const channelModalCancel = document.getElementById("channel-modal-cancel");
  const channelModalClose = document.getElementById("channel-modal-close");

  let creators = [];
  let platformFilter = "all";

  const CHANNEL_PLATFORMS = {
    twitch: { baseUrl: "https://twitch.com/", label: "Twitch" },
    youtube: { baseUrl: "https://youtube.com/@", label: "YouTube" },
  };

  const fanslyIcon = `<img src="fansly.svg" alt="" aria-hidden="true">`;
  const xIcon = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18.9 2H22l-7.2 8.3L23.3 22h-6.6l-5.2-6.8L5.3 22H2.2l7.7-8.9L1 2h6.8l4.7 6.2L18.9 2zm-1.2 18h1.8L7.3 4h-2l12.4 16z"/></svg>`;
  // Bluesky's mark in its real brand colors (blue disc, white butterfly)
  // rather than the pill's monochrome currentColor, since the brand mark
  // reads better in color.
  const bskyIcon = `<svg viewBox="0 0 512 512" aria-hidden="true"><path fill="#0085FF" d="M256 0c141.385 0 256 114.615 256 256S397.385 512 256 512 0 397.385 0 256 114.615 0 256 0z"/><path fill="#fff" fill-rule="nonzero" d="M183.776 158.537c29.233 22.022 60.681 66.666 72.223 90.625 11.543-23.959 42.992-68.603 72.225-90.625 21.097-15.886 55.275-28.181 55.275 10.937 0 7.81-4.463 65.631-7.084 75.02-9.1 32.629-42.27 40.953-71.774 35.916 51.573 8.806 64.691 37.97 36.357 67.137-53.808 55.394-77.34-13.898-83.364-31.653-1.738-5.111-1.49-5.228-3.268 0-6.026 17.755-29.555 87.047-83.364 31.653-28.334-29.167-15.216-58.331 36.357-67.137-29.504 5.037-62.674-3.287-71.774-35.916-2.621-9.389-7.084-67.21-7.084-75.02 0-39.118 34.182-26.823 55.275-10.937z"/></svg>`;
  const socialsIcon = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg>`;

  // Fansly, OnlyFans, Rplay, and Joystick all use their real brand marks as
  // external svg files.
  const onlyfansIcon = `<img src="onlyfans.svg" alt="" aria-hidden="true">`;
  const rplayIcon = `<img src="rplay.svg" alt="" aria-hidden="true">`;
  const joystickIcon = `<img src="joystick.svg" alt="" aria-hidden="true">`;

  // Fixed display order for the "Spice" column — a creator will rarely have
  // more than one or two of these set, but this keeps the order consistent
  // across rows regardless of key order in accounts.json.
  const SPICE_PLATFORMS = [
    { key: "fansly", className: "fansly", label: "Fansly", icon: fanslyIcon, baseUrl: "https://fansly.com/", refKey: "fanslyRef" },
    { key: "onlyfans", className: "onlyfans", label: "OnlyFans", icon: onlyfansIcon, baseUrl: "https://onlyfans.com/" },
    { key: "rplay", className: "rplay", label: "Rplay", icon: rplayIcon, baseUrl: "https://rplay.live/c/", rootBaseUrl: "https://rplay.live/" },
    { key: "joystick", className: "joystick", label: "joystick.tv", icon: joystickIcon, baseUrl: "https://joystick.tv/u/" },
  ];

  // ---------------------------------------------------------------------
  // Platform filter — single-select pills, "All" is the default.
  // ---------------------------------------------------------------------

  function renderPlatformFilter() {
    const allBtn = `<button type="button" class="platform-filter-btn is-active" data-platform="all" aria-pressed="true" aria-label="All"><span>All</span></button>`;
    const platformBtns = SPICE_PLATFORMS
      .map(
        (platform) =>
          `<button type="button" class="platform-filter-btn" data-platform="${platform.key}" aria-pressed="false" aria-label="${escapeHtml(platform.label)}">${platform.icon}<span>${platform.label}</span></button>`
      )
      .join("");
    platformFilterEl.innerHTML = allBtn + platformBtns;
  }

  platformFilterEl.addEventListener("click", (e) => {
    const btn = e.target.closest(".platform-filter-btn");
    if (!btn || btn.classList.contains("is-active")) return;

    platformFilter = btn.dataset.platform;
    platformFilterEl.querySelectorAll(".platform-filter-btn").forEach((b) => {
      const isActive = b === btn;
      b.classList.toggle("is-active", isActive);
      b.setAttribute("aria-pressed", String(isActive));
    });
    update();
  });

  function escapeHtml(str) {
    const div = document.createElement("div");
    div.textContent = str;
    return div.innerHTML;
  }

  function profileLink(baseUrl, handle, ...pathSegments) {
    const segments = [handle, ...pathSegments].filter(Boolean).map(encodeURIComponent);
    return `${baseUrl}${segments.join("/")}`;
  }

  function getInitials(str) {
    const parts = str.split(/[\s_-]+/).filter(Boolean);
    if (parts.length >= 2) {
      return (parts[0][0] + parts[1][0]).toUpperCase();
    }
    return str.charAt(0).toUpperCase();
  }

  // "other" is a free-form array of single-key objects, e.g.
  // [{ "patreon": "https://patreon.com/..." }] — the key is used as the
  // button label and the value as the link's full URL.
  function getOtherLinks(creator) {
    if (!Array.isArray(creator.other)) return [];
    return creator.other.flatMap((entry) =>
      Object.entries(entry || {})
        .filter(([, url]) => url)
        .map(([label, url]) => ({ label, url }))
    );
  }

  // Rows are built once per creator and cached (keyed by the creator object
  // itself), rather than rebuilt from scratch on every render. Rebuilding
  // used to tear down and recreate each row's avatar <img> on every
  // keystroke, which flashed the fallback initials before the image
  // reloaded even for rows that stayed in the filtered results.
  const rowCache = new WeakMap();

  function buildRow(creator) {
    const tr = document.createElement("tr");

    const channelTd = document.createElement("td");
    channelTd.dataset.label = "Channel";
    channelTd.className = "name-cell";
    const platform = CHANNEL_PLATFORMS[creator.type] || CHANNEL_PLATFORMS.twitch;
    const platformKey = creator.type && CHANNEL_PLATFORMS[creator.type] ? creator.type : "twitch";
    channelTd.innerHTML = creator.channel
      ? `<a class="name-link" href="${profileLink(platform.baseUrl, creator.channel)}" target="_blank" rel="noopener noreferrer" data-channel="${escapeHtml(creator.channel)}" data-platform-key="${platformKey}">` +
        `<span class="avatar">` +
        `<span class="avatar-fallback" aria-hidden="true">${escapeHtml(getInitials(creator.channel))}</span>` +
        `<img class="avatar-img" src="avatars/${encodeURIComponent(creator.channel.toLowerCase())}.webp" alt="" loading="lazy" decoding="async" onload="this.classList.add('is-loaded')" onerror="this.remove()">` +
        `</span>` +
        `<span class="name-text">${escapeHtml(creator.channel)}</span>` +
        `</a>`
      : "";

    const spiceTd = document.createElement("td");
    spiceTd.dataset.label = "Spice";
    const spicePills = SPICE_PLATFORMS.filter((platform) => creator[platform.key])
      .map((platform) => {
        const value = creator[platform.key];
        const ref = platform.refKey ? creator[platform.refKey] : undefined;
        const isPath = platform.rootBaseUrl && value.includes("/");
        const href = isPath
          ? profileLink(platform.rootBaseUrl, ...value.split("/"))
          : profileLink(platform.baseUrl, value, ref);
        const text = isPath ? creator.channel.toLowerCase() : value.toLowerCase();
        return `<a class="spice-pill ${platform.className}" href="${href}" target="_blank" rel="noopener noreferrer" title="${escapeHtml(platform.label)}: ${escapeHtml(text)}">${platform.icon}${escapeHtml(text)}</a>`;
      })
      .join("");
    const otherPills = getOtherLinks(creator)
      .map(
        ({ label, url }) =>
          `<a class="spice-pill other-pill" href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" title="${escapeHtml(url)}">${escapeHtml(label)}</a>`
      )
      .join("");
    spiceTd.innerHTML = `<div class="spice-handles">${spicePills}${otherPills}</div>`;

    const xTd = document.createElement("td");
    xTd.dataset.label = "Twitter/Bluesky";
    const xPills = creator.xHandles
      .map(
        (handle) =>
          `<a class="x-pill" href="${profileLink("https://x.com/", handle)}" target="_blank" rel="noopener noreferrer" title="Twitter: ${escapeHtml(handle)}">${xIcon}${escapeHtml(handle.toLowerCase())}</a>`
      )
      .join("");
    const [bskyName, bskyLink] = Array.isArray(creator.bskyHandle) ? creator.bskyHandle : [];
    const bskyPill = bskyLink
      ? `<a class="x-pill" href="${profileLink("https://bsky.app/profile/", bskyLink)}" target="_blank" rel="noopener noreferrer" title="Bluesky: ${escapeHtml((bskyName || bskyLink).toLowerCase())}">${bskyIcon}${escapeHtml((bskyName || bskyLink).toLowerCase())}</a>`
      : "";
    xTd.innerHTML = `<div class="x-handles">${xPills}${bskyPill}</div>`;

    const socialsTd = document.createElement("td");
    socialsTd.dataset.label = "Socials";
    const socialsPill = creator.socials
      ? `<a class="x-pill socials-pill" href="${escapeHtml(creator.socials)}" target="_blank" rel="noopener noreferrer" title="Socials">${socialsIcon}</a>`
      : "";
    socialsTd.innerHTML = `<div class="x-handles">${socialsPill}</div>`;

    tr.append(channelTd, spiceTd, xTd, socialsTd);
    return tr;
  }

  function getOrBuildRow(creator) {
    let tr = rowCache.get(creator);
    if (!tr) {
      tr = buildRow(creator);
      rowCache.set(creator, tr);
    }
    return tr;
  }

  function renderRows(data) {
    if (data.length === 0) {
      tbody.innerHTML = "";
      emptyState.hidden = false;
      return;
    }
    emptyState.hidden = true;

    const fragment = document.createDocumentFragment();
    data.forEach((creator) => fragment.appendChild(getOrBuildRow(creator)));

    // Appending each row above moves it (existing rows are moved, not
    // cloned) out of tbody into fragment, so anything still left in tbody
    // at this point is a row that dropped out of the filtered set — clear
    // those, then insert the new set in one go.
    tbody.innerHTML = "";
    tbody.appendChild(fragment);
  }

  function updateResultCount(count, total) {
    resultCount.textContent =
      count === total
        ? `${total} creator${total === 1 ? "" : "s"}`
        : `${count} of ${total} creators`;
  }

  function getFiltered() {
    const query = searchInput.value.trim().toLowerCase();
    return creators.filter((creator) => {
      if (platformFilter !== "all" && !creator[platformFilter]) return false;
      if (query && !creator.searchText.includes(query)) return false;
      return true;
    });
  }

  function update() {
    const filtered = getFiltered();
    renderRows(filtered);
    updateResultCount(filtered.length, creators.length);
  }

  // ---------------------------------------------------------------------
  // Search
  // ---------------------------------------------------------------------

  function debounce(fn, delay) {
    let timer;
    return (...args) => {
      clearTimeout(timer);
      timer = setTimeout(() => fn(...args), delay);
    };
  }

  searchInput.addEventListener("input", debounce(update, 150));

  // ---------------------------------------------------------------------
  // Channel link confirmation modal — clicking a channel avatar/name opens
  // a "you're about to visit X on Twitch/YouTube" confirmation instead of
  // navigating immediately. Modifier-clicks (middle-click, ctrl/cmd/shift)
  // are left alone so opening in a new tab/window still works as expected.
  // ---------------------------------------------------------------------

  let lastFocusedElement = null;

  function openChannelModal(link) {
    const platformKey = link.dataset.platformKey || "twitch";
    const platform = CHANNEL_PLATFORMS[platformKey] || CHANNEL_PLATFORMS.twitch;
    const channelName = link.dataset.channel || "";
    const avatar = link.querySelector(".avatar");

    channelModalAvatarWrap.innerHTML = "";
    if (avatar) channelModalAvatarWrap.appendChild(avatar.cloneNode(true));

    channelModalTitle.textContent = `Open ${platform.label} channel?`;
    channelModalName.textContent = channelName;
    channelModalPlatform.textContent = platform.label;
    channelModalConfirm.href = link.href;
    channelModalConfirm.textContent = `Open ${platform.label}`;

    lastFocusedElement = document.activeElement;
    channelModalOverlay.hidden = false;
    requestAnimationFrame(() => channelModalOverlay.classList.add("is-open"));
    channelModalConfirm.focus();
  }

  function closeChannelModal() {
    if (channelModalOverlay.hidden) return;
    channelModalOverlay.classList.remove("is-open");
    channelModalOverlay.addEventListener(
      "transitionend",
      () => {
        channelModalOverlay.hidden = true;
      },
      { once: true }
    );
    if (lastFocusedElement) lastFocusedElement.focus();
  }

  tbody.addEventListener("click", (e) => {
    const link = e.target.closest(".name-link");
    if (!link) return;
    if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    openChannelModal(link);
  });

  channelModalConfirm.addEventListener("click", closeChannelModal);
  channelModalCancel.addEventListener("click", closeChannelModal);
  channelModalClose.addEventListener("click", closeChannelModal);
  channelModalOverlay.addEventListener("click", (e) => {
    if (e.target === channelModalOverlay) closeChannelModal();
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && !channelModalOverlay.hidden) closeChannelModal();
  });

  // ---------------------------------------------------------------------
  // Init — load creators from accounts.json
  // ---------------------------------------------------------------------

  async function init() {
    try {
      const response = await fetch(DATA_URL);
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const data = await response.json();
      creators = data
        .filter((c) => c && typeof c.channel === "string" && c.channel.trim() !== "")
        .map((c) => ({ ...c, xHandles: (c.xHandles || []).filter(Boolean) }))
        .sort((a, b) => a.channel.toLowerCase().localeCompare(b.channel.toLowerCase()));

      // Precompute each creator's lowercased search haystack once, instead of
      // rebuilding it from scratch on every keystroke.
      creators.forEach((creator) => {
        creator.searchText = [
          creator.channel,
          ...SPICE_PLATFORMS.map((platform) => creator[platform.key]),
          ...creator.xHandles,
          ...(Array.isArray(creator.bskyHandle) ? creator.bskyHandle : []),
          ...getOtherLinks(creator).map((link) => link.label),
        ]
          .filter(Boolean)
          .join(" ")
          .toLowerCase();
      });
    } catch (err) {
      console.error("Failed to load accounts.json:", err);
      resultCount.textContent = "Couldn't load creator data.";
      emptyState.hidden = false;
      emptyState.textContent =
        "Couldn't load accounts.json (if opening this file directly, serve it via a local web server).";
      return;
    }
    renderPlatformFilter();
    update();
  }

  init();
})();
