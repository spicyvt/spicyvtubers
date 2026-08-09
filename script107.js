(() => {
  "use strict";

  const EXTENSION_URLS = {
    chrome: "https://chromewebstore.google.com/detail/spicy-vtubers/oohhdkpmeaeejcaojpccilpfebdbeeib", 
    firefox: "https://addons.mozilla.org/en-US/firefox/addon/spicyvtubers/",
  };

  const siteStats = document.getElementById("site-stats");

  const resultCountIcon = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm0 2c-3.31 0-8 1.66-8 4.5V21h16v-2.5c0-2.84-4.69-4.5-8-4.5zm8.34-9.94a4 4 0 0 1 0 7.88 5.5 5.5 0 0 1 0-7.88zM18.5 14c2.9.53 4.5 1.9 4.5 3.5V21h-3v-2.5c0-1.63-.6-2.9-1.5-4.5z"/></svg>`;

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
  // Index page: search/filter hides/shows the fixed set of rows baked into index.html by
  // generate-creator-pages.php — no fetch, no DOM insert/reorder, no pagination.
  function initIndex() {
    const tableSection = document.getElementById("table-section");
    const newestSection = document.getElementById("newest-section");
    const toolbar = document.querySelector(".toolbar");
    const thead = document.getElementById("creator-thead");
    const tbody = document.getElementById("creator-tbody");
    const newestTbody = document.getElementById("newest-tbody");
    const searchInput = document.getElementById("search-input");
    const searchClearBtn = document.getElementById("search-clear-btn");
    const resultCount = document.getElementById("result-count");
    const emptyState = document.getElementById("empty-state");
    const platformFilterEl = document.getElementById("platform-filter");
    const sortToggleBtn = document.getElementById("sort-toggle");
    const loadMoreWrap = document.getElementById("load-more-wrap");
    const loadMoreBtn = document.getElementById("load-more-btn");
    if (!tableSection || !newestSection || !thead || !tbody || !searchInput || !platformFilterEl || !sortToggleBtn || !loadMoreWrap || !loadMoreBtn) return;

    const rows = Array.from(tbody.querySelectorAll("tr"));
    const totalCount = rows.length;
    let platformFilter = "all";
    let isNewestView = false;
    // Only the first INITIAL_ROWS matches are unhidden at a time (index.html already bakes
    // rows past position 50 as hidden, mirroring this); Load More reveals LOAD_BATCH_SIZE more.
    const INITIAL_ROWS = 50;
    const LOAD_BATCH_SIZE = 200;
    let shownCount = INITIAL_ROWS;

    // Single reusable "View Profile" row shown below the sole row instead of
    // the header when exactly one result is showing (solo mode).
    let profileRow = null;
    function getProfileRow() {
      if (!profileRow) {
        profileRow = document.createElement("tr");
        profileRow.className = "profile-row";
        profileRow.hidden = true;
        const td = document.createElement("td");
        td.className = "profile-cell";
        td.colSpan = 2;
        td.innerHTML = `<a class="view-profile-link">View Profile</a>`;
        profileRow.appendChild(td);
        tbody.appendChild(profileRow);
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

    function updateResultCount(count, total) {
      const suffix = count === total ? "" : `<span class="result-count-total"> of ${total}</span>`;
      resultCount.innerHTML = `${resultCountIcon}${count}${suffix}`;
    }

    function rowMatches(row, query) {
      if (platformFilter !== "all" && !row.dataset.platforms.split(" ").includes(platformFilter)) return false;
      if (query && !row.dataset.search.includes(query)) return false;
      return true;
    }

    function updateSearchClearBtn() {
      searchClearBtn.hidden = searchInput.value.trim() === "";
    }

    // Applies the current search/platform match set to the fixed row list, revealing only
    // the first shownCount matches (the rest stay hidden until Load More raises shownCount).
    function applyVisibility() {
      const query = searchInput.value.trim().toLowerCase();
      const matchedRows = [];
      rows.forEach((row) => {
        row.classList.remove("is-profile-open");
        if (rowMatches(row, query)) matchedRows.push(row);
      });
      const shown = new Set(matchedRows.slice(0, shownCount));
      rows.forEach((row) => {
        row.hidden = !shown.has(row);
      });

      // Solo mode (exactly one result): hide the header and show a "View
      // Profile" row below it instead.
      const isSolo = matchedRows.length === 1;
      thead.hidden = isSolo;
      const profileRowEl = getProfileRow();
      profileRowEl.hidden = !isSolo;
      if (isSolo) {
        const soloRow = matchedRows[0];
        soloRow.classList.add("is-profile-open");
        profileRowEl.querySelector(".view-profile-link").href = soloRow.querySelector(".name-link").href;
      }

      emptyState.hidden = matchedRows.length !== 0;
      updateResultCount(matchedRows.length, totalCount);
      loadMoreWrap.hidden = matchedRows.length <= shownCount;
    }

    function update() {
      window.scrollTo({ top: 0, behavior: "smooth" }); // scroll to top when updating the list
      updateSearchClearBtn();
      shownCount = INITIAL_ROWS;
      applyVisibility();
    }

    loadMoreBtn.addEventListener("click", () => {
      shownCount += LOAD_BATCH_SIZE;
      applyVisibility();
    });

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

    // The header button now swaps between the search view and a separate static
    // "last 25 additions" table, instead of live re-sorting the same rows.
    sortToggleBtn.addEventListener("click", () => {
      isNewestView = !isNewestView;
      if (toolbar) toolbar.hidden = isNewestView;
      tableSection.hidden = isNewestView;
      newestSection.hidden = !isNewestView;
      sortToggleBtn.classList.toggle("is-active", isNewestView);
      sortToggleBtn.setAttribute("aria-pressed", String(isNewestView));
      sortToggleBtn.querySelector("span").textContent = isNewestView ? "All Creators" : "Newest";
      // load-more-wrap lives outside table-section, so it isn't hidden by the toggle above
      // and must be handled separately (recompute for the all-creators table when returning to it).
      if (isNewestView) {
        loadMoreWrap.hidden = true;
      } else {
        applyVisibility();
      }
    });

    // Avatar images are baked into the initial HTML (not inserted later by JS) and shown as
    // soon as they paint (no opacity fade/is-loaded class to juggle) — only a truly broken
    // image (network error) needs handling, and some may already have errored before this
    // listener attaches below, so check those up front too.
    function syncAvatarState(img) {
      if (img.complete && img.naturalWidth === 0) img.remove();
    }

    // Avatar error handling + row-click-to-navigate delegation, shared by both tables.
    function wireTable(body) {
      body.querySelectorAll(".avatar-img").forEach(syncAvatarState);
      body.addEventListener(
        "error",
        (e) => {
          if (e.target.classList?.contains("avatar-img")) e.target.remove();
        },
        true
      );
      // Clicking anywhere in a row (that isn't itself a link) navigates to that creator's page —
      // the row's only real <a> is the small avatar/name-link, so without this most of the row
      // (which still shows a pointer cursor) would do nothing when clicked.
      body.addEventListener("click", (e) => {
        if (e.target.closest("a")) return;
        const tr = e.target.closest("tr");
        const link = tr?.querySelector(".name-link, .view-profile-link");
        if (link) window.location.href = link.href;
      });
    }
    wireTable(tbody);
    if (newestTbody) wireTable(newestTbody);

    update();
    renderStats();
  }

  // Initialize creator pages — stats for now; future interactive creator-page bits go here.
  function initCreatorPage() {
    renderStats();
  }

  // Output the creators json data in a pretty-printed format shown below the cards on each creator page
  async function renderCreatorJson() {
    const jsonContainer = document.getElementById("creator-json-container");
    if (!jsonContainer) return;

    // Get creators name from the URL path, e.g. /c/creator-name/ -> creator-name
    const pathParts = window.location.pathname.split("/").filter(Boolean);
    if (pathParts.length < 2 || pathParts[0] !== "c") {
      jsonContainer.textContent = "Invalid creator page URL.";
      return;
    }
    window.creatorId = pathParts[1];
    
    // Only display if ?debug=1 is in the URL query string
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get("debug") !== "1") {
      return;
    }

    const response = await fetch(`/creators/${window.creatorId}.json`);
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const jsonData = await response.json();
    
    const prettyJson = JSON.stringify(jsonData, null, 2);    
    const pre = document.createElement("div");
    pre.textContent = prettyJson;
    jsonContainer.appendChild(pre);    
    jsonContainer.style.display = "block";
  }

  document.addEventListener("DOMContentLoaded", () => {
    renderCreatorJson();
  });

  // Exposed so each page's own bootstrap script can call these once script001.js has loaded
  window.initIndex = initIndex;
  window.initCreatorPage = initCreatorPage;
})();