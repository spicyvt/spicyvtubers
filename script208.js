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

  function getAvatarFolder(creator) {
    return AVATAR_FOLDERS.has(creator.avatar) ? creator.avatar : "avatarsLarge";
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
    const avatarFolder = getAvatarFolder(creator);
    const avatarBaseName = getAvatarBaseName(creator, avatarFolder);
    channelTd.innerHTML = creator.channel
      ? `<a class="name-link" href="${BASE_URL}c/${encodeURIComponent(creator.channelLower)}/">` +
        `<span class="avatar">` +
        `<img class="avatar-img" src="${avatarFolder}/${encodeURIComponent(avatarBaseName)}.webp" alt="" decoding="async" loading="lazy">` +
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
    // A plain space (not a visible separator) between pill groups keeps
    // extracted/read text from running labels together; whitespace-only
    // text nodes between flex-item <a> tags aren't rendered as a visible
    // gap in this display:flex container, so this is invisible on-screen.
    spiceTd.innerHTML = `<div class="spice-handles">${[spicePills, otherPills, xPills, bskyPill].filter(Boolean).join(" ")}</div>`;

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
      if (isHidden && !criteriaContent.dataset.loaded) {
        // Criteria text lives in a <template> (not a real text node) until
        // now, so it's never present for crawlers/snippet builders to scrape.
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

    // Filter buttons are baked into index.html at generation time (see
    // buildPlatformFilterHtml() in generate-creator-pages.php) since the
    // platform list never changes at runtime.
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
    initFanslyGraphs();
    initFanslyStreamHistory();
  }

  // Fansly leaderboard section: clicking a month pill swaps which
  // pre-rendered SVG panel (baked server-side) is shown.
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

  const FANSLY_STREAMS_CDN_BASE = "https://cdn.spicyvtubers.com/fansly/streams/";
  const MIN_STREAM_DURATION_MS = 15 * 60 * 1000;
  const MAX_STREAM_DURATION_MS = 24 * 60 * 60 * 1000;

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

  // Fansly stream-history card: baked hidden server-side for creators who
  // have ever had a Fansly stream — only unhidden here once we actually
  // have rows to show, fetched from the R2-backed CDN feed written by the
  // stream-tracker Worker.
  async function initFanslyStreamHistory() {
    const container = document.getElementById("fansly-stream-history");
    if (!container) return;

    const card = container.closest(".creator-fansly-streams");
    const slug = container.dataset.slug;

    try {
      const res = await fetch(`${FANSLY_STREAMS_CDN_BASE}${encodeURIComponent(slug)}.json`);
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const rawStreams = await res.json();
      if (!Array.isArray(rawStreams)) throw new Error("Unexpected response shape");

      // Drop noise like brief test/disconnect "streams", and bogus/stuck-tracker entries that read as day(s)-long.
      const streams = rawStreams.filter(
        (stream) =>
          typeof stream.durationMs !== "number" ||
          (stream.durationMs >= MIN_STREAM_DURATION_MS && stream.durationMs <= MAX_STREAM_DURATION_MS)
      );

      if (streams.length === 0) {
        card?.remove();
        return;
      }

      // Most recent stream first.
      const rowsHtml = streams
        .slice()
        .reverse()
        .map((stream) => {
          const start = escapeHtml(formatStreamTimestamp(stream.startedAt));
          const finish = escapeHtml(formatStreamTimestamp(stream.lastFetchedAt));
          const duration = escapeHtml(formatStreamDuration(stream.durationMs));
          return `<tr><td>${start}</td><td>${finish}</td><td>${duration}</td></tr>`;
        })
        .join("");

      container.insertAdjacentHTML(
        "beforeend",
        `<table class="fansly-streams-table"><thead><tr><th>Start</th><th>Finish</th><th>Duration</th></tr></thead><tbody>${rowsHtml}</tbody></table>`
      );
      if (card) card.hidden = false;
    } catch (err) {
      // Couldn't obtain stream history (network/CORS/404) — leave the card hidden rather than show an error.
      card?.remove();
    }
  }

  // Exposed so each page's own bootstrap script can call these once script001.js has loaded
  window.initIndex = initIndex;
  window.initCreatorPage = initCreatorPage;
})();