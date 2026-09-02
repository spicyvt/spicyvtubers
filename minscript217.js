(() => {
  "use strict";

  const BASE_URL = "/";

  const EXTENSION_URLS = {
    chrome: "https://chromewebstore.google.com/detail/spicy-vtubers/oohhdkpmeaeejcaojpccilpfebdbeeib", 
    firefox: "https://addons.mozilla.org/en-US/firefox/addon/spicyvtubers/",
  };

  const siteStats = document.getElementById("site-stats");

  const CHANNEL_PLATFORMS = {
    twitch: { baseUrl: "https://twitch.com/", label: "Twitch" },
    youtube: { baseUrl: "https://youtube.com/@", label: "YouTube" },
  };

  const externalLinkIcon = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3h7v7h-2V6.41l-9.29 9.3-1.42-1.42 9.3-9.29H14V3zM5 5h6v2H7v10h10v-4h2v6H5V5z"/></svg>`;
  const resultCountIcon = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm0 2c-3.31 0-8 1.66-8 4.5V21h16v-2.5c0-2.84-4.69-4.5-8-4.5zm8.34-9.94a4 4 0 0 1 0 7.88 5.5 5.5 0 0 1 0-7.88zM18.5 14c2.9.53 4.5 1.9 4.5 3.5V21h-3v-2.5c0-1.63-.6-2.9-1.5-4.5z"/></svg>`;

  const SPICE_PLATFORMS = [
    { key: "fansly", label: "Fansly", baseUrl: "https://fansly.com/", refKey: "fanslyRef" },
    { key: "onlyfans", label: "OnlyFans", baseUrl: "https://onlyfans.com/" },
    { key: "rplay", label: "Rplay", baseUrl: "https://rplay.live/c/", rootBaseUrl: "https://rplay.live/" },
    { key: "joystick", label: "joystick.tv", baseUrl: "https://joystick.tv/u/" },
    { key: "patreon", label: "Patreon", baseUrl: "https://www.patreon.com/" },
  ];

  const AVATAR_FOLDERS = new Set(["avatarsLarge", "avatarsX", "avatarsB"]);

  
  
  
  
  const LIVE_STATUS_PLATFORMS = [
    { key: "fansly", jsonUrl: "https://cdn.spicyvtubers.com/fansly/live.json" },
    { key: "rplay", jsonUrl: "https://cdn.spicyvtubers.com/rplay/live.json" },
  ];
  const LIVE_STALE_MS = 24 * 60 * 60 * 1000;

  
  
  
  async function loadLiveLogins(jsonUrl) {
    try {
      
      const res = await fetch(`${jsonUrl}?t=${Date.now()}`);
      if (!res.ok) return new Set();
      const data = await res.json();
      if (!Array.isArray(data)) return new Set();
      const now = Date.now();
      return new Set(
        data
          .filter((entry) => entry && typeof entry.login === "string" && now - entry.startedAt <= LIVE_STALE_MS)
          .map((entry) => entry.login.toLowerCase())
      );
    } catch (err) {
      console.error(`Failed to load ${jsonUrl}:`, err);
      return new Set();
    }
  }

  
  async function loadAllLiveLogins() {
    const entries = await Promise.all(
      LIVE_STATUS_PLATFORMS.map(async (platform) => [platform.key, await loadLiveLogins(platform.jsonUrl)])
    );
    return Object.fromEntries(entries);
  }

  
  let dataJsonPromise = null;
  function loadDataJson() {
    if (!dataJsonPromise) {
      dataJsonPromise = fetch("/data.json").then((response) => (response.ok ? response.json() : null));
    }
    return dataJsonPromise;
  }

  function escapeHtml(str) {
    const div = document.createElement("div");
    div.textContent = str;
    return div.innerHTML;
  }

  function profileLink(baseUrl, handle, ...pathSegments) {
    const segments = [handle, ...pathSegments].filter(Boolean).map(encodeURIComponent);
    return `${baseUrl}${segments.join("/")}`;
  }

  function pillLink(className, href, title, iconKey, label) {
    const iconAttr = iconKey ? ` data-icon="${iconKey}"` : "";
    return `<a class="${className}"${iconAttr} href="${href}" target="_blank" rel="noopener noreferrer" title="${escapeHtml(title)}">${escapeHtml(label)}</a>`;
  }

  function getOtherLinks(creator) {
    if (!Array.isArray(creator.other)) return [];
    return creator.other.flatMap((entry) =>
      Object.entries(entry || {})
        .filter(([, url]) => url)
        .map(([label, url]) => ({ label, url }))
    );
  }

  function getChannelInfo(creator) {
    if (!creator.channel) return null;
    const platform = CHANNEL_PLATFORMS[creator.type] || CHANNEL_PLATFORMS.twitch;
    return { href: profileLink(platform.baseUrl, creator.channel), label: platform.label };
  }

  function isSimpleMedia(value) {
    return String(value || "").toLowerCase() === "simple";
  }

  function getAvatarFolder(creator) {
    return AVATAR_FOLDERS.has(creator.avatar) ? creator.avatar : "avatarsLarge";
  }

  
  
  
  function getAvatarSrc(creator) {
    if (isSimpleMedia(creator.avatar)) {
      return null;
    }
    const avatarFolder = getAvatarFolder(creator);
    const avatarBaseName = getAvatarBaseName(creator, avatarFolder);
    return `${avatarFolder}/${encodeURIComponent(avatarBaseName)}.webp`;
  }

  function getAvatarBaseName(creator, folder) {
    if (folder === "avatarsX") {
      const firstXHandle = Array.isArray(creator.xHandles) ? creator.xHandles.find(Boolean) : "";
      if (firstXHandle) return firstXHandle.toLowerCase();
    }

    if (folder === "avatarsB") {
      const bskyName = Array.isArray(creator.bskyHandle) ? creator.bskyHandle[0] : "";
      if (bskyName) return String(bskyName).toLowerCase();
    }

    return creator.channelLower;
  }

  function buildRow(creator) {
    const tr = document.createElement("tr");

    const channelTd = document.createElement("td");
    channelTd.dataset.label = "Channel";
    channelTd.className = "name-cell";
    const avatarSrc = getAvatarSrc(creator);
    const avatarImgHtml = avatarSrc
      ? `<img class="avatar-img" src="${avatarSrc}" alt="" decoding="async" loading="lazy">`
      : "";
    channelTd.innerHTML = creator.channel
      ? `<a class="name-link" href="${BASE_URL}c/${encodeURIComponent(creator.channelLower)}/">` +
        `<span class="avatar">${avatarImgHtml}</span>` +
        `<span class="channel-name">${escapeHtml(creator.channel)}</span>` +
        `</a>`
      : "";

    const spiceTd = document.createElement("td");
    spiceTd.dataset.label = "Spice";
    const spicePills = SPICE_PLATFORMS.filter((platform) => creator[platform.key])
      .map((platform) => {
        const value = creator[platform.key];
        const ref = platform.refKey ? creator[platform.refKey] || "spicy" : undefined;
        const isPath = platform.rootBaseUrl && value.includes("/");
        const href = isPath
          ? profileLink(platform.rootBaseUrl, ...value.split("/"))
          : profileLink(platform.baseUrl, value, ref);
        const text = isPath ? creator.channelLower : value.toLowerCase();
        const isLive = creator.liveStatus && creator.liveStatus[platform.key];
        const pillClass = isLive ? `spice-pill is-live-${platform.key}` : "spice-pill";
        return pillLink(pillClass, href, `${platform.label}: ${text}`, platform.key, text);
      })
      .join(" ");
    const otherPills = getOtherLinks(creator)
      .map(({ label, url }) => pillLink("spice-pill other-pill", escapeHtml(url), url, "", label))
      .join(" ");
    const xPills = creator.xHandles
      .map((handle) => pillLink("x-pill", profileLink("https://x.com/", handle), `Twitter: ${handle}`, "x", handle.toLowerCase()))
      .join(" ");
    const [bskyName, bskyLink] = Array.isArray(creator.bskyHandle) ? creator.bskyHandle : [];
    const bskyLabel = (bskyName || bskyLink || "").toLowerCase();
    const bskyPill = bskyLink
      ? pillLink("x-pill", profileLink("https://bsky.app/profile/", bskyLink), `Bluesky: ${bskyLabel}`, "bsky", bskyLabel)
      : "";
    
    
    
    
    spiceTd.innerHTML = `<div class="spice-handles">${[spicePills, otherPills, xPills, bskyPill].filter(Boolean).join(" ")}</div>`;

    tr.append(channelTd, spiceTd);
    return tr;
  }

  async function renderStats() {
    let stats = {};
    try {
      stats = (await loadDataJson()) || {};
    } catch (err) {
      console.error("Failed to load data.json:", err);
    }
    const format = (n) => (n || 0).toLocaleString();
    siteStats.innerHTML =
      `<strong>${format(stats.spicyLinks)}</strong> Spicy Links · ` +
      `<strong>${format(stats.twitterBsky)}</strong> X/Bsky · ` +
      `<strong>${format(stats.socials)}</strong> Socials`;
  }

  (() => {
    const wrap = document.getElementById("get-extension-wrap");
    const toggleBtn = document.getElementById("get-extension-btn");
    const menu = document.getElementById("get-extension-menu");
    if (!wrap || !toggleBtn || !menu) return;

    Object.entries(EXTENSION_URLS).forEach(([browser, url]) => {
      const option = document.getElementById(`get-extension-${browser}`);
      if (!option) return;
      if (url) {
        option.href = url;
        option.removeAttribute("aria-disabled");
      } else {
        option.href = "#";
        option.setAttribute("aria-disabled", "true");
      }
    });

    const EXTENSION_VERSION_KEYS = { chrome: "chromeVersion", firefox: "firefoxVersion" };
    loadDataJson()
      .then((data) => {
        if (!data) return;
        Object.entries(EXTENSION_VERSION_KEYS).forEach(([browser, key]) => {
          const option = document.getElementById(`get-extension-${browser}`);
          const versionEl = option && option.querySelector(".get-extension-version");
          if (versionEl && data[key]) versionEl.textContent = data[key];
        });
      })
      .catch((err) => console.error("Failed to load data.json:", err));

    function closeMenu() {
      menu.hidden = true;
      toggleBtn.setAttribute("aria-expanded", "false");
    }

    function openMenu() {
      menu.hidden = false;
      toggleBtn.setAttribute("aria-expanded", "true");
    }

    toggleBtn.addEventListener("click", (event) => {
      event.stopPropagation();
      if (menu.hidden) {
        openMenu();
      } else {
        closeMenu();
      }
    });

    menu.addEventListener("click", (event) => {
      const option = event.target.closest(".get-extension-option");
      if (option && option.getAttribute("aria-disabled") === "true") {
        event.preventDefault();
      }
    });

    document.addEventListener("click", (event) => {
      if (!menu.hidden && !wrap.contains(event.target)) {
        closeMenu();
      }
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && !menu.hidden) {
        closeMenu();
        toggleBtn.focus();
      }
    });
  })();

  
  (() => {
    const criteriaToggleBtn = document.getElementById("criteria-toggle-btn");
    const criteriaContent = document.getElementById("criteria-content");
    if (!criteriaToggleBtn || !criteriaContent) return;

    criteriaToggleBtn.addEventListener("click", () => {
      const isHidden = criteriaContent.hidden;
      if (isHidden && !criteriaContent.dataset.loaded) {
        
        
        const template = document.getElementById("criteria-content-template");
        if (template) {
          criteriaContent.appendChild(template.content.cloneNode(true));
          criteriaContent.dataset.loaded = "true";
        }
      }
      criteriaContent.hidden = !isHidden;
      criteriaToggleBtn.classList.toggle("expanded", isHidden);
      criteriaToggleBtn.setAttribute("aria-expanded", String(isHidden));
    });
  })();

  
  function initIndex() {
    const tableSection = document.getElementById("table-section");
    const siteFooter = document.getElementById("site-footer");
    const thead = document.getElementById("creator-thead");
    const tbody = document.getElementById("creator-tbody");
    const searchInput = document.getElementById("search-input");
    const searchClearBtn = document.getElementById("search-clear-btn");
    const resultCount = document.getElementById("result-count");
    const emptyState = document.getElementById("empty-state");
    const platformFilterEl = document.getElementById("platform-filter");
    const sortToggleBtn = document.getElementById("sort-toggle");
    const loadMoreWrap = document.getElementById("load-more-wrap");
    const loadMoreBtn = document.getElementById("load-more-btn");
    const liveFilterBtn = document.getElementById("live-filter-btn");
    if (!tableSection || !thead || !tbody || !searchInput || !platformFilterEl || !sortToggleBtn || !loadMoreWrap || !loadMoreBtn || !liveFilterBtn) return;

    let creators = [];
    let azOrderCreators = [];
    let jsonOrderCreators = [];
    let newestOrderCreators = [];
    let sortMode = "az"; 
    let platformFilter = "all";
    let liveFilterActive = false;
    let lastLiveFetchAt = 0;
    const LIVE_REFETCH_INTERVAL_MS = 60 * 1000;
    let filteredRows = [];
    const INITIAL_ROWS = 50;
    const LOAD_BATCH_SIZE = 200;
    const rowCache = new WeakMap();

    function getOrBuildRow(creator) {
      let tr = rowCache.get(creator);
      if (!tr) {
        tr = buildRow(creator);
        rowCache.set(creator, tr);
      }
      return tr;
    }

    
    
    let profileRow = null;
    function getProfileRow() {
      if (!profileRow) {
        profileRow = document.createElement("tr");
        profileRow.className = "profile-row";
        const td = document.createElement("td");
        td.className = "profile-cell";
        td.colSpan = 2;
        td.innerHTML = `<a class="view-profile-link">View Profile</a>`;
        profileRow.appendChild(td);
      }
      return profileRow;
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

    
    
    
    
    function applyLiveStatus(liveLoginsByPlatform) {
      jsonOrderCreators.forEach((creator) => {
        const prevLiveStatus = creator.liveStatus;
        const liveStatus = {};
        let anyLive = false;
        LIVE_STATUS_PLATFORMS.forEach((platform) => {
          const isLive = liveLoginsByPlatform[platform.key].has(creator.channelLower);
          liveStatus[platform.key] = isLive;
          if (isLive) anyLive = true;
        });
        const changed =
          !prevLiveStatus || LIVE_STATUS_PLATFORMS.some((platform) => Boolean(prevLiveStatus[platform.key]) !== liveStatus[platform.key]);
        creator.liveStatus = liveStatus;
        creator.isLive = anyLive;
        if (changed) rowCache.delete(creator);
      });
      liveFilterBtn.hidden = !jsonOrderCreators.some((creator) => creator.isLive);
    }

    
    liveFilterBtn.addEventListener("click", async () => {
      if (Date.now() - lastLiveFetchAt >= LIVE_REFETCH_INTERVAL_MS) {
        lastLiveFetchAt = Date.now();
        applyLiveStatus(await loadAllLiveLogins());
      }
      liveFilterActive = !liveFilterActive;
      liveFilterBtn.classList.toggle("is-active", liveFilterActive);
      liveFilterBtn.setAttribute("aria-pressed", String(liveFilterActive));
      update();
    });

    
    function renderInitialRows(data) {
      if (data.length === 0) {
        tbody.innerHTML = "";
        emptyState.hidden = false;
        thead.hidden = false;
        return;
      }
      emptyState.hidden = true;

      
      
      
      const isSolo = data.length === 1;
      const nextRows = data.slice(0, INITIAL_ROWS).map((creator) => getOrBuildRow(creator));
      const profileRowEl = getProfileRow();
      const nextRowSet = new Set(nextRows);
      if (isSolo) nextRowSet.add(profileRowEl);

      tbody.querySelectorAll(".is-profile-open").forEach((tr) => tr.classList.remove("is-profile-open"));

      Array.from(tbody.children).forEach((tr) => {
        if (!nextRowSet.has(tr)) tr.remove();
      });

      nextRows.forEach((tr) => tbody.appendChild(tr));

      thead.hidden = isSolo;
      if (isSolo) {
        const soloRow = nextRows[0];
        soloRow.classList.add("is-profile-open");
        profileRowEl.querySelector(".view-profile-link").href = soloRow.querySelector(".name-link").href;
        tbody.appendChild(profileRowEl);
      }
    }

    function renderMoreRows(data) {
      const currentCount = tbody.children.length;
      const nextRows = data.slice(currentCount, currentCount + LOAD_BATCH_SIZE).map((creator) => getOrBuildRow(creator));
      nextRows.forEach((tr) => tbody.appendChild(tr));
    }

    function updateResultCount(count, total) {
      const suffix = count === total ? "" : `<span class="result-count-total"> of ${total}</span>`;
      resultCount.innerHTML = `${resultCountIcon}${count}${suffix}`;
    }

    function getFiltered() {
      const query = searchInput.value.trim().toLowerCase();
      return creators.filter((creator) => {
        if (platformFilter !== "all" && !creator[platformFilter]) return false;
        if (liveFilterActive && !creator.isLive) return false;
        if (query && !creator.searchText.includes(query)) return false;
        return true;
      });
    }

    function updateSearchClearBtn() {
      searchClearBtn.hidden = searchInput.value.trim() === "";
    }

    function update() {

      window.scrollTo({ top: 0, behavior: "smooth" }); 
      updateSearchClearBtn();
      filteredRows = getFiltered();
      renderInitialRows(filteredRows);    
      updateResultCount(filteredRows.length, creators.length);
      loadMoreWrap.hidden = filteredRows.length <= INITIAL_ROWS;
    }

    function debounce(fn, delay) {
      let timer;
      return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
      };
    }

    const debouncedUpdate = debounce(update, 200);
    searchInput.addEventListener("input", () => {
      updateSearchClearBtn();
      debouncedUpdate();
    });

    searchClearBtn.addEventListener("click", () => {
      searchInput.value = "";
      update();
      searchInput.focus();
    });

    sortToggleBtn.addEventListener("click", () => {
      sortMode = sortMode === "az" ? "newest" : "az";
      creators = sortMode === "az" ? azOrderCreators : newestOrderCreators;
      sortToggleBtn.classList.toggle("is-active", sortMode === "newest");
      sortToggleBtn.setAttribute("aria-pressed", String(sortMode === "newest"));
      update();
    });

    tbody.addEventListener(
      "load",
      (e) => {
        if (e.target.classList?.contains("avatar-img")) e.target.classList.add("is-loaded");
      },
      true
    );
    tbody.addEventListener(
      "error",
      (e) => {
        if (e.target.classList?.contains("avatar-img")) e.target.remove();
      },
      true
    );

    
    
    
    tbody.addEventListener("click", (e) => {
      if (e.target.closest("a")) return;
      const tr = e.target.closest("tr");
      const link = tr?.querySelector(".name-link, .view-profile-link");
      if (link) window.location.href = link.href;
    });

    loadMoreBtn.addEventListener("click", () => {
      renderMoreRows(filteredRows);
      if (tbody.children.length >= filteredRows.length) {
        loadMoreWrap.hidden = true;
      }
    });

    async function init() {
      let liveLoginsByPlatform = {};
      try {
        const [response, liveData] = await Promise.all([fetch("/accounts.json"), loadAllLiveLogins()]);
        liveLoginsByPlatform = liveData;
        lastLiveFetchAt = Date.now();
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();
        jsonOrderCreators = data
          .filter((c) => c && typeof c.channel === "string" && c.channel.trim() !== "")
          .map((c) => ({ ...c, xHandles: (c.xHandles || []).filter(Boolean) }));
        azOrderCreators = [...jsonOrderCreators].sort((a, b) =>
          a.channel.toLowerCase().localeCompare(b.channel.toLowerCase())
        );
        newestOrderCreators = [...jsonOrderCreators].reverse();
        creators = azOrderCreators;

        jsonOrderCreators.forEach((creator) => {
          creator.channelLower = creator.channel.toLowerCase();
          creator.searchText = [
            creator.channel,
            ...SPICE_PLATFORMS.map((platform) => {
              const value = creator[platform.key];
              if (value && platform.rootBaseUrl && value.includes("/")) return undefined;
              return value;
            }),
            ...creator.xHandles,
            Array.isArray(creator.bskyHandle) ? creator.bskyHandle[0] : undefined,
            ...getOtherLinks(creator).map((link) => link.label),
          ]
            .filter(Boolean)
            .join(" ")
            .toLowerCase();
        });

        applyLiveStatus(liveLoginsByPlatform);
      } catch (err) {
        console.error("Failed to load accounts.json:", err);
        resultCount.textContent = "Couldn't load creator data.";
        emptyState.hidden = false;
        emptyState.textContent =
          "Couldn't load accounts.json (if opening this file directly, serve it via a local web server).";
        return;
      }
      update();
      tableSection.hidden = false;
      if (siteFooter) siteFooter.hidden = false;
    }

    init();
    renderStats();
  }

  
  function initCreatorPage() {
    renderStats();
    initStreamTabs();
    initFanslyGraphs();
    initStreamHistory();
  }

  
  
  function initStreamTabs() {
    const tabsWrap = document.querySelector(".stream-main-tabs");
    if (!tabsWrap) return;

    tabsWrap.addEventListener("click", (e) => {
      const btn = e.target.closest(".stream-tab-btn");
      if (!btn || btn.classList.contains("is-active")) return;

      const tab = btn.dataset.tab;
      tabsWrap.querySelectorAll(".stream-tab-btn").forEach((b) => {
        b.classList.toggle("is-active", b === btn);
      });
      document.querySelectorAll(".stream-tab-panel").forEach((panel) => {
        panel.classList.toggle("is-active", panel.dataset.tab === tab);
      });
    });
  }

  
  
  
  function initFanslyGraphs() {
    const pillsWrap = document.querySelector(".fansly-month-pills");
    if (!pillsWrap) return;

    pillsWrap.addEventListener("click", (e) => {
      const btn = e.target.closest(".fansly-month-btn");
      if (!btn || btn.classList.contains("is-active")) return;

      const month = btn.dataset.month;
      pillsWrap.querySelectorAll(".fansly-month-btn").forEach((b) => {
        b.classList.toggle("is-active", b === btn);
      });
      document.querySelectorAll(".fansly-graph-panel").forEach((panel) => {
        panel.classList.toggle("is-active", panel.dataset.month === month);
      });
    });
  }

  
  
  
  
  const STREAM_HISTORY_PLATFORMS = {
    fansly: { cdnBase: "https://cdn.spicyvtubers.com/fansly/streams/" },
    rplay: { cdnBase: "https://cdn.spicyvtubers.com/rplay/streams/" },
  };
  const MAX_STREAM_DURATION_MS = 24 * 60 * 60 * 1000;
  const RECENT_FETCH_WINDOW_MS = 60 * 60 * 1000;

  function formatStreamDuration(ms) {
    if (typeof ms !== "number" || ms < 0) return "—";
    const totalMinutes = Math.round(ms / 60000);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`;
  }

  function formatStreamTimestamp(ms) {
    if (typeof ms !== "number") return "—";
    return new Date(ms).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" });
  }

  
  function computeStreamDurationMs(stream) {
    return typeof stream.startedAt === "number" && typeof stream.lastFetchedAt === "number"
      ? stream.lastFetchedAt - stream.startedAt
      : null;
  }

  
  
  
  function buildViewerGraphSvg(viewerData) {
    const width = 760;
    const height = 220;
    const padLeft = 44;
    const padRight = 16;
    const padTop = 16;
    const padBottom = 30;
    const plotWidth = width - padLeft - padRight;
    const plotHeight = height - padTop - padBottom;

    const times = viewerData.map((p) => p[0]);
    const counts = viewerData.map((p) => p[1]);
    const minTs = Math.min(...times);
    const maxTs = Math.max(...times);
    const tsSpan = Math.max(maxTs - minTs, 1);
    const maxCount = Math.max(...counts, 1);

    const x = (ts) => padLeft + ((ts - minTs) / tsSpan) * plotWidth;
    const y = (count) => padTop + (1 - count / maxCount) * plotHeight;

    const points = viewerData.map(([ts, count]) => `${x(ts).toFixed(1)},${y(count).toFixed(1)}`).join(" ");

    let yTicksSvg = "";
    const yTickCount = 4;
    for (let i = 0; i <= yTickCount; i++) {
      const value = Math.round((maxCount * i) / yTickCount);
      const ty = y(value).toFixed(1);
      yTicksSvg += `<text class="stream-axis-text" x="${padLeft - 8}" y="${ty}" text-anchor="end" dominant-baseline="middle">${value}</text>`;
    }

    let xTicksSvg = "";
    const xTickCount = 5;
    for (let i = 0; i < xTickCount; i++) {
      const tickTs = minTs + (i * tsSpan) / (xTickCount - 1);
      const tx = x(tickTs).toFixed(1);
      const label = escapeHtml(
        new Date(tickTs * 1000).toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" })
      );
      xTicksSvg += `<text class="stream-axis-text" x="${tx}" y="${height - padBottom + 18}" text-anchor="middle">${label}</text>`;
    }

    return `<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Viewer count over time" class="viewer-graph-svg">${yTicksSvg}${xTicksSvg}<polyline class="stream-line" points="${points}"></polyline></svg>`;
  }

  
  
  function toggleStreamGraphRow(tbody, row, stream) {
    const existing = row.nextElementSibling;
    if (existing && existing.classList.contains("stream-graph-row")) {
      existing.remove();
      row.classList.remove("is-open");
      return;
    }

    tbody.querySelectorAll(".stream-graph-row").forEach((el) => el.remove());
    tbody.querySelectorAll(".stream-row.is-open").forEach((el) => el.classList.remove("is-open"));

    row.classList.add("is-open");
    const graphRow = document.createElement("tr");
    graphRow.className = "stream-graph-row";
    const td = document.createElement("td");
    td.colSpan = 4;
    td.innerHTML = buildViewerGraphSvg(stream.viewerData);
    graphRow.appendChild(td);
    row.after(graphRow);
  }

  function showNoStreamDataMessage(container) {
    container.innerHTML = '<p class="stream-empty-message">No stream data yet.</p>';
  }

  
  
  
  async function loadStreamHistory(container) {
    const slug = container.dataset.slug;
    const platformConfig = STREAM_HISTORY_PLATFORMS[container.dataset.platform];
    if (!platformConfig) return;

    try {
      const res = await fetch(`${platformConfig.cdnBase}${encodeURIComponent(slug)}.json`);
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const rawStreams = await res.json();
      if (!Array.isArray(rawStreams)) throw new Error("Unexpected response shape");

      if (rawStreams.length === 0) {
        showNoStreamDataMessage(container);
        return;
      }

      
      const ordered = rawStreams.slice().reverse();

      
      for (const stream of ordered) {
        if (!Array.isArray(stream.viewerData) || stream.viewerData.length === 0) continue;
        const startedAtSec = typeof stream.startedAt === "number" ? Math.round(stream.startedAt / 1000) : null;
        if (startedAtSec !== null && startedAtSec < stream.viewerData[0][0]) {
          stream.viewerData = [[startedAtSec, 0], ...stream.viewerData];
        }
      }

      const rowsHtml = ordered
        .map((stream, i) => {
          const durationMs = computeStreamDurationMs(stream);
          
          const durationTooLong = durationMs !== null && durationMs > MAX_STREAM_DURATION_MS;
          
          const recentlyFetched = typeof stream.lastFetchedAt === "number" && Date.now() - stream.lastFetchedAt < RECENT_FETCH_WINDOW_MS;
          const start = escapeHtml(formatStreamTimestamp(stream.startedAt));
          const finish = durationTooLong || recentlyFetched ? "—" : escapeHtml(formatStreamTimestamp(stream.lastFetchedAt));
          const duration = durationTooLong ? "—" : escapeHtml(formatStreamDuration(durationMs));
          const viewers =
            typeof stream.maxViewers === "number" && stream.maxViewers > 0 ? escapeHtml(String(stream.maxViewers)) : "—";
          const hasGraph = Array.isArray(stream.viewerData) && stream.viewerData.length >= 2;
          const rowClass = hasGraph ? "stream-row is-expandable" : "stream-row";
          return `<tr class="${rowClass}" data-idx="${i}"><td>${start}</td><td>${finish}</td><td>${duration}</td><td>${viewers}</td></tr>`;
        })
        .join("");

      container.insertAdjacentHTML(
        "beforeend",
        `<table class="streams-table"><thead><tr><th>Start</th><th>Finish</th><th>Duration</th><th>Viewers</th></tr></thead><tbody>${rowsHtml}</tbody></table>`
      );

      const tbody = container.querySelector(".streams-table tbody");
      tbody.addEventListener("click", (e) => {
        const row = e.target.closest(".stream-row.is-expandable");
        if (!row) return;
        toggleStreamGraphRow(tbody, row, ordered[Number(row.dataset.idx)]);
      });
    } catch (err) {
      
      showNoStreamDataMessage(container);
    }
  }

  
  
  function initStreamHistory() {
    document.querySelectorAll(".stream-history-panel").forEach((container) => loadStreamHistory(container));
  }

  

  const WEEKLY_STATS_URL = "https://cdn.spicyvtubers.com/fansly/weekly-stats.json";
  const DAY_LABELS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
  const INSIGHTS_METRICS = [
    { key: "streamStarts", label: "Stream Starts", format: (v) => `${v} start${v === 1 ? "" : "s"}` },
    { key: "avgConcurrentStreams", label: "Concurrent Streams", format: (v) => `~${v.toFixed(1)} live` },
    { key: "avgViewers", label: "Avg Viewers", format: (v) => `${v} viewers` },
  ];

  async function initInsightsPage() {
    const summaryRoot = document.getElementById("insights-summary");
    const tabsRoot = document.getElementById("insights-tabs");
    const graphRoot = document.getElementById("insights-root");
    if (!summaryRoot || !tabsRoot || !graphRoot) return;

    try {
      const res = await fetch(WEEKLY_STATS_URL, { cache: "no-store" });
      if (!res.ok) throw new Error("bad status");
      const data = await res.json();
      const buckets = Array.isArray(data && data.buckets) ? data.buckets : [];
      if (buckets.length === 0) {
        showInsightsEmptyMessage(summaryRoot, tabsRoot, graphRoot);
        return;
      }
      renderInsights(summaryRoot, tabsRoot, graphRoot, buckets);
    } catch (err) {
      
      showInsightsEmptyMessage(summaryRoot, tabsRoot, graphRoot);
    }
  }

  function showInsightsEmptyMessage(summaryRoot, tabsRoot, graphRoot) {
    summaryRoot.innerHTML = "";
    tabsRoot.innerHTML = "";
    graphRoot.innerHTML = '<p class="insights-empty-message">No insights data yet — check back soon.</p>';
  }

  function formatHourLabel(hour) {
    const period = hour < 12 ? "AM" : "PM";
    const h = hour % 12 === 0 ? 12 : hour % 12;
    return `${h} ${period}`;
  }

  
  
  function convertBucketsToLocalTime(utcBuckets) {
    const localMap = new Map();
    const now = new Date();
    const refSunday = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate() - now.getUTCDay(), 0, 0, 0, 0));

    for (const bucket of utcBuckets) {
      const ts = refSunday.getTime() + (bucket.dow * 24 + bucket.hour) * 60 * 60 * 1000;
      const localDate = new Date(ts);
      const localDow = localDate.getDay();
      const localHour = localDate.getHours();
      localMap.set(`${localDow}:${localHour}`, {
        dow: localDow,
        hour: localHour,
        streamStarts: bucket.streamStarts,
        avgConcurrentStreams: bucket.avgConcurrentStreams,
        avgViewers: bucket.avgViewers,
      });
    }
    return localMap;
  }

  function buildHeatmapGrid(localMap, metricKey, maxValue, formatValue) {
    let html = '<div class="insights-heatmap">';
    html += '<div class="insights-heatmap-corner"></div>';
    for (let dow = 0; dow < 7; dow++) {
      html += `<div class="insights-heatmap-day-label">${DAY_LABELS[dow]}</div>`;
    }
    for (let hour = 0; hour < 24; hour++) {
      html += `<div class="insights-heatmap-hour-label">${formatHourLabel(hour)}</div>`;
      for (let dow = 0; dow < 7; dow++) {
        const bucket = localMap.get(`${dow}:${hour}`);
        const value = bucket ? bucket[metricKey] : 0;
        const intensity = maxValue > 0 ? Math.min(1, value / maxValue) : 0;
        const title = `${DAY_LABELS[dow]} ${formatHourLabel(hour)}: ${formatValue(value)}`;
        html += `<div class="insights-cell" style="--intensity:${intensity.toFixed(3)}" title="${escapeHtml(title)}"></div>`;
      }
    }
    html += "</div>";
    return html;
  }

  function renderInsights(summaryRoot, tabsRoot, graphRoot, utcBuckets) {
    const localMap = convertBucketsToLocalTime(utcBuckets);
    const localBuckets = Array.from(localMap.values());

    const summaryHtml = INSIGHTS_METRICS.map((m) => {
      const busiest = localBuckets.reduce((best, b) => (!best || b[m.key] > best[m.key] ? b : best), null);
      if (!busiest || busiest[m.key] <= 0) return "";
      return `<span class="insights-stat"><span class="insights-stat-label">Busiest for ${escapeHtml(
        m.label
      )}:</span> ${DAY_LABELS[busiest.dow]} ${formatHourLabel(busiest.hour)}</span>`;
    })
      .filter(Boolean)
      .join("");

    summaryRoot.innerHTML =
      `<div class="insights-summary">${summaryHtml}</div>` +
      '<p class="insights-tz-note">Times shown in your local timezone.</p>';

    tabsRoot.innerHTML = INSIGHTS_METRICS.map(
      (m, i) => `<button type="button" class="insights-tab-btn${i === 0 ? " is-active" : ""}" data-tab="${m.key}">${escapeHtml(m.label)}</button>`
    ).join("");

    graphRoot.innerHTML = INSIGHTS_METRICS.map((m, i) => {
      const maxValue = localBuckets.reduce((max, b) => Math.max(max, b[m.key]), 0);
      const grid = buildHeatmapGrid(localMap, m.key, maxValue, m.format);
      return `<div class="insights-tab-panel${i === 0 ? " is-active" : ""}" data-tab="${m.key}">${grid}</div>`;
    }).join("");

    initInsightsTabs();
  }

  function initInsightsTabs() {
    const tabsWrap = document.getElementById("insights-tabs");
    if (!tabsWrap) return;

    tabsWrap.addEventListener("click", (e) => {
      const btn = e.target.closest(".insights-tab-btn");
      if (!btn || btn.classList.contains("is-active")) return;

      const tab = btn.dataset.tab;
      tabsWrap.querySelectorAll(".insights-tab-btn").forEach((b) => {
        b.classList.toggle("is-active", b === btn);
      });
      document.querySelectorAll(".insights-tab-panel").forEach((panel) => {
        panel.classList.toggle("is-active", panel.dataset.tab === tab);
      });
    });
  }

  

  const LEADERBOARD_LIVE_URL = "https://cdn.spicyvtubers.com/fansly/leaderboard/live.json";
  const LEADERBOARD_ACCOUNT_BASE = "https://cdn.spicyvtubers.com/fansly/leaderboard/";
  const LEADERBOARD_MAX_SELECTED = 10;
  const LEADERBOARD_INITIAL_TOP_COUNT = 3;
  const LEADERBOARD_COLORS = [
    "#0284c7",
    "#ef4444",
    "#22c55e",
    "#eab308",
    "#a855f7",
    "#f97316",
    "#14b8a6",
    "#ec4899",
    "#3b82f6",
    "#84cc16",
  ];

  let leaderboardEntries = [];
  const leaderboardHistoryCache = new Map(); 
  const leaderboardSelected = new Map(); 

  async function initLeaderboardPage() {
    const graphRoot = document.getElementById("leaderboard-graph");
    const gridRoot = document.getElementById("leaderboard-grid");
    const selectedRoot = document.getElementById("leaderboard-selected");
    const clearBtn = document.getElementById("leaderboard-clear-btn");
    if (!graphRoot || !gridRoot || !selectedRoot || !clearBtn) return;

    try {
      const res = await fetch(`${LEADERBOARD_LIVE_URL}?t=${Date.now()}`, { cache: "no-store" });
      if (!res.ok) throw new Error("bad status");
      const data = await res.json();
      if (!Array.isArray(data) || data.length === 0) {
        showLeaderboardEmptyMessage(graphRoot, gridRoot);
        return;
      }
      leaderboardEntries = data.filter((e) => e && e.accountId != null);
    } catch (err) {
      showLeaderboardEmptyMessage(graphRoot, gridRoot);
      return;
    }

    renderLeaderboardGrid(gridRoot);
    renderLeaderboardGraph(graphRoot);
    gridRoot.addEventListener("click", (e) => {
      const btn = e.target.closest(".leaderboard-entry");
      if (!btn) return;
      toggleLeaderboardAccount(btn.dataset.accountId, graphRoot, gridRoot, selectedRoot);
    });
    selectedRoot.addEventListener("click", (e) => {
      const btn = e.target.closest(".leaderboard-selected-remove");
      if (!btn) return;
      removeLeaderboardAccount(btn.dataset.accountId, graphRoot, gridRoot, selectedRoot);
    });
    clearBtn.addEventListener("click", () => clearLeaderboardSelection(graphRoot, gridRoot, selectedRoot));

    await selectTopRankedLeaderboardEntries(graphRoot, gridRoot, selectedRoot);
  }

  function showLeaderboardEmptyMessage(graphRoot, gridRoot) {
    graphRoot.innerHTML = '<p class="leaderboard-empty-message">No leaderboard data yet — check back soon.</p>';
    gridRoot.innerHTML = "";
  }

  
  
  function leaderboardEntryParts(entry) {
    if (!entry.displayName && !entry.username) return { name: "Unknown", username: "" };
    if (!entry.displayName) return { name: entry.username, username: "" };
    return { name: entry.displayName, username: entry.username ? `@${entry.username}` : "" };
  }

  function leaderboardColorFor(accountId) {
    const order = Array.from(leaderboardSelected.keys());
    const idx = order.indexOf(accountId);
    return idx === -1 ? LEADERBOARD_COLORS[0] : LEADERBOARD_COLORS[idx % LEADERBOARD_COLORS.length];
  }

  function renderLeaderboardGrid(gridRoot) {
    gridRoot.innerHTML = leaderboardEntries
      .map((entry) => {
        const isSelected = leaderboardSelected.has(entry.accountId);
        const style = isSelected ? ` style="--chip-color:${leaderboardColorFor(entry.accountId)}"` : "";
        const parts = leaderboardEntryParts(entry);
        const usernameHtml = parts.username ? `<span class="leaderboard-entry-username">${escapeHtml(parts.username)}</span>` : "";
        return (
          `<button type="button" class="leaderboard-entry${isSelected ? " is-selected" : ""}" data-account-id="${escapeHtml(
            String(entry.accountId)
          )}"${style}>` +
          `<span class="leaderboard-entry-dot"></span>` +
          `<span class="leaderboard-entry-rank">${entry.rank}.</span>` +
          `<span class="leaderboard-entry-name">${escapeHtml(parts.name)}</span>` +
          usernameHtml +
          `</button>`
        );
      })
      .join("");
  }

  
  
  function renderLeaderboardSelectedChips(selectedRoot) {
    selectedRoot.innerHTML = Array.from(leaderboardSelected.entries())
      .sort((a, b) => a[1].rank - b[1].rank)
      .map(([accountId, entry]) => {
        const color = leaderboardColorFor(accountId);
        const parts = leaderboardEntryParts(entry);
        const label = `${entry.rank}. ${parts.name}`;
        return (
          `<span class="leaderboard-selected-chip" style="--chip-color:${color}">` +
          `<span class="leaderboard-selected-swatch"></span>` +
          `<span>${escapeHtml(label)}</span>` +
          `<button type="button" class="leaderboard-selected-remove" data-account-id="${escapeHtml(
            String(accountId)
          )}" aria-label="Remove ${escapeHtml(label)}">×</button>` +
          `</span>`
        );
      })
      .join("");
  }

  async function ensureLeaderboardHistory(accountId) {
    if (leaderboardHistoryCache.has(accountId)) return leaderboardHistoryCache.get(accountId);
    try {
      const res = await fetch(`${LEADERBOARD_ACCOUNT_BASE}${encodeURIComponent(accountId)}.json?t=${Date.now()}`, { cache: "no-store" });
      if (!res.ok) throw new Error("bad status");
      const data = await res.json();
      const points = Array.isArray(data) ? data : [];
      leaderboardHistoryCache.set(accountId, points);
      return points;
    } catch (err) {
      leaderboardHistoryCache.set(accountId, []);
      return [];
    }
  }

  
  
  async function addLeaderboardAccounts(entries, graphRoot, gridRoot, selectedRoot) {
    const capacity = LEADERBOARD_MAX_SELECTED - leaderboardSelected.size;
    if (capacity <= 0) return;
    const toAdd = entries.filter((e) => !leaderboardSelected.has(e.accountId)).slice(0, capacity);
    if (toAdd.length === 0) return;

    await Promise.all(toAdd.map((e) => ensureLeaderboardHistory(e.accountId)));
    for (const entry of toAdd) {
      leaderboardSelected.set(entry.accountId, entry);
    }

    renderLeaderboardGrid(gridRoot);
    renderLeaderboardSelectedChips(selectedRoot);
    renderLeaderboardGraph(graphRoot);
  }

  function removeLeaderboardAccount(accountId, graphRoot, gridRoot, selectedRoot) {
    if (!leaderboardSelected.has(accountId)) return;
    leaderboardSelected.delete(accountId);
    renderLeaderboardGrid(gridRoot);
    renderLeaderboardSelectedChips(selectedRoot);
    renderLeaderboardGraph(graphRoot);
  }

  async function toggleLeaderboardAccount(accountId, graphRoot, gridRoot, selectedRoot) {
    if (leaderboardSelected.has(accountId)) {
      removeLeaderboardAccount(accountId, graphRoot, gridRoot, selectedRoot);
      return;
    }
    const entry = leaderboardEntries.find((e) => String(e.accountId) === String(accountId));
    if (!entry) return;
    await addLeaderboardAccounts([entry], graphRoot, gridRoot, selectedRoot);
  }

  
  async function selectTopRankedLeaderboardEntries(graphRoot, gridRoot, selectedRoot) {
    const topEntries = leaderboardEntries
      .slice()
      .sort((a, b) => a.rank - b.rank)
      .slice(0, LEADERBOARD_INITIAL_TOP_COUNT);
    await addLeaderboardAccounts(topEntries, graphRoot, gridRoot, selectedRoot);
  }

  function clearLeaderboardSelection(graphRoot, gridRoot, selectedRoot) {
    leaderboardSelected.clear();
    renderLeaderboardGrid(gridRoot);
    renderLeaderboardSelectedChips(selectedRoot);
    renderLeaderboardGraph(graphRoot);
  }

  function renderLeaderboardGraph(graphRoot) {
    const series = Array.from(leaderboardSelected.entries())
      .sort((a, b) => a[1].rank - b[1].rank)
      .map(([accountId, entry]) => {
        const rawPoints = leaderboardHistoryCache.get(accountId) || [];
        const points = rawPoints.map(([ts, rank]) => ({ ts, rank })).sort((a, b) => a.ts - b.ts);
        return { accountId, color: leaderboardColorFor(accountId), points };
      });

    if (series.length === 0) {
      graphRoot.innerHTML = '<p class="leaderboard-empty-message">Select accounts below to graph their rank over time.</p>';
      return;
    }

    const svg = buildLeaderboardGraphSvg(series);
    graphRoot.innerHTML = svg || '<p class="leaderboard-empty-message">No history data yet for the selected accounts.</p>';
  }

  
  
  function buildLeaderboardGraphSvg(seriesList) {
    const width = 760;
    const height = 340;
    const padLeft = 44;
    const padRight = 16;
    const padTop = 16;
    const padBottom = 34;
    const plotWidth = width - padLeft - padRight;
    const plotHeight = height - padTop - padBottom;

    const seriesWithPoints = seriesList.filter((s) => s.points.length > 0);
    if (seriesWithPoints.length === 0) return "";

    let minTs = Infinity;
    let maxTs = -Infinity;
    let worstRank = 1;
    for (const s of seriesWithPoints) {
      for (const p of s.points) {
        if (p.ts < minTs) minTs = p.ts;
        if (p.ts > maxTs) maxTs = p.ts;
        if (p.rank > worstRank) worstRank = p.rank;
      }
    }
    const tsSpan = Math.max(maxTs - minTs, 1);

    const buckets = [
      [10, 2],
      [50, 10],
      [100, 20],
      [250, 50],
      [500, 100],
    ];
    let bottom = 500;
    let step = 100;
    for (const [bucket, bucketStep] of buckets) {
      if (worstRank <= bucket) {
        bottom = bucket;
        step = bucketStep;
        break;
      }
    }

    const x = (ts) => padLeft + ((ts - minTs) / tsSpan) * plotWidth;
    const y = (rank) => padTop + ((Math.max(1, Math.min(bottom, rank)) - 1) / (bottom - 1)) * plotHeight;

    let yTicksSvg = "";
    const yTickValues = [1];
    for (let i = 1; i <= 5; i++) yTickValues.push(i * step);
    for (const rank of yTickValues) {
      const ty = y(rank).toFixed(1);
      yTicksSvg += `<text class="leaderboard-axis-text" x="${padLeft - 8}" y="${ty}" text-anchor="end" dominant-baseline="middle">${rank}</text>`;
    }

    let xTicksSvg = "";
    const tickCount = 6;
    for (let i = 0; i < tickCount; i++) {
      const tickTs = Math.round(minTs + (i * tsSpan) / (tickCount - 1));
      const tx = x(tickTs).toFixed(1);
      const label = escapeHtml(new Date(tickTs).toLocaleDateString(undefined, { month: "short", day: "numeric" }));
      xTicksSvg += `<text class="leaderboard-axis-text" x="${tx}" y="${height - padBottom + 18}" text-anchor="middle">${label}</text>`;
    }

    const linesSvg = seriesWithPoints
      .map((s) => {
        const pts = s.points.map((p) => `${x(p.ts).toFixed(1)},${y(p.rank).toFixed(1)}`).join(" ");
        return `<polyline class="leaderboard-line" style="stroke:${s.color}" points="${pts}"></polyline>`;
      })
      .join("");

    return `<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Fansly leaderboard rank over time">${yTicksSvg}${xTicksSvg}${linesSvg}</svg>`;
  }

  
  window.initIndex = initIndex;
  window.initCreatorPage = initCreatorPage;
  window.initInsights = initInsightsPage;
  window.initLeaderboard = initLeaderboardPage;
})();