/*
########################################
#                                      #
# Community Applications               #
# Copyright 2020-2026, Lime Technology #
# Copyright 2015-2026, Andrew Zawadzki #
#                                      #
# Licenced under GPLv2                 #
#                                      #
########################################
*/

function caInitializeClickHandlers() {
  function caInitFirefoxFixedHorizontalOverlay() {
    var selector = ".menuItems, .ca_homeTemplates, .mainArea, .sidenav";
    var overlays = new Map();
    var hideTimers = new WeakMap();
    var remPx = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
    var thumbLengthPx = 20 * remPx;
    var cssThickness = getComputedStyle(document.documentElement).getPropertyValue("--ca-fixed-scrollbar-thickness");
    var trackThicknessPx = parseFloat(cssThickness) || 10;

    var $root = $("#ca_fixed_scroll_root");
    if ($root.length === 0) {
      $root = $("<div>", { id: "ca_fixed_scroll_root" }).appendTo("body");
    }

    var clearHideTimer = function(el) {
      var t = hideTimers.get(el);
      if (t) {
        clearTimeout(t);
        hideTimers.delete(el);
      }
    };

    var showIndicator = function(el) {
      var entry = overlays.get(el);
      if (!entry) return;
      clearHideTimer(el);
      if (entry.$hIndicator && entry.$hIndicator.is(":visible")) entry.$hIndicator.addClass("visible");
      if (entry.$vIndicator && entry.$vIndicator.is(":visible")) entry.$vIndicator.addClass("visible");
    };

    var hideIndicatorSoon = function(el) {
      var entry = overlays.get(el);
      if (!entry) return;
      if (entry.dragging || entry.overlayHover || $(el).is(":hover")) return;
      clearHideTimer(el);
      hideTimers.set(el, setTimeout(function() {
        // Don't hide if user is still interacting/hovering the scroll target.
        if (entry.dragging || entry.overlayHover || $(el).is(":hover")) return;
        if (entry.$hIndicator) entry.$hIndicator.removeClass("visible");
        if (entry.$vIndicator && !entry.alwaysShowVertical) entry.$vIndicator.removeClass("visible");
      }, 250));
    };

    var updateIndicator = function(el) {
      var entry = overlays.get(el);
      if (!entry) return;

      var rect = el.getBoundingClientRect();
      var hasHorizontal = (el.scrollWidth - el.clientWidth) > 1;
      var hasVertical = (el.scrollHeight - el.clientHeight) > 1;
      if (rect.width <= 0 || rect.height <= 0) {
        if (entry.$hIndicator) entry.$hIndicator.hide();
        if (entry.$vIndicator) entry.$vIndicator.hide();
        return;
      }

      if (entry.$hIndicator) {
        if (!hasHorizontal) {
          entry.$hIndicator.hide();
        } else {
          entry.$hIndicator
            .show()
            .css({
              left: rect.left + "px",
              top: (rect.bottom - trackThicknessPx) + "px",
              width: rect.width + "px"
            });

          var trackWidth = rect.width;
          var thumbWidth = Math.min(trackWidth, thumbLengthPx);
          var hScrollable = el.scrollWidth - el.clientWidth;
          var hRatio = hScrollable > 0 ? (el.scrollLeft / hScrollable) : 0;
          var hMaxLeft = Math.max(0, trackWidth - thumbWidth);
          var hLeft = Math.max(0, Math.min(hMaxLeft, hMaxLeft * hRatio));

          entry.$hThumb.css({
            width: thumbWidth + "px",
            transform: "translateX(" + hLeft + "px)"
          });
        }
      }

      if (entry.$vIndicator) {
        if (!hasVertical) {
          entry.$vIndicator.hide();
        } else {
          entry.$vIndicator.show();
          if (entry.alwaysShowVertical) {
            entry.$vIndicator.css("left", (window.innerWidth - trackThicknessPx) + "px");
          } else {
            entry.$vIndicator.css("left", (rect.right - trackThicknessPx) + "px");
          }
          entry.$vIndicator.css({
            top: rect.top + "px",
            height: rect.height + "px"
          });

          var trackHeight = rect.height;
          var thumbHeight = Math.min(trackHeight, thumbLengthPx);
          var vScrollable = el.scrollHeight - el.clientHeight;
          var vRatio = vScrollable > 0 ? (el.scrollTop / vScrollable) : 0;
          var vMaxTop = Math.max(0, trackHeight - thumbHeight);
          var vTop = Math.max(0, Math.min(vMaxTop, vMaxTop * vRatio));

          entry.$vThumb.css({
            height: thumbHeight + "px",
            transform: "translateY(" + vTop + "px)"
          });
        }
      }
    };

    var attachOverlay = function(el) {
      if (overlays.has(el)) return;

      var hasHorizontal = (el.scrollWidth - el.clientWidth) > 1;
      var hasVertical = (el.scrollHeight - el.clientHeight) > 1;
      // Sidebar should never show the horizontal overlay scrollbar.
      var allowHorizontal = !el.classList.contains("sidenav");
      var allowVertical = true;
      if ((!allowHorizontal || !hasHorizontal) && (!allowVertical || !hasVertical)) return;

      var $hIndicator = null;
      var $hThumb = null;
      if (allowHorizontal && hasHorizontal) {
        $hIndicator = $("<div>", { "class": "ca_fixed_hscroll_indicator" });
        $hThumb = $("<div>", { "class": "ca_fixed_hscroll_thumb" }).appendTo($hIndicator);
        $hIndicator.appendTo($root);
      }

      var $vIndicator = null;
      var $vThumb = null;
      if (allowVertical && hasVertical) {
        $vIndicator = $("<div>", { "class": "ca_fixed_vscroll_indicator" });
        $vThumb = $("<div>", { "class": "ca_fixed_vscroll_thumb" }).appendTo($vIndicator);
        $vIndicator.appendTo($root);
      }

      overlays.set(el, {
        $hIndicator: $hIndicator,
        $hThumb: $hThumb,
        $vIndicator: $vIndicator,
        $vThumb: $vThumb,
        overlayHover: false,
        dragging: false,
        alwaysShowVertical: el.classList.contains("mainArea") || el.classList.contains("sidenav"),
        mainAreaScrollFxTimer: null
      });
      var entry = overlays.get(el);
      if (el.classList.contains("mainArea")) {
        if (entry.$hIndicator) entry.$hIndicator.addClass("ca_scroll_mainarea");
        if (entry.$vIndicator) entry.$vIndicator.addClass("ca_scroll_mainarea");
      } else if (el.classList.contains("sidenav")) {
        if (entry.$hIndicator) entry.$hIndicator.addClass("ca_scroll_sidenav");
        if (entry.$vIndicator) entry.$vIndicator.addClass("ca_scroll_sidenav");
      }
      // Used to disable non-sidenav overlays when the sidebar is open.
      if (!el.classList.contains("sidenav")) {
        if (entry.$hIndicator) entry.$hIndicator.addClass("ca_scroll_nonsidenav");
        if (entry.$vIndicator) entry.$vIndicator.addClass("ca_scroll_nonsidenav");
      }
      // Allow targeted control of the menu scroll indicators (eg. hide during Awesomplete dropdown).
      if (el.classList.contains("menuItems")) {
        if (entry.$hIndicator) entry.$hIndicator.addClass("ca_scroll_menuitems");
        if (entry.$vIndicator) entry.$vIndicator.addClass("ca_scroll_menuitems");
      }
      if (entry && entry.alwaysShowVertical) {
        if (entry.$vIndicator) entry.$vIndicator.addClass("ca_mainarea_v_always visible");
      }
      $(el).addClass("ca_custom_scroll_target");

      var startDrag = function(axis, downEvent) {
        downEvent.preventDefault();
        downEvent.stopPropagation();
        showIndicator(el);
        var entry = overlays.get(el);
        if (!entry) return;
        entry.dragging = true;

        var rect = el.getBoundingClientRect();
        var startX = downEvent.clientX;
        var startY = downEvent.clientY;
        var startScrollLeft = el.scrollLeft;
        var startScrollTop = el.scrollTop;
        var prevUserSelect = document.body.style.userSelect;
        document.body.style.userSelect = "none";

        if (axis === "x" && entry.$hThumb) entry.$hThumb.addClass("dragging");
        if (axis === "y" && entry.$vThumb) entry.$vThumb.addClass("dragging");

        var onMove = function(moveEvent) {
          if (axis === "x") {
            var trackWidth = rect.width;
            var thumbWidth = Math.min(trackWidth, thumbLengthPx);
            var hMaxLeft = Math.max(0, trackWidth - thumbWidth);
            var hScrollable = Math.max(0, el.scrollWidth - el.clientWidth);
            if (hMaxLeft > 0 && hScrollable > 0) {
              var deltaX = moveEvent.clientX - startX;
              var scrollDeltaX = deltaX * (hScrollable / hMaxLeft);
              el.scrollLeft = startScrollLeft + scrollDeltaX;
            }
          } else {
            var trackHeight = rect.height;
            var thumbHeight = Math.min(trackHeight, thumbLengthPx);
            var vMaxTop = Math.max(0, trackHeight - thumbHeight);
            var vScrollable = Math.max(0, el.scrollHeight - el.clientHeight);
            if (vMaxTop > 0 && vScrollable > 0) {
              var deltaY = moveEvent.clientY - startY;
              var scrollDeltaY = deltaY * (vScrollable / vMaxTop);
              el.scrollTop = startScrollTop + scrollDeltaY;
            }
          }
          updateIndicator(el);
        };

        var onUp = function() {
          $(document).off("mousemove.caScrollOverlay", onMove);
          $(document).off("mouseup.caScrollOverlay", onUp);
          document.body.style.userSelect = prevUserSelect;
          var currentEntry = overlays.get(el);
          if (currentEntry) currentEntry.dragging = false;
          if (currentEntry && currentEntry.$hThumb) currentEntry.$hThumb.removeClass("dragging");
          if (currentEntry && currentEntry.$vThumb) currentEntry.$vThumb.removeClass("dragging");
          hideIndicatorSoon(el);
        };

        $(document).on("mousemove.caScrollOverlay", onMove);
        $(document).on("mouseup.caScrollOverlay", onUp);
      };

      if (entry.$hThumb) entry.$hThumb.on("mousedown", function(e) { startDrag("x", e); });
      if (entry.$vThumb) entry.$vThumb.on("mousedown", function(e) { startDrag("y", e); });
      if (entry.$hIndicator) entry.$hIndicator.on("mousedown", function(e) {
        if (entry.$hThumb && e.target === entry.$hThumb[0]) return;
        e.preventDefault();
        e.stopPropagation();
        showIndicator(el);

        var rect = el.getBoundingClientRect();
        var trackWidth = rect.width;
        var thumbWidth = Math.min(trackWidth, thumbLengthPx);
        var hMaxLeft = Math.max(0, trackWidth - thumbWidth);
        var hScrollable = Math.max(0, el.scrollWidth - el.clientWidth);
        if (hMaxLeft > 0 && hScrollable > 0) {
          var clickX = Math.max(0, Math.min(trackWidth, e.clientX - rect.left));
          var left = Math.max(0, Math.min(hMaxLeft, clickX - (thumbWidth / 2)));
          el.scrollLeft = (left / hMaxLeft) * hScrollable;
          updateIndicator(el);
        }
      });
      if (entry.$vIndicator) entry.$vIndicator.on("mousedown", function(e) {
        if (entry.$vThumb && e.target === entry.$vThumb[0]) return;
        e.preventDefault();
        e.stopPropagation();
        showIndicator(el);

        var rect = el.getBoundingClientRect();
        var trackHeight = rect.height;
        var thumbHeight = Math.min(trackHeight, thumbLengthPx);
        var vMaxTop = Math.max(0, trackHeight - thumbHeight);
        var vScrollable = Math.max(0, el.scrollHeight - el.clientHeight);
        if (vMaxTop > 0 && vScrollable > 0) {
          var clickY = Math.max(0, Math.min(trackHeight, e.clientY - rect.top));
          var top = Math.max(0, Math.min(vMaxTop, clickY - (thumbHeight / 2)));
          el.scrollTop = (top / vMaxTop) * vScrollable;
          updateIndicator(el);
        }
      });
      [entry.$hIndicator, entry.$vIndicator].filter(Boolean).forEach(function($indicator) {
        $indicator.on("mouseenter", function() {
          var entry = overlays.get(el);
          if (!entry) return;
          entry.overlayHover = true;
          showIndicator(el);
        });
        $indicator.on("mouseleave", function() {
          var entry = overlays.get(el);
          if (!entry) return;
          entry.overlayHover = false;
          hideIndicatorSoon(el);
        });
      });

      $(el).on("scroll", function() {
        var current = overlays.get(el);
        if (!current) return;
        updateIndicator(el);
        if (current.alwaysShowVertical && current.$vIndicator) {
          current.$vIndicator.addClass("ca_mainarea_v_scrolling");
          if (current.mainAreaScrollFxTimer) clearTimeout(current.mainAreaScrollFxTimer);
          current.mainAreaScrollFxTimer = setTimeout(function() {
            var latest = overlays.get(el);
            if (!latest || !latest.$vIndicator) return;
            latest.$vIndicator.removeClass("ca_mainarea_v_scrolling");
          }, 450);
        }
        if (current.dragging || current.overlayHover || $(el).is(":hover")) showIndicator(el);
        hideIndicatorSoon(el);
      });
      $(el).on("mouseenter", function() {
        updateIndicator(el);
        showIndicator(el);
      });
      $(el).on("mouseleave", function() {
        hideIndicatorSoon(el);
      });
      updateIndicator(el);
    };

    var refreshTargets = function() {
      $(selector).each(function() { attachOverlay(this); });
      overlays.forEach(function(_, el) {
        if (!$.contains(document.body, el) || !$(el).is(selector)) {
          var entry = overlays.get(el);
          if (entry) {
            if (entry.$hIndicator) entry.$hIndicator.remove();
            if (entry.$vIndicator) entry.$vIndicator.remove();
          }
          overlays.delete(el);
        }
      });
      overlays.forEach(function(_, el) { updateIndicator(el); });
    };

    window.addEventListener("resize", refreshTargets);
    window.addEventListener("scroll", function() {
      overlays.forEach(function(_, el) { updateIndicator(el); });
    }, true);
    var refreshQueued = false;
    var queueRefresh = function() {
      if (refreshQueued) return;
      refreshQueued = true;
      requestAnimationFrame(function() {
        refreshQueued = false;
        refreshTargets();
      });
    };
    var domObserver = new MutationObserver(queueRefresh);
    domObserver.observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ["class"]
    });

    refreshTargets();
    setTimeout(refreshTargets, 250);
    setTimeout(refreshTargets, 1200);
  }

  caInitFirefoxFixedHorizontalOverlay();

  if (window.caEnableLegacyExternalLinkGuard) {
    $("body").on("click", "a,.ca_href,.dockerPopup", function(e) {
      var dockerHub = false;
      var ca_href = false;
      var href;
      var target;
      if ($(this).hasClass("ca_href")) {
        ca_href = true;
        href = $(this).attr("data-href");
        target = $(this).attr("data-target");
      } else if ($(this).hasClass("dockerPopup")) {
        href = $(this).data("dockerhub");
        target = "_blank";
        dockerHub = true;
      } else {
        href = $(this).attr("href");
        target = $(this).attr("target");
      }
      if (!href) return;
      href = href.trim();
      if (href.match("https?://[^\\.]*.(my)?unraid.net/") || href.indexOf("https://unraid.net/") === 0 || href === "https://unraid.net" || href.indexOf("http://lime-technology.com") === 0) {
        if (ca_href) {
          e.stopPropagation();
          e.preventDefault();
          window.open(href, target);
        }
        return;
      }
      if (href === "#" || href.indexOf("javascript") === 0) return;
      var dom = isValidURL(href);
      if (dom === false) {
        if (href.indexOf("/") === 0) return;
        var baseURLpage = href.split("/");
        if (typeof gui_pages_available !== "undefined" && gui_pages_available.includes(baseURLpage[0])) return;
      }
      if ($(this).hasClass("localURL")) return;
      var domainsAllowed;
      try { domainsAllowed = JSON.parse($.cookie("allowedDomains")); } catch (err) { domainsAllowed = {}; }
      $.cookie("allowedDomains", JSON.stringify(domainsAllowed), { expires: 3650 });
      if (dom && domainsAllowed[dom.hostname]) {
        if (dockerHub) {
          var popupOpen = window.open(href, target);
          if (!popupOpen || popupOpen.closed || typeof popupOpen == "undefined") {
            var popupWarning = addBannerWarning(tr("Popup Blocked."));
            setTimeout(function() { removeBannerWarning(popupWarning); }, 10000);
          }
        }
        return;
      }
      e.preventDefault();
      var host = dom && dom.hostname ? dom.hostname : "";
      swal({
        title: tr("External Link"),
        text: "<span title='" + href + "'>" + tr("Clicking OK will take you to a 3rd party website not associated with Limetech") + "<br><br><b>" + href + "<br><br><input id='Link_Always_Allow' type='checkbox'></input>" + tr("Always Allow") + " " + host + "</span>",
        html: true,
        type: "warning",
        showCancelButton: true,
        showConfirmButton: true,
        cancelButtonText: tr("Cancel"),
        confirmButtonText: tr("OK")
      }, function(isConfirm) {
        if (!isConfirm) return;
        if ($("#Link_Always_Allow").is(":checked") && host) {
          domainsAllowed[host] = true;
          $.cookie("allowedDomains", JSON.stringify(domainsAllowed), { expires: 3650 });
        }
        var popupOpen = window.open(href, target);
        if (!popupOpen || popupOpen.closed || typeof popupOpen == "undefined") {
          var popupWarning = addBannerWarning(tr("Popup Blocked."));
          setTimeout(function() { removeBannerWarning(popupWarning); }, 10000);
        }
      });
    });
  }

  $("body").on("click mousedown", "#ca_homeSectionSubtitle, #ca_homeSearchSubtitle", function(e) {
    e.stopPropagation();
  });

  $(".showMenuButton").on("click", function() { showMenu(); });
  $(".closeMenuButton,.menuOverlay").on("click", function() { closeMenu(); });
  $(".mobileOverlay").on("click", function() { closeMenu(); });
  $(".sidebarClose").on("click", function() {
    if ($(".moderationContainer").length) 
      showStatistics(); 
    else if ( ! $(".popUpBack").hasClass("ca_hide")) 
      $(".popUpBack").click();
    else closeSidebar();
  });
  $(".mainArea").on("click", ".actionsButtonContext,.actionsButton,.supportButton,.supportButtonCardContext,.ca_multiselect", function() {
    data.actions = true;
  });
  /* Keep focus in #searchBox when clicking the icon again (mousedown would otherwise blur and trigger collapse). */
  $(".searchSubmit").on("mousedown", function(e) {
    e.preventDefault();
  });
  $(".searchSubmit").on("click", function() {
    var $f = $("#searchFilter");
    if ($f.hasClass("ca_searchInputCollapsed") && !$.trim($("#searchBox").val())) {
      $f.removeClass("ca_searchInputCollapsed");
      $("#searchBox").trigger("focus");
      return;
    }
    doSearch(true);
  });
  $(".caChangeLog").on("click", function() { disableSort(); scrollToTop(); caChangeLog(); });
  $(".mainArea").on("click", ".ca_multiselect", function() { enableMultiInstall(); });
  $("#sidenavContent").on("click", ".pinPopup", function() { pinApp(this, $(this).data("repository"), $(this).data("name")); });
  $(".mainArea").on("click", ".ca_favouriteRepo", function() {
    $(".ca_fav").removeClass("ca_favouriteRepo").addClass("ca_non_favouriteRepo");
    $(".ca_holderFav").removeClass("ca_holderFav");
    $(this).removeClass("ca_favouriteRepo").addClass("ca_non_favouriteRepo");
    setToolTipForFavourite();
    post({ action: "toggleFavourite", repository: "" }, function() {
      clearTimeout(repoBannerTimer);
      if (repoBanner !== false) removeBannerWarning(repoBanner);
      repoBanner = addBannerWarning(tr("Removed favourite repository"), false, true);
      repoBannerTimer = setTimeout(function() {
        removeBannerWarning(repoBanner);
        repoBanner = false;
      }, 5000);
      setFavRepoSearch();
    });
  });
  $("body").on("click", ".fav,.nonfav", function() { setFavourite(this); });
  $("body").on("click", ".ca_repoSearch,.ca_repoSearchPopUp", function() {
    caClearHomeSectionSubtitle();
    closeSidebar();
    var repo = $(this).data("repository");
    var sortButton = false;
    $(".sortIcons").each(function() { if ($(this).hasClass("enabledIcon") && (!$(this).hasClass("startupMore"))) sortButton = true; });
    if (!sortButton) {
      $(".sortIcons").removeClass("enabledIcon").removeClass("startupMore");
      post({ action: "defaultSortOrder" }, function() { $("#defaultSort").addClass("enabledIcon"); doSearch(false, repo); });
    } else doSearch(false, repo);
  });
  $(".favouriteRepo").on("click", function() {
    if ($(this).hasClass("caMenuDisabled")) return;
    var repo = $(this).attr("data-repository");
    caClearHomeSectionSubtitle();
    var sortButton = false;
    $(".sortIcons").each(function() { if ($(this).hasClass("enabledIcon") && (!$(this).hasClass("startupMore"))) sortButton = true; });
    if (!sortButton) {
      $(".sortIcons").removeClass("enabledIcon").removeClass("startupMore");
      post({ action: "defaultSortOrder" }, function() { $("#defaultSort").addClass("enabledIcon"); doSearch(false, repo); });
    } else doSearch(false, repo);
  });

  $("body").on("click", ".templateSearch", function() { caClearHomeSectionSubtitle(); doSearch(false); });
  $("body").on("click", ".pageNavClick", function(e) {
    e.preventDefault();
    if ($(this).hasClass("pageNavNoClick")) return;
    if (window.getSelection) window.getSelection().removeAllRanges();
    var page = parseInt($(this).data("page"), 10);
    if (!page || page < 1) return;
    var pageFunction = $(this).data("pageFunction");
    if (pageFunction === "dockerSearch") dockerSearch(String(page));
    else changePage(String(page));
  });
  $("body").on("mousedown", ".pageNavClick", function(e) {
    e.preventDefault();
  });
  $("body").on("click", ".xmlInstall", function() {
    var type = $(this).data("type");
    var xml = $(this).data("xml");
    saveState();
    post({ action: "createXML", xml: xml, type: type }, function(result) {
      if (result.status == "ok") {
        if (type == "second") type = "default";
        openNewWindow("/Apps/AddContainer?xmlTemplate=" + type + ":" + xml);
      }
    });
  });
  $("body").on("click", ".pluginInstall", function() { installPlugin($(this).data("url"), $(this).data("update") ? true : false); });
  $("body").on("click", ".uninstallApp", function() {
    var type = $(this).data("type");
    var name = $(this).data("name");
    var app = $(this).data("app");
    if (type === "docker") 
      uninstallDocker(app, name); 
    else 
      uninstallApp(app, name);
  });
  $("body").on("click", ".repoPopup,.ca_repoinfo,.ca_repoFromPopUp,.cardDescriptionRepo", function(e) {
    e.stopPropagation();
    var repository = $(this).data("repository") ? $(this).data("repository") : $(this).closest(".ca_holder").data("repository");
    showRepoPopup(repository);
  });
  $("body").on("click", ".ca_holder", function(e) {
    if (data.actions) { data.actions = false; return; }
    data.actions = false;
    e.stopPropagation();
    if ($(this).hasClass("ca_repoPopup")) {
      showRepoPopup($(this).data("repository"));
      return;
    }
    if ($(this).hasClass("dockerCardBackground") || $(this).hasClass("noClick")) return;
    if ($(".dropdown-menu").is(":visible")) { $(".dropdown-menu").hide(); return; }
    var apppath = $(this).data("apppath");
    var appname = stripTags($(this).data("appname"));
    if (!apppath || !appname) return;
    showSidebarApp(apppath, appname);
  });
  $("body").on("click", ".caMenuItem", function(e) {
    if ($(this).hasClass("caMenuDisabled") || $(this).hasClass("selectedMenu")) return;
    if ($(this).hasClass("showStatistics")) { e.stopPropagation(); showStatistics(); }
    else if ($(this).hasClass("showSettings")) { e.stopPropagation(); showSettings(); }
    else if ($(this).hasClass("showCredits")) { e.stopPropagation(); showCredits(); }
    else { scrollToTop(); closeMenu(); }
  });
  $(".menuItems").on("click", ".categoryMenu", function() {
    var menu = this;
    if ($(menu).hasClass("caMenuDisabled") || $(menu).hasClass("selectedMenu")) return;
    caClearHomeSectionSubtitle();
    if (!data.searchFlag) {
      $("#searchBox").val("");
      data.committedSearchFilter = "";
      caSyncSearchFilterCollapsed();
      caSyncHomeSearchSubtitle();
    }
    showSortIcons();
    scrollToTop();
    var sortButton = false;
    $(".sortIcons").each(function() { if ($(this).hasClass("enabledIcon") && (!$(this).hasClass("startupMore"))) sortButton = true; });
    if (!sortButton) {
      $(".sortIcons").removeClass("enabledIcon").removeClass("startupMore");
      post({ action: "defaultSortOrder" }, function() { $("#defaultSort").addClass("enabledIcon"); changeCategory(menu, false); });
    } else changeCategory(menu, false);
  });
  $(".sidebar").on("click", ".chartMenu", function() {
    if ($(this).hasClass("selectedMenu")) return;
    $(".chartMenu").removeClass("selectedMenu");
    $(this).addClass("selectedMenu");
    $(".caChart").hide();
    $("#" + $(this).data("chart")).show();
  });
  $(".sidebar").on("click", ".popUpBack", function() { showSidebarApp($.cookie("sidebarAppPath"), $.cookie("sidebarAppName")); });
  $("body").on("click", ".caMenuItem", function() {
    if ($(this).hasClass("caMenuDisabled") || $(this).hasClass("noSelect") || $(this).attr("onclick")) return;
    if (!$(this).hasClass("startupButton")) {
      caClearHomeSectionSubtitle();
    }
    $(".caRepositoryMenu").addClass("caMenuEnabled").removeClass("caMenuDisabled");
    var slideFlag = true;
    var currentCat = $(".selectedMenu").data("category");
    var newCat = $(this).data("category");
    if (currentCat && currentCat.startsWith(newCat)) slideFlag = false;
    if (!$(this).hasClass("caRepositoryMenu")) {
      $(".caMenuItem").removeClass("selectedMenu");
      if (!$(this).parent().hasClass("subCategory") && slideFlag) $(".subCategory").hide("fast");
    }
    if ($(this).hasClass("caRepositoryMenu") && $(".startupButton").hasClass("selectedMenu")) {
      $(".startupButton").removeClass("selectedMenu");
      $(".allApps").addClass("selectedMenu");
    }
    $(this).addClass("selectedMenu");
    if (slideFlag && !$(this).parent().hasClass("actionCentre")) $(this).next().show("fast");
  });
  $("body").on("click", ".sectionMenu", function() {
    if ($(this).hasClass("caMenuDisabled")) return;
    caClearHomeSectionSubtitle();
    var section = $(this).attr("data-category");
    showSortIcons();
    switch (section) {
      case "installed_apps": data.previousAppsSection = ""; previousApps(true); break;
      case "inst_docker": data.previousAppsSection = "docker"; previousApps(true, false, "docker"); break;
      case "inst_plugins": data.previousAppsSection = "plugins"; previousApps(true, false, "plugins"); break;
      case "previous_apps": data.previousAppsSection = ""; previousApps(false); break;
      case "prev_docker": data.previousAppsSection = "docker"; previousApps(false, true, "docker"); break;
      case "prev_plugins": data.previousAppsSection = "plugins"; previousApps(false, true, "plugins"); break;
      case "action_centre": data.previousAppsSection = ""; actionCentre(); break;
      case "pinned_apps": pinnedApps(); break;
    }
  });
  $(".mainArea").on("click", ".homeMore", function() {
    var description = $(this).data("des");
    var category = $(this).data("category");
    var menuItem = $.find(".caMenuItem[data-category='" + category + "']");
    $(".caMenuItem").removeClass("selectedMenu");
    $(menuItem).addClass("selectedMenu");
    var sortOrder = {};
    if ($(this).data("sortby")) {
      sortOrder.sortBy = $(this).data("sortby");
      sortOrder.sortDir = $(this).data("sortdir");
      $(".sortIcons").removeClass("enabledIcon").addClass("startupMore");
    }
    caSetHomeSectionSubtitle(description);
    $("#searchBox").val("");
    data.committedSearchFilter = "";
    caSyncSearchFilterCollapsed();
    caSyncHomeSearchSubtitle();
    scrollToTop();
    post({ action: "changeSortOrder", sortOrder: sortOrder }, function() { getContent(false, category, description, false); });
  });
  $("body").on("click", ".debugging", function() {
    var tzoffset = (new Date()).getTimezoneOffset() * 60000;
    var localISOTime = (new Date(Date.now() - tzoffset)).toISOString().slice(0, -1);
    var filename = "CA-Logging-" + localISOTime.substr(0, 16).replace(/[-:]/g, "").replace("T", "-") + ".zip";
    post({ action: "downloadDebugging", file: filename }, function(result) {
      if (result && result.zip) {
        location = result.zip;
      }
    });
  });
  $(".dockerSearch").click(function() { caClearHomeSectionSubtitle(); initDockerSearch(); });
  $(".multi_installClear").click(function() { clearMultiInstall(); });
  $(".multi_deleteButton").click(function() { deleteMulti(); });
  $(".multi_installAll").click(function() { selectAllPrevious(); enableMultiInstall(); });
  $("body").on("click", ".startupButton", function() { window.location.reload(); });
  $(".multi_installButton").click(function() { if ($(".multi_installButton").hasClass("actionCenter")) updateMulti(); else installMulti(); });
  $(".sortIcons").click(function() {
    $(".sortIcons").removeClass("enabledIcon");
    $(this).addClass("enabledIcon");
    data.currentpage = 1;
    data.searchActive = false;
    var sortOrder = { sortBy: $(this).attr("data-sortBy"), sortDir: $(this).attr("data-sortDir") };
    post({ action: "changeSortOrder", sortOrder: sortOrder }, function() { changeSortOrder(); });
  });
  $("body").on("click", ".languageSwitch", function() { CAswitchLanguage($(this).data("language")); });
  $("body").on("click", ".languageInstall", function() { installLanguage($(this).data("language_xml"), $(this).data("language")); });
  $("body").on("click", ".languageRemove", function() { removeLanguage($(this).data("language")); });
  $("body").on("click", ".languageUpdate", function() { updateLanguage($(this).data("language")); });
  $("body").on("click", ".popUpClose", function() { closeSidebar(); });
  $("body").on("click", ".popUpStat", function() { showStatistics(); });
  $("body").on("click", ".similarSearch", function() { doSearch(false, $(this).data("search")); });
  $("body").on("click", ".removeApp", function() { removeApp($(this).data("path"), $(this).data("name")); });
}

function caInitializeEventHandlers() {
  window.addEventListener("error", function(event) {
    var target = event.target;
    if (target && target.tagName === "IMG") {
      if (target.classList.contains("spotlightIcon")) {
        target.src = window.caSpotlightIconBackup || "/plugins/dynamix.docker.manager/images/question.png";
      } else {
        target.src = "/plugins/dynamix.docker.manager/images/question.png";
      }
      target.onerror = null;
    }
  }, true);

  window.onerror = function(msg, url, lineNo, columnNo, error) {
    post({ action: "javascriptError", msg: msg, url: url, lineNo: lineNo, columnNo: columnNo, error: error });
  };

  if (window.caResponsiveOS) {
    var resizeDebounce = function(func, wait) {
      var timeout;
      return function() {
        var args = arguments;
        var later = function() {
          clearTimeout(timeout);
          func.apply(null, args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
      };
    };
    var resizeDebouncedOrientationHandler = resizeDebounce(function() {
      saveState(false);
      data.ignoreUnload = true;
      window.location.replace("/Apps/Apps");
    }, 100);
    var debouncedOrientationHandler = resizeDebounce(function() {
      resizeDebouncedOrientationHandler();
    }, 300);
    $(window).on("orientationchange", function() {
      setTimeout(debouncedOrientationHandler, 100);
    });
  }

  $("#searchBox").on("input", function() {
    if (!$("#searchBox").val()) {
      $("#searchButton").addClass("fa-search").removeClass("fa-remove");
    } else {
      $("#searchButton").addClass("fa-remove").removeClass("fa-search");
    }
    caSyncSearchFilterCollapsed();
  });

  $("#searchBox").on("focus", function() {
    $("#searchFilter").removeClass("ca_searchInputCollapsed");
  });

  $("#searchFilter").on("focusout", function(e) {
    var rt = e.relatedTarget;
    if (rt && $(rt).closest(".awesomplete").length) return;
    setTimeout(function() {
      var el = document.getElementById("searchFilter");
      if (!el) return;
      var active = document.activeElement;
      if (active && (el.contains(active) || $(active).closest(".awesomplete").length)) return;
      caSyncSearchFilterCollapsed();
    }, 150);
  });

  $("#mobileMenu").on("mousedown", function() {
    caRestoreCommittedSearchIfDrafted();
  });

  $("#searchBox").keydown(function(e) {
    if (e.which === 13) {
      e.stopPropagation();
      searchBoxAwesomplete.close();
      var sortButton = false;
      $(".sortIcons").each(function() {
        if ($(this).hasClass("enabledIcon") && (!$(this).hasClass("startupMore"))) sortButton = true;
      });
      if (!sortButton) {
        $(".sortIcons").removeClass("enabledIcon").removeClass("startupMore");
        post({ action: "defaultSortOrder" }, function() {
          $("#defaultSort").addClass("enabledIcon");
          doSearch();
        });
      } else doSearch();
    }
  });

  $("body").keydown(function(e) {
    switch (e.which) {
      case 27:
        if ($(".sidenav").hasClass("sidenavShow")) {
          e.preventDefault();
          e.stopPropagation();
          if ( ! $(".popUpBack").hasClass("ca_hide") ) {
            $(".popUpBack").click();
            return;
          }
          if ( $(".moderationContainer").length > 0 ) {
            showStatistics();
            return;
          }
          closeSidebar();
          return;
        }
        if ($(".menuOverlay").is(":visible")) {
          e.preventDefault();
          e.stopPropagation();
          closeMenu();
          return;
        }
        if ($("#searchBox").is(":focus") && !$("#searchBox").prop("disabled")) {
          e.preventDefault();
          e.stopPropagation();
          if ($("#searchButton").hasClass("fa-remove")) $(".searchSubmit").trigger("click");
          else {
            $("#searchBox").val("");
            data.committedSearchFilter = "";
            caSyncSearchFilterCollapsed();
            caSyncHomeSearchSubtitle();
          }
          return;
        }
        break;
      case 37:
        if ($(".sidenav").hasClass("sidenavShow") || $(".menuOverlay").is(":visible")) return;
        if (!$(".pageLeft").hasClass("pageNavNoClick")) {
          e.preventDefault();
          e.stopPropagation();
          $(".pageLeft").click();
        }
        break;
      case 39:
        if ($(".sidenav").hasClass("sidenavShow") || $(".menuOverlay").is(":visible")) return;
        if (!$(".pageRight").hasClass("pageNavNoClick")) {
          e.preventDefault();
          e.stopPropagation();
          $(".pageRight").click();
        }
        break;
    }
  });
}

function caBindSettingsFormHandlers(initialFormState) {
  var $sidenav = $("#sidenavContent");
  var $form = $sidenav.find(".ca_settingsForm");
  var $applyButton = $sidenav.find(".ca_settingsApply");
  var $resetButton = $sidenav.find(".ca_settingsDone");
  var updateSettingsButtonState = function() {
    var hasChanges = $form.serialize() !== initialFormState;
    $resetButton.prop("disabled", !hasChanges);
    $applyButton.prop("disabled", !hasChanges);
  };

  updateSettingsButtonState();
  $sidenav.off("change.caSettings input.caSettings", ".ca_settingsForm :input").on("change.caSettings input.caSettings", ".ca_settingsForm :input", function() {
    updateSettingsButtonState();
  });

  $sidenav.off("click.caSettings", ".ca_settingsDone").on("click.caSettings", ".ca_settingsDone", function() {
    showSettings();
  });

  $sidenav.off("submit.caSettings", ".ca_settingsForm").on("submit.caSettings", ".ca_settingsForm", function() {
    var $localForm = $(this);
    if ($localForm.data("submitting")) return false;
    $localForm.data("submitting", true);
    $sidenav.find(".ca_settingsApply,.ca_settingsDone").prop("disabled", true);

    addBannerWarning(tr("Saving settings..."), false, true);
    var onSaved = function() {
      addBannerWarning(tr("Settings saved. Refreshing..."), false, true);
      setTimeout(function() { window.location.reload(); }, 400);
    };

    var $progressFrame = $("iframe[name='progressFrame']");
    if ($progressFrame.length) {
      $progressFrame.off("load.caSettings").one("load.caSettings", function() { setTimeout(onSaved, 100); });
    } else {
      setTimeout(onSaved, 800);
    }
  });
}
