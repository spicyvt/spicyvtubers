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

  const fanslyIcon = `<img src="fansly.svg" alt="" aria-hidden="true">`;
  const socialsIcon = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg>`;

  const onlyfansIcon = `<img src="onlyfans.svg" alt="" aria-hidden="true">`;
  const rplayIcon = `<img src="rplay.svg" alt="" aria-hidden="true">`;
  const joystickIcon = `<img src="joystick.svg" alt="" aria-hidden="true">`;
  const patreonIcon = `<svg viewBox="0 0 436 476" aria-hidden="true"><path fill="currentColor" d="M436 143c-.084-60.778-47.57-110.591-103.285-128.565C263.528-7.884 172.279-4.649 106.214 26.424 26.142 64.089.988 146.596.051 228.883c-.77 67.653 6.004 245.841 106.83 247.11 74.917.948 86.072-95.279 120.737-141.623 24.662-32.972 56.417-42.285 95.507-51.929C390.309 265.865 436.097 213.011 436 143Z"/></svg>`;
  const externalLinkIcon = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3h7v7h-2V6.41l-9.29 9.3-1.42-1.42 9.3-9.29H14V3zM5 5h6v2H7v10h10v-4h2v6H5V5z"/></svg>`;
  const resultCountIcon = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm0 2c-3.31 0-8 1.66-8 4.5V21h16v-2.5c0-2.84-4.69-4.5-8-4.5zm8.34-9.94a4 4 0 0 1 0 7.88 5.5 5.5 0 0 1 0-7.88zM18.5 14c2.9.53 4.5 1.9 4.5 3.5V21h-3v-2.5c0-1.63-.6-2.9-1.5-4.5z"/></svg>`;

  const SPICE_PLATFORMS = [
    { key: "fansly", label: "Fansly", icon: fanslyIcon, baseUrl: "https://fansly.com/", refKey: "fanslyRef" },
    { key: "onlyfans", label: "OnlyFans", icon: onlyfansIcon, baseUrl: "https://onlyfans.com/" },
    { key: "rplay", label: "Rplay", icon: rplayIcon, baseUrl: "https://rplay.live/c/", rootBaseUrl: "https://rplay.live/" },
    { key: "joystick", label: "joystick.tv", icon: joystickIcon, baseUrl: "https://joystick.tv/u/" },
    { key: "patreon", label: "Patreon", icon: patreonIcon, baseUrl: "https://www.patreon.com/" },
  ];


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

  function getInitials(str) {
    const parts = str.split(/[\s_-]+/).filter(Boolean);
    if (parts.length >= 2) {
      return (parts[0][0] + parts[1][0]).toUpperCase();
    }
    return str.charAt(0).toUpperCase();
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

  function buildRow(creator) {
    const tr = document.createElement("tr");

    const channelTd = document.createElement("td");
    channelTd.dataset.label = "Channel";
    channelTd.className = "name-cell";
    channelTd.innerHTML = creator.channel
      ? `<a class="name-link" href="${BASE_URL}c/${encodeURIComponent(creator.channelLower)}/">` +
        `<span class="avatar" data-initials="${escapeHtml(getInitials(creator.channel))}">` +
        `<img class="avatar-img" src="avatarsLarge/${encodeURIComponent(creator.channelLower)}.webp" alt="" decoding="async" loading="lazy">` +
        `</span>` +
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
        return pillLink("spice-pill", href, `${platform.label}: ${text}`, platform.key, text);
      })
      .join("");
    const otherPills = getOtherLinks(creator)
      .map(({ label, url }) => pillLink("spice-pill other-pill", escapeHtml(url), url, "", label))
      .join("");
    const xPills = creator.xHandles
      .map((handle) => pillLink("x-pill", profileLink("https://x.com/", handle), `Twitter: ${handle}`, "x", handle.toLowerCase()))
      .join("");
    const [bskyName, bskyLink] = Array.isArray(creator.bskyHandle) ? creator.bskyHandle : [];
    const bskyLabel = (bskyName || bskyLink || "").toLowerCase();
    const bskyPill = bskyLink
      ? pillLink("x-pill", profileLink("https://bsky.app/profile/", bskyLink), `Bluesky: ${bskyLabel}`, "bsky", bskyLabel)
      : "";
    spiceTd.innerHTML = `<div class="spice-handles">${spicePills}${otherPills}${xPills}${bskyPill}</div>`;

    tr.append(channelTd, spiceTd);
    return tr;
  }

  async function renderStats() {
    let stats = {};
    try {
      const response = await fetch("/data.json");
      if (response.ok) stats = await response.json();
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
    fetch("/data.json")
      .then((response) => (response.ok ? response.json() : null))
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

  // ---- Index criteria toggle (footer, shared markup on both page types) ----
  (() => {
    const criteriaToggleBtn = document.getElementById("criteria-toggle-btn");
    const criteriaContent = document.getElementById("criteria-content");
    if (!criteriaToggleBtn || !criteriaContent) return;

    criteriaToggleBtn.addEventListener("click", () => {
      const isHidden = criteriaContent.hidden;
      criteriaContent.hidden = !isHidden;
      criteriaToggleBtn.classList.toggle("expanded", isHidden);
      criteriaToggleBtn.setAttribute("aria-expanded", String(isHidden));
    });
  })();

  // ---- Index page: search/sort/filter/table — only ever set up on index.html ----
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
    if (!tableSection || !thead || !tbody || !searchInput || !platformFilterEl || !sortToggleBtn || !loadMoreWrap || !loadMoreBtn) return;

    let creators = [];
    let azOrderCreators = [];
    let jsonOrderCreators = [];
    let newestOrderCreators = [];
    let sortMode = "az"; // "az" | "newest"
    let platformFilter = "all";
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

    // Single reusable "View Profile" row shown below the sole row instead of
    // the header when exactly one result is showing (solo mode).
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

    //Cap to 50 rows initially to avoid long load times on mobile devices
    function renderInitialRows(data) {
      if (data.length === 0) {
        tbody.innerHTML = "";
        emptyState.hidden = false;
        thead.hidden = false;
        return;
      }
      emptyState.hidden = true;

      // Solo mode (exactly one result): hide the header, same as a search
      // narrowed down to a single creator used to do, and append a "View
      // Profile" row below it instead of the header row.
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
        if (query && !creator.searchText.includes(query)) return false;
        return true;
      });
    }

    function updateSearchClearBtn() {
      searchClearBtn.hidden = searchInput.value.trim() === "";
    }

    function update() {

      window.scrollTo({ top: 0, behavior: "smooth" }); // scroll to top when updating the list
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

    // Clicking anywhere in a row (that isn't itself a link) navigates to that creator's page —
    // the row's only real <a> is the small avatar/name-link, so without this most of the row
    // (which still shows a pointer cursor) would do nothing when clicked.
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
      try {
        const response = await fetch("/accounts.json");
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
      tableSection.hidden = false;
      if (siteFooter) siteFooter.hidden = false;
    }

    init();
    renderStats();
  }

  // Initialize creator pages — stats for now; future interactive creator-page bits go here.
  function initCreatorPage() {
    renderStats();
  }

  // Exposed so each page's own bootstrap script can call these once script001.js has loaded
  window.initIndex = initIndex;
  window.initCreatorPage = initCreatorPage;
})();