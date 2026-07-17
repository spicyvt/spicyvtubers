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

  let creators = [];
  let platformFilter = "all";

  const CHANNEL_PLATFORMS = {
    twitch: { baseUrl: "https://twitch.com/" },
    youtube: { baseUrl: "https://youtube.com/@" },
  };

  const fanslyIcon = `<img src="fansly.svg" alt="" aria-hidden="true">`;
  const xIcon = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18.9 2H22l-7.2 8.3L23.3 22h-6.6l-5.2-6.8L5.3 22H2.2l7.7-8.9L1 2h6.8l4.7 6.2L18.9 2zm-1.2 18h1.8L7.3 4h-2l12.4 16z"/></svg>`;
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
    { key: "rplay", className: "rplay", label: "Rplay", icon: rplayIcon, baseUrl: "https://rplay.live/c/" },
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
    channelTd.innerHTML = creator.channel
      ? `<a class="name-link" href="${profileLink(platform.baseUrl, creator.channel)}" target="_blank" rel="noopener noreferrer">` +
        `<span class="avatar">` +
        `<span class="avatar-fallback" aria-hidden="true">${escapeHtml(getInitials(creator.channel))}</span>` +
        `<img class="avatar-img" src="images/${encodeURIComponent(creator.channel.toLowerCase())}.png" alt="" loading="lazy" decoding="async" onload="this.classList.add('is-loaded')" onerror="this.remove()">` +
        `</span>` +
        `<span class="name-text">${escapeHtml(creator.channel)}</span>` +
        `</a>`
      : "";

    const spiceTd = document.createElement("td");
    spiceTd.dataset.label = "Spice";
    spiceTd.innerHTML = `<div class="spice-handles">${SPICE_PLATFORMS.filter((platform) => creator[platform.key])
      .map((platform) => {
        const value = creator[platform.key];
        const ref = platform.refKey ? creator[platform.refKey] : undefined;
        return `<a class="spice-pill ${platform.className}" href="${profileLink(platform.baseUrl, value, ref)}" target="_blank" rel="noopener noreferrer">${platform.icon}${escapeHtml(value.toLowerCase())}</a>`;
      })
      .join("")}</div>`;

    const xTd = document.createElement("td");
    xTd.dataset.label = "X.com";
    const xPills = creator.xHandles
      .map(
        (handle) =>
          `<a class="x-pill" href="${profileLink("https://x.com/", handle)}" target="_blank" rel="noopener noreferrer">${xIcon}${escapeHtml(handle.toLowerCase())}</a>`
      )
      .join("");
    xTd.innerHTML = `<div class="x-handles">${xPills}</div>`;

    const socialsTd = document.createElement("td");
    socialsTd.dataset.label = "Socials";
    const socialsPill = creator.socials
      ? `<a class="x-pill socials-pill" href="${escapeHtml(creator.socials)}" target="_blank" rel="noopener noreferrer">${socialsIcon}socials</a>`
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
