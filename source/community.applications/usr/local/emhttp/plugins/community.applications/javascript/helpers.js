/*
########################################
#                                      #
# Community Applications               #
# Copyright 2020-2026, Lime Technology #
# Copyright 2015-2026, Andrew Zawadzki #
#                                      #
# Licensed under GPL-2.0-or-later      #
#                                      #
########################################

SPDX-License-Identifier: GPL-2.0-or-later
*/

/**
 * @file Shared browser helpers for the Community Applications UI (utilities, spinners, DOM sync).
 */

/**
 * Dev-gated console.log. No-op unless CA's dev mode is enabled
 * (default.cfg's `dev` key flipped from `no` to `yes`).
 *
 * Reads the live setting via `caHasSetting("dev")` (single source of truth
 * = the #caSettingsFlags hidden div emitted by Apps.page) instead of a
 * page-load-time snapshot — flipping dev mode in another tab and coming
 * back to /Apps stays correctly reflected without a hard reload of this
 * tab's JS bundle.
 *
 * Wrapped in try/catch so a broken console (e.g. swal-modal sandboxing)
 * never throws out of a caller's middle. Use this instead of console.log()
 * for diagnostic chatter you only want end users to see when they've
 * explicitly turned dev mode on.
 *
 * @param {...*} _args Forwarded to console.log
 * @returns {void}
 */
function caDebug() {
	if (!caHasSetting("dev")) return;
	try { console.log.apply(console, arguments); } catch (e) { /* no-op */ }
}

/**
 * True when the named CA setting currently differs from its shipped
 * default.cfg value, false otherwise.
 *
 * Backed by the `#caSettingsFlags` hidden div that Apps.page emits at page
 * render time — it carries a `ca_<key>` class for every default.cfg key
 * whose runtime value in $GLOBALS['caSettings'] doesn't match the default.
 * So `caHasSetting("dev")` is shorthand for "the user has flipped dev
 * mode away from the shipped default", which for dev (default `no`) means
 * "dev is on", and for hideIncompatible (default `true`) means
 * "hideIncompatible has been turned off". The semantic is *differs*, not
 * *truthy* — the class name is the key, not the value.
 *
 * Uses an attribute selector + length so the answer doesn't depend on the
 * div existing in any particular spot — if a future caller moves or dupes
 * the flag div, the lookup still works.
 *
 * @param {string} name Setting key from default.cfg (eg. "dev", "displayUsageGraphs").
 * @returns {boolean}
 */
function caHasSetting(name) {
	if (!name) return false;
	return $("#caSettingsFlags.ca_" + name).length > 0;
}

/**
 * Parse `url` with the URL API; returns a `URL` instance or `false` if invalid.
 *
 * @param {string} url
 * @returns {URL|false}
 */
function isValidURL(url) {
	try {
		var ret = new URL(url);
		return ret;
	} catch (err) {
		return false;
	}
}

/**
 * Escape HTML special characters in the string (`&`, `<`, `>`) for safe DOM insertion.
 *
 * @returns {string} HTML-escaped copy of the string.
 */
String.prototype.escapeHTML = function() {
	return this.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

/**
 * Return a new array containing the unique elements of this array, preserving
 * first-seen order.
 *
 * @returns {Array} Deduplicated copy of this array.
 */
Array.prototype.uniqueArrayElements = function() {
	var uniqueEntries = new Array();
	$.each(this, function(i, el) {
		if ($.inArray(el,uniqueEntries) === -1) {
		 uniqueEntries.push(el)
		}
	});
	return uniqueEntries;
}

/**
 * Return the last path segment of a `/`-separated string (filename basename).
 *
 * @returns {string} Final segment after the last forward slash.
 */
String.prototype.basename = function() {
	return this.split('/').reverse()[0];
}

/**
 * Strip HTML tags from a string (regex-based).
 *
 * @param {string} str
 * @returns {string}
 */
function stripTags(str) {
	if ( ! str )
		return "";

	return str.replace(/(<([^>]+)>)/ig,"");
}

/**
 * Fire-and-forget invalidate of the server-side getAllInfo() cache file.
 * Used by saveState / restoreState, init paths that don't trigger a feed
 * update, and the ca_browser_back_helper.page child-page detector — all spots where
 * the container fleet might have changed under us. Uses postNoSpin so it
 * never raises a spinner or otherwise disturbs the UI.
 */
function caDropInfoCache() {
	try { postNoSpin({ action: "dropInfoCache" }); } catch (e) { /* no-op */ }
}

/* Pending show-spinner timer. The spinner is shown 250ms after it's requested,
   not immediately, so quick operations that finish inside that window never
   flash it. myCloseSpinner cancels a still-pending show. */
var caSpinnerTimer = null;

/**
 * Show the global spinner after a 250ms delay (skipped if the operation
 * completes first).
 */
function mySpinner(wait=250) {
	if (caSpinnerTimer !== null) return;            // a show is already pending
	if ($("div.spinner").is(":visible")) return;  
	if ( wait===0) {	  // Skip the delay
		$("div.spinner").show();
	} else {
		caSpinnerTimer = setTimeout(function() {
			caSpinnerTimer = null;
			mySpinner(0);
		}, wait);	
	}
}

/**
 * Hide the spinner (and cancel any pending delayed show).
 */
function myCloseSpinner() {
	if (caSpinnerTimer !== null) {
		clearTimeout(caSpinnerTimer);
		caSpinnerTimer = null;
	}
	$("div.spinner").hide();
}

/**
 * Reset navigation state after multi-select flows (clears selected category on `data`).
 */
function enableButtons() {
	data.selected_category = "";
}

/**
 * After container install/uninstall at deep scroll: refetch pages 1..current and restore `.mainArea` scrollTop.
 * For page 1 delegates to `changeSortOrder` only.
 */
function refreshDisplay() {
	/* changeSortOrder() refetches a SINGLE page (data.currentpage), which is
	   fine for sort-button clicks (user expects to land back at the top) but
	   breaks the scroll model when install/uninstall hooks call us from a
	   deep-scroll position: the cardCache gets replaced with just the current
	   page's slice and there's nothing to restore when the user scrolls up.
	   Bulk-load pages 1..currentpage in a single fetch, then put the viewport
	   back where it was. Reuses post()'s callback so we don't poll. */
	/* Most install/uninstall flows funnel through here (directly or via the
	   openPlugin "refreshDisplay" callback string), so this is the single
	   chokepoint where we re-sync the Installed/Previous submenu disable
	   states from server-side counts. Guard for skin variants that don't
	   ship the Apps.page helper. */
	if (typeof caRefreshInstalledMenuStates === "function") caRefreshInstalledMenuStates();
	var savedPage    = parseInt(data.currentpage, 10) || 1;
	var savedPerPage = parseInt(data.maxPerPage, 10) || (typeof getMaxPerPage === "function" ? getMaxPerPage() : 12);
	if (savedPage <= 1) {
		changeSortOrder(null, null, null);
		return;
	}
	var $ma = $(".mainArea");
	var savedScrollTop = $ma.length ? ($ma[0].scrollTop || 0) : 0;
	var startup = false;
	$(".startupButton").each(function() { if ($(this).hasClass("selectedMenu")) startup = "true"; });
	data.allLoaded = false;
	data.searchFlag = false;
	post({
		action:     "display_content",
		pageNumber: 1,
		selected:   data.selected,
		startup:    startup,
		maxPerPage: savedPage * savedPerPage
	}, function(result) {
		updateDisplay(result.display_data || result.display);
		/* Put the viewport back where the user was. updateDisplay just rebuilt
		   the DOM with all cards through the user's page, so scrollTop maps to
		   the same content. Restore on the next frame so layout has settled. */
		data.currentpage = savedPage;
		data.maxPerPage  = savedPerPage;
		requestAnimationFrame(function() {
			if ($ma.length) $ma[0].scrollTop = savedScrollTop;
		});
	});
}

/**
 * Append trailing `s` when count is 0 or greater than 1 (English plural helper).
 *
 * @param {string} string
 * @param {number} count
 * @returns {string}
 */
function makePlural(string,count) {
	return ( (count > 1) || (count == 0) ) ? string + "s" : string;
}

/**
 * Sort comparator for `[folder, app]` pairs (multi-install list) by folder name then app id.
 *
 * @param {Array} a
 * @param {Array} b
 * @returns {number}
 */
function installSort(a,b) {
	if (a[0] === b[0]) {
		if (a[1] === b[1]) return 0;
		return (a[1] < b[1]) ? -1 : 1;
	}
	return (a[0] < b[0]) ? -1 : 1;
}

/**
 * Full page reload.
 */
function reloadPage() {
	window.location.reload();
}

/**
 * Whether `el` overflows horizontally (`type` truthy) or vertically (default).
 *
 * @param {HTMLElement} el
 * @param {boolean} [type] true = horizontal
 * @returns {boolean}
 */
function isOverflown(el,type=false){
	// Optimized to minimize forced reflows by using the most efficient DOM properties
	// offsetWidth/offsetHeight are generally faster than clientWidth/clientHeight
	if (type) {
		// For horizontal overflow: compare scrollable content width with element's offset width
		return el.scrollWidth > el.offsetWidth;
	}
	// For vertical overflow: compare scrollable content height with element's offset height
	return el.scrollHeight > el.offsetHeight;
}


/**
 * Disable the main search input (during alerts).
 */
function disableSearch() {
	$("#searchBox").prop("disabled",true);
	$("#searchBox").blur();
}

/**
 * Re-enable the search input after `disableSearch()`.
 */
function enableSearch() {
	$("#searchBox").prop("disabled",false);
}

/** Align search modal to .mainArea (fixed coords from getBoundingClientRect). */
function caUpdateSearchModalLayout() {
	if (!$("body").hasClass("ca_searchModalOpen")) return;
	var $main = $(".mainArea");
	if (!$main.length) return;
	var r = $main[0].getBoundingClientRect();
	var rootFont = parseFloat($("html").css("font-size")) || 16;
	var topPx = r.top + 2 * rootFont;
	var panelW = r.width * 0.5;
	var leftPx = r.left + (r.width - panelW) / 2;
	$("html")
		.css("--ca-search-modal-top", topPx + "px")
		.css("--ca-search-modal-left", leftPx + "px")
		.css("--ca-search-modal-width", panelW + "px");
}

/**
 * If the field is empty but a search is still active (e.g. user backspaced in the modal and closed it),
 * put data.committedSearchFilter back in the input so the bar/modal show the current query.
 */
function caRestoreCommittedSearchTermIntoBoxIfEmpty() {
	var d = typeof data !== "undefined" && data ? data : null;
	if (!d) return false;
	var c = $.trim(String(d.committedSearchFilter || ""));
	var v = $.trim(String($("#searchBox").val() || ""));
	if (!c || v) return false;
	$("#searchBox").val(c);
	return true;
}

/**
 * Filled overlay + fixed panel (same #searchBox + Awesomplete).
 * @param {object} [options] noRefocus: if true, do not move focus to #searchBox (e.g. focus handler already has it).
 */
function caOpenSearchModal(options) {
	/* Desktop uses the always-visible inline search input + horizontal
	   suggestion strip in .searchArea instead of the modal flow. Route
	   to focusing the inline input so the Cmd/Ctrl+K global hotkey
	   (caInitGlobalSearchHotkeyOverride) still puts the cursor on the
	   primary desktop search control instead of becoming a no-op.
	   body.ca_searchModalOpen is deliberately never set on desktop —
	   that class is what the .ca_modal_overlay click handler (and a
	   handful of other places) checks to decide "click outside should
	   close" / "input got focus while modal is open", and it would
	   otherwise tear the inline strip down whenever the user clicks
	   a caret. */
	if (window.innerWidth >= 768) {
		try {
			var $inline = $("#caInlineSearchBox");
			if ($inline.length && $inline.is(":visible")) {
				$inline.trigger("focus").select();
			}
		} catch (e) { /* no-op */ }
		return;
	}
	options = options || {};
	caRestoreCommittedSearchTermIntoBoxIfEmpty();
	$("body").addClass("ca_searchModalOpen");
	caInitSearchModalSuggestionInputMode();
	$("#searchFilter").removeClass("ca_searchInputCollapsed");
	caUpdateSearchModalLayout();
	$(window).on("resize.caSearchModal orientationchange.caSearchModal", caUpdateSearchModalLayout);
	requestAnimationFrame(function() {
		caUpdateSearchModalLayout();
		setTimeout(function() {
			if (!options.noRefocus) {
				$("#searchBox").trigger("focus");
			}
			/* Always refresh chips when reopening: committed text is often already in #searchBox (didRestore false). */
			var kick = function() {
				caKickSearchModalAwesomplete();
			};
			requestAnimationFrame(function() {
				kick();
				setTimeout(kick, 40);
				setTimeout(kick, 100);
			});
			/* Ensure the Awesomplete dropdown area is open/visible when the modal opens, but only when the
			   input has enough text. Otherwise force it closed so stale suggestions don't flash. */
			try {
				if (typeof searchBoxAwesomplete !== "undefined" && searchBoxAwesomplete) {
					var minChars = typeof searchBoxAwesomplete.minChars === "number" ? searchBoxAwesomplete.minChars : 2;
					var hasEnough = $.trim(String($("#searchBox").val() || "")).length >= minChars;
					if (hasEnough && !searchBoxAwesomplete.opened) {
						if (typeof searchBoxAwesomplete.open === "function") searchBoxAwesomplete.open();
					} else if (!hasEnough) {
						if (typeof searchBoxAwesomplete.close === "function") searchBoxAwesomplete.close();
					}
				}
			} catch (e) { /* no-op */ }
			caSyncSearchModalClearButton();
		}, 0);
	});
}

/** Run Awesomplete's internal evaluate (jQuery .trigger("input") does not always fire native listeners). */
function caRunSearchBoxAwesompleteEvaluate() {
	var el = document.getElementById("searchBox");
	if (!el || typeof searchBoxAwesomplete === "undefined" || !searchBoxAwesomplete) return;
	if (typeof searchBoxAwesomplete.evaluate === "function") {
		searchBoxAwesomplete.evaluate();
	} else {
		var ev;
		try {
			ev = typeof InputEvent === "function" ? new InputEvent("input", { bubbles: true, cancelable: true }) : new Event("input", { bubbles: true });
		} catch (err) {
			ev = new Event("input", { bubbles: true });
		}
		el.dispatchEvent(ev);
	}
}

/**
 * Re-run suggestions when the search modal is shown.
 * - If the field meets minChars, run evaluate() so the list (re)populates from the current term.
 * - If the field is empty/below minChars, run evaluate() anyway so Awesomplete drops any cached
 *   `<li>` items from a prior search and then close the dropdown.
 */
function caKickSearchModalAwesomplete() {
	if (typeof searchBoxAwesomplete === "undefined" || !searchBoxAwesomplete) return;
	var $el = $("#searchBox");
	if (!$el.length) return;
	var v = String($el.val() || "");
	var minC = typeof searchBoxAwesomplete.minChars === "number" ? searchBoxAwesomplete.minChars : 2;
	caRunSearchBoxAwesompleteEvaluate();
	if (v.length < minC) {
		try { searchBoxAwesomplete.close(); } catch (e) { /* no-op */ }
	}
}

/**
 * Search suggestions: once the user uses the mouse to hover a suggestion, do not keep
 * the keyboard-selected suggestion "hovered" when the mouse leaves. Require another
 * ArrowUp/ArrowDown keypress to re-enable the keyboard hover state.
 */
function caInitSearchModalSuggestionInputMode() {
	if (window.ca_searchModalSuggestionInputModeInit) return;
	window.ca_searchModalSuggestionInputModeInit = true;

	/* Mouse hover marks "mouse used" */
	$(document).on("mouseenter.caSuggestionInputMode", "body.ca_searchModalOpen #searchFilter .awesomplete > ul > li", function() {
		$("body").addClass("ca_suggestionMouseUsed");
		/* Keep arrow-key navigation working by ensuring the input stays focused.
		   Use the native focus() so we can pass preventScroll (jQuery .trigger("focus") can't). */
		try {
			var $sb = $("#searchBox");
			if ($sb.length && !$sb.is(":focus")) {
				$sb[0].focus({ preventScroll: true });
			}
		} catch (err) { /* no-op */ }
	});

	/* Arrow key navigation clears "mouse used" so keyboard hover can show again */
	$(document).on("keydown.caSuggestionInputMode", "#searchBox", function(e) {
		var k = e && (e.key || e.keyCode);
		if (k === "ArrowDown" || k === "ArrowUp" || k === 40 || k === 38) {
			$("body").removeClass("ca_suggestionMouseUsed");
		}
	});

	/*
	Ensure arrow-key navigation always works even if focus drifts after mouse hover:
	on ArrowUp/ArrowDown while the search modal is open, force focus back to #searchBox
	and clear ca_suggestionMouseUsed. Capture phase is required so this runs before
	Awesomplete's own keydown handler — jQuery does not expose the capture flag.
	*/
	try {
		var sbArrow = $("#searchBox")[0];
		if (sbArrow && !sbArrow.__caSuggestionArrowKeyCap) {
			sbArrow.__caSuggestionArrowKeyCap = true;
			window.addEventListener("keydown", function(e) {
				if (!$("body").hasClass("ca_searchModalOpen")) return;
				var k = e && (e.key || e.keyCode);
				if (!(k === "ArrowDown" || k === "ArrowUp" || k === 40 || k === 38)) return;
				try {
					var $sb = $("#searchBox");
					if ($sb.length && !$sb.is(":focus")) {
						$sb[0].focus({ preventScroll: true });
					}
				} catch (err) { /* no-op */ }
				$("body").removeClass("ca_suggestionMouseUsed");
			}, true);
		}
	} catch (err) { /* no-op */ }

	/*
	Enter key: if the mouse was used (so keyboard highlight is suppressed) and nothing is
	currently hovered by the mouse, prevent Awesomplete from selecting the last keyboard
	item on Enter. This keeps the raw input value as the search term. Capture phase is
	required to run before Awesomplete — jQuery does not expose the capture flag.
	*/
	try {
		var sbEnter = $("#searchBox")[0];
		if (sbEnter && !sbEnter.__caSuggestionInputModeEnterCap) {
			sbEnter.__caSuggestionInputModeEnterCap = true;
			sbEnter.addEventListener("keydown", function(e) {
				var k = e && (e.key || e.keyCode);
				if (!(k === "Enter" || k === 13)) return;
				if (!$("body").hasClass("ca_suggestionMouseUsed")) return;
				if ($("body.ca_searchModalOpen #searchFilter .awesomplete > ul > li:hover").length) return;

				/* Clear any active selection before Awesomplete's key handler runs */
				try {
					if (typeof searchBoxAwesomplete !== "undefined" && searchBoxAwesomplete) {
						searchBoxAwesomplete.index = -1;
					}
				} catch (err) { /* no-op */ }
			}, true);
		}
	} catch (err) { /* no-op */ }
}

/**
 * If the field has any text (committed search or draft), focus/mousedown reopens the search modal
 * so Awesomplete uses the in-modal chip layout. (mousedown runs when the input is already focused.)
 * Empty field: opening the modal is done via the search icon or by focusing #searchBox (e.g. Tab).
 */
function caReopenSearchModalIfNeeded() {
	if ($("body").hasClass("ca_searchModalOpen")) return;
	var v = $.trim(String($("#searchBox").val() || ""));
	if (!v) {
		caRestoreCommittedSearchTermIntoBoxIfEmpty();
		v = $.trim(String($("#searchBox").val() || ""));
	}
	if (!v) return;
	caOpenSearchModal({ noRefocus: true });
	var afterListReady = function() {
		if (typeof searchBoxAwesomplete === "undefined" || !searchBoxAwesomplete) return;
		var list = searchBoxAwesomplete._list;
		if (list == null) {
			try { list = searchBoxAwesomplete.list; } catch (e) { list = null; }
		}
		if (!list || (Array.isArray(list) && !list.length)) {
			if (typeof populateAutoComplete === "function") {
				populateAutoComplete(function() {
					caRunSearchBoxAwesompleteEvaluate();
				});
			} else {
				caRunSearchBoxAwesompleteEvaluate();
			}
			return;
		}
		caRunSearchBoxAwesompleteEvaluate();
	};
	/* Defer until modal + layout; repeat so Awesomplete sees the visible ul (flex-wrap) */
	var kick = function() { afterListReady(); };
	requestAnimationFrame(function() {
		requestAnimationFrame(function() {
			kick();
			setTimeout(kick, 0);
			setTimeout(kick, 40);
			setTimeout(kick, 100);
		});
	});
}

/**
 * Tear down the search modal: CSS vars, body classes, Awesomplete, optional draft discard.
 *
 * @param {object} [options] discardDraft: restore committed term or clear when abandoning modal
 */
function caCloseSearchModal(options) {
	options = options || {};
	$(window).off("resize.caSearchModal orientationchange.caSearchModal", caUpdateSearchModalLayout);
	$("html")
		.css("--ca-search-modal-top", "")
		.css("--ca-search-modal-left", "")
		.css("--ca-search-modal-width", "");

	/*
	Abandonment paths (backdrop click, focusout, ESC) pass discardDraft:true:
	- If an active committed search exists, restore the input to that committed term
	  (so reopening shows the prior search and its autocomplete).
	- Otherwise, clear the draft entirely so reopening starts blank.
	Commit paths (doSearch) pass nothing and must not have the input touched here.
	*/
	if (options.discardDraft) {
		try {
			var d = (typeof data !== "undefined" && data) ? data : null;
			var committed = d ? $.trim(String(d.committedSearchFilter || "")) : "";
			var hasActive = d && !!(d.searchActive || d.searchFlag || d.docker);
			$("#searchBox").val((committed && hasActive) ? committed : "");
			/* Force Awesomplete to re-evaluate against the new value so its cached <li> list updates
			   (empty input drops the list; a restored committed term repopulates from that term). */
			if (typeof searchBoxAwesomplete !== "undefined" && searchBoxAwesomplete && typeof searchBoxAwesomplete.evaluate === "function") {
				searchBoxAwesomplete.evaluate();
			}
		} catch (e) { /* no-op */ }
	}

	$("body").removeClass("ca_searchModalOpen ca_suggestionMouseUsed ca_awesomplete_open");
	if (typeof searchBoxAwesomplete !== "undefined" && searchBoxAwesomplete) {
		try { searchBoxAwesomplete.close(); } catch (e) { /* no-op */ }
	}
	try {
		var $sb = $("#searchBox");
		if ($sb.is(":focus")) $sb.trigger("blur");
	} catch (e) { /* no-op */ }
	caSyncSearchFilterCollapsed();
}

/** Modal: show X when there is text to clear or an active search/docker context to exit. */
function caSyncSearchModalClearButton() {
	var $x = $(".searchModalClearBtn");
	if (!$x.length) return;
	var v = $.trim(String($("#searchBox").val() || ""));
	var d = typeof data !== "undefined" && data ? data : null;
	var c = d ? $.trim(String(d.committedSearchFilter || "")) : "";
	var hasActive = d && !!(d.searchActive || d.searchFlag || d.docker);
	var show = !!v;
	if (!show && c && hasActive) {
		show = true;
	}
	if (show) {
		$x.removeClass("ca_hide");
	} else {
		$x.addClass("ca_hide");
	}
}

/** Toolbar: icon-only row; full input only while the search modal is open. */
function caSyncSearchFilterCollapsed() {
	var $f = $("#searchFilter");
	if (!$f.length) return;
	if ($("body").hasClass("ca_searchModalOpen")) {
		$f.removeClass("ca_searchInputCollapsed");
	} else {
		$f.addClass("ca_searchInputCollapsed");
	}
	caSyncSearchModalClearButton();
}


/**
 * True if string matches common truthy tokens (true, 1, on).
 *
 * @param {string} str
 * @returns {boolean}
 */
function evaluateBoolean(str) {
	var regex=/^\s*(true|1|on)\s*$/i
	return regex.test(str);
}

/**
 * Whether the browser reports cookies enabled (via `evaluateBoolean` on `navigator.cookieEnabled`).
 */
function cookiesEnabled() {
	return evaluateBoolean(navigator.cookieEnabled);
}

/**
 * Scroll document and `.mainArea` to top (category switches rely on main pane reset).
 */
function scrollToTop() {
	$('html,body').animate({scrollTop:0},0);
	/* CA's actual scroller is .mainArea, not the document. Resetting only
	   html/body left the user at the bottom of the previous category when
	   switching to a new one (eg. clicking Repositories after scrolling deep
	   into All Apps). Snap .mainArea too. */
	var ma = $(".mainArea")[0];
	if (ma) ma.scrollTop = 0;
}

/** Line under Home: last committed app search (after Enter/submit), not draft typing (non-clickable). */
function caSyncHomeSearchSubtitle() {
	var $el = $("#ca_homeSearchSubtitle");
	if ($el.length && typeof data !== "undefined" && data) {
		var committed = $.trim(String(data.committedSearchFilter || ""));
		/* caShowInApps puts the template Name in the search box but wants the
		   subtitle to read the URL instead. The override only applies while it
		   still belongs to the current committed term. Any new search or
		   navigation changes committedSearchFilter and the override is dropped. */
		var override = (data.committedSearchSubtitleFor && data.committedSearchSubtitleFor === committed)
			? $.trim(String(data.committedSearchSubtitle || ""))
			: "";
		var v = override || committed;
		if (!v) {
			$el.empty().addClass("ca_hide");
		} else {
			$el.text(v).removeClass("ca_hide");
		}
	}
	caSyncHomeMenuLabel();
}

/**
 * Toggle the Home menu item's label between "Home" and "Clear Search" based on whether
 * a search is in progress. The two translated strings come from data attributes set by skin.html.
 */
function caSyncHomeMenuLabel() {
	var d = (typeof data !== "undefined" && data) ? data : null;
	var c = d ? $.trim(String(d.committedSearchFilter || "")) : "";
	var hasActive = d && !!(d.searchActive || d.searchFlag || d.docker);
	var inProgress = !!c || hasActive;
	$(".caHomeMenuLabel").each(function() {
		var $el = $(this);
		var home = $el.attr("data-home-label") || "Home";
		var clear = $el.attr("data-clear-label") || "Clear Search";
		var want = inProgress ? clear : home;
		if ($el.text() !== want) $el.text(want);
	});
	$(".caAllAppsMenuLabel").each(function() {
		var $el = $(this);
		var allApps = $el.attr("data-all-apps-label") || "All Apps";
		var allResults = $el.attr("data-all-results-label") || "All Results";
		var want = inProgress ? allResults : allApps;
		if ($el.text() !== want) $el.text(want);
	});
}

/** If the box was edited (e.g. backspaced) but not submitted, put the last committed search back. Called from the nav menu (#mobileMenu) or page change (changePage / dockerSearch). */
function caRestoreCommittedSearchIfDrafted() {
	if (typeof data === "undefined" || !data) return;
	var c = $.trim(String(data.committedSearchFilter || ""));
	if (!c) return;
	var cur = $.trim(String($("#searchBox").val() || ""));
	if (cur === c) return;
	$("#searchBox").val(c);
	caSyncSearchFilterCollapsed();
}

/**
 * Translate via Dynamix `_()` — thin wrapper for consistency in CA JS.
 *
 * @param {string} string
 * @returns {string}
 */
function tr(string) {
 return _(string);
}

/**
 * Show full-screen blocker element before a hard reload/navigation.
 */
function caBlockViewportForReload() {
	try {
		$("#caViewportBlocker").removeClass("ca_hide");
	} catch(e) {}
}

/**
 * Non-auto-reloading fatal banner: user must click or keypress to trigger Home or `location.reload()`.
 *
 * Used for the benign cross-tab "another instance updated the feed" reload — a
 * stale tab just needs to pick up fresh content. For an actual failed request
 * (a broken exec.php POST) use caShowFatalErrorBanner instead, which shows
 * explicit buttons and error detail rather than reloading on any click.
 *
 * @param {string} [message] Banner text (translated default when empty)
 * @param {*} _unusedDelay Reserved
 */
function caShowFatalReloadBanner(message, _unusedDelay) {
	try {
		if (window.ca_reloadPending) return;
		window.ca_reloadPending = true;
		try {
			if (typeof closeSidebar === "function") closeSidebar(true, true);
		} catch(e) {}
		var msg = (typeof message === "string" && message) ? message : tr("Click anywhere to reload the page.");

		var $banner = $(".ca_bottomBanner");
		var $msg = $(".ca_fatalReloadBanner");
		if ($banner.length && $msg.length) {
			$(".ca_pageGeometryChange").addClass("ca_hide");
			$msg.text(msg).removeClass("ca_hide");
			$banner.removeClass("ca_hide");
		} else {
			alert(msg);
		}

		var doHomeReload = function() {
			window.location.reload();
		};
		/* User-driven reload instead of a timer: multiple tabs receiving the
		   same "feed updated" signal would otherwise all reload simultaneously,
		   each spawning a new tabId and pile of cache files. Waiting for an
		   explicit click means tabs only reload when the user actually wants
		   to reuse them. Capture phase + once: true so the very first input
		   anywhere triggers it without bubbling into other handlers first. */
		var onAny = function() {
			document.removeEventListener("click", onAny, true);
			document.removeEventListener("keydown", onAny, true);
			/* If a real exec.php failure has since taken the banner over via
			   caShowFatalErrorBanner, don't reload out from under its buttons and
			   error detail — let this click reach those buttons instead. */
			if (window.ca_fatalErrorActive) return;
			doHomeReload();
		};
		document.addEventListener("click", onAny, true);
		document.addEventListener("keydown", onAny, true);
	} catch(e) {
		var $homeBtn = $(".startupButton").first();
		if ($homeBtn.length) $homeBtn.trigger("click");
		else window.location.reload();
	}
}

/**
 * Fatal ERROR banner (a failed exec.php request) with explicit Ignore /
 * Download logs / Reload buttons. Unlike caShowFatalReloadBanner — the
 * click-anywhere feed-update reload — this one NEVER reloads on a stray click;
 * the user chooses. Shows `message`, and the caller appends the failed action
 * and a PHP-log tail beneath it.
 *
 * @param {string} [message] Banner text (translated default when empty)
 */
function caShowFatalErrorBanner(message) {
	try {
		/* Gate on THIS banner's own flag, NOT the shared ca_reloadPending: a real
		   failure must be able to take over even if the benign feed-update banner
		   (caShowFatalReloadBanner) already claimed ca_reloadPending first —
		   otherwise the failure's buttons and diagnostics are silently dropped.
		   Setting ca_reloadPending too then suppresses any later feed banner. */
		if (window.ca_fatalErrorActive) return;
		window.ca_fatalErrorActive = true;
		window.ca_reloadPending = true;
		try {
			if (typeof closeSidebar === "function") closeSidebar(true, true);
		} catch(e) {}
		var msg = (typeof message === "string" && message) ? message : tr("Something went wrong.");

		var $banner = $(".ca_bottomBanner");
		var $msg = $(".ca_fatalReloadBanner");
		var $buttons = $(".ca_fatalReloadButtons");
		if ($banner.length && $msg.length && $buttons.length) {
			$(".ca_pageGeometryChange").addClass("ca_hide");
			$msg.text(msg).removeClass("ca_hide");
			/* Namespaced + off-first so re-showing the banner never stacks
			   duplicate handlers on the buttons. */
			$(".ca_fatalReloadBtn").off("click.caFatal").on("click.caFatal", function() {
				window.location.reload();
			});
			$(".ca_fatalIgnoreBtn").off("click.caFatal").on("click.caFatal", caDismissFatalReloadBanner);
			$(".ca_fatalDownloadBtn").off("click.caFatal").on("click.caFatal", caDownloadFatalLogs);
			$buttons.removeClass("ca_hide");
			$banner.removeClass("ca_hide");
		} else {
			/* Skin markup missing (shouldn't happen): fall back to a confirm. */
			if (confirm(msg + "\n\n" + tr("Reload the page now?"))) window.location.reload();
			else { window.ca_fatalErrorActive = false; window.ca_reloadPending = false; }
		}
	} catch(e) {
		var $homeBtn = $(".startupButton").first();
		if ($homeBtn.length) $homeBtn.trigger("click");
		else window.location.reload();
	}
}

/**
 * Dismiss the fatal reload banner (the Ignore action): hide the banner and its
 * buttons, clear the appended message / PHP-log detail, unblock the viewport,
 * and clear the pending flag so a later failure can raise the banner again.
 */
function caDismissFatalReloadBanner() {
	try {
		$(".ca_fatalReloadButtons").addClass("ca_hide");
		$(".ca_fatalReloadBanner").addClass("ca_hide").empty();
		$(".ca_bottomBanner").addClass("ca_hide");
		$("#caViewportBlocker").addClass("ca_hide").removeClass("caReloadBlur");
		window.ca_fatalErrorActive = false;
		window.ca_reloadPending = false;
	} catch(e) {}
}

/**
 * "Download logs" action on the fatal banner: trigger the standalone
 * downloadlog.php (a GET that streams a zip of CA's logs). Kept off post() /
 * exec.php on purpose — the banner is only up because a post() just failed, so
 * reusing that path would hit the same broken endpoint / stale csrf_token. The
 * server's Content-Disposition: attachment means the browser downloads without
 * navigating away, so the banner stays up.
 */
function caDownloadFatalLogs() {
	try {
		if (typeof downloadLogURL !== "string" || !downloadLogURL) return;
		var a = document.createElement("a");
		a.href = downloadLogURL;
		a.rel = "noopener";
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
	} catch(e) {
		try { window.location = downloadLogURL; } catch(e2) {}
	}
}

/**
 * Append the name of the exec.php action that failed under the fatal banner's
 * message, so the user (and support) sees WHICH request broke — not just that
 * something did. Best-effort; no-op when the action or banner is absent.
 */
function caAppendFatalActionDetail(action) {
	try {
		if ( ! action ) return;
		var $msg = $(".ca_fatalReloadBanner");
		if ( ! $msg.length ) return;
		$msg.find(".caErrorAction").remove();
		/* .text() so the action id can't inject markup into the banner. */
		$("<div>").addClass("caErrorAction").text(tr("Failed action") + ": " + action).appendTo($msg);
	} catch(e) {}
}

/**
 * Best-effort: fetch the last line or two of the system PHP error log and append
 * them, as a muted detail block, beneath the fatal reload banner's message so
 * the user (and support) can see WHY the POST failed without digging through
 * logs. Any failure — endpoint unreachable, empty log, banner not on the page —
 * silently leaves the banner as-is.
 *
 * A plain GET to errorLogURL, NOT the usual post(): the banner only ever appears
 * because a post() to exec.php just failed, so reusing that path would hit the
 * same broken endpoint / stale csrf_token. See include/errorlog.php.
 */
function caAppendPhpLogTail() {
	try {
		if (typeof errorLogURL !== "string" || !errorLogURL) return;
		$.get(errorLogURL, {lines: 2}).done(function(result) {
			var tail = (result && typeof result.log === "string") ? result.log : "";
			if ( ! tail ) return;
			var $msg = $(".ca_fatalReloadBanner");
			if ( ! $msg.length ) return;
			/* .text() so raw log content (may contain <, &, quotes) is escaped
			   and can't inject markup into the banner. */
			$("<div>").addClass("caErrorLogDetail").text(tail).appendTo($msg);
		});
	} catch(e) {}
}

/**
 * Show the bottom banner announcing a required reload, wait `delayMs`
 * (default 10s), then run the reload routine. Used in place of an immediate
 * auto-reload after a settings change or a repository enable/disable so the
 * user sees why the page is about to refresh.
 *
 * An optional `beforeReload(done)` callback runs when the countdown elapses and
 * MUST call `done()` to perform the reload. The repository path uses this to
 * defer its /tmp wipe until after the countdown — nothing destructive happens
 * while the banner is up.
 *
 * @param {function} [beforeReload] Optional pre-reload step; receives a `done` callback.
 * @param {number}   [delayMs]      Countdown in ms (default 10000).
 */
function caShowReloadNoticeBanner(beforeReload, delayMs) {
	if (window.ca_reloadPending) return;
	window.ca_reloadPending = true;
	delayMs = (typeof delayMs === "number") ? delayMs : 10000;

	try { if (typeof closeSidebar === "function") closeSidebar(true, true); } catch (e) {}

	/* Blurred, full-screen blocker so the user can't interact with the
	   about-to-be-stale page while the countdown runs (z-index 9999, below the
	   .ca_bottomBanner toast at 10000 so the message stays crisp on top). */
	$("#caViewportBlocker").addClass("caReloadBlur").removeClass("ca_hide");

	var baseMsg = tr("Community Applications needs to reload for the changes to take effect");
	var $banner = $(".ca_bottomBanner");
	var $msg = $(".ca_fatalReloadBanner");
	var secs = Math.ceil(delayMs / 1000);
	/* baseMsg is a fixed translated string (no user input) so .html() is safe;
	   the live countdown sits on its own line beneath it. */
	var render = function() {
		$msg.html(baseMsg + "<br><span class='caReloadCountdown'>" + tr("Reloading in") + " " + secs + "s</span>");
	};
	if ($banner.length && $msg.length) {
		$(".ca_pageGeometryChange").addClass("ca_hide");
		render();
		$msg.removeClass("ca_hide");
		$banner.removeClass("ca_hide");
	}

	var tick = setInterval(function() {
		secs = Math.max(0, secs - 1);
		render();
		if (secs <= 0) clearInterval(tick);
	}, 1000);

	var doReload = function() {
		window.location.reload();
	};

	setTimeout(function() {
		clearInterval(tick);
		if (typeof beforeReload === "function") {
			try { beforeReload(doReload); return; } catch (e) { /* fall through */ }
		}
		doReload();
	}, delayMs);
}

/**
 * Same as {@link post} but forces `noSpinner` (logging prefix "No Spin Post").
 *
 * @param {object|function} options POST payload or callback when first arg is the callback
 * @param {function} [callback]
 */
function postNoSpin(options,callback) {
	var msg = "No Spin Post: ";
	caDebug(msg+JSON.stringify(options));
	if ( typeof options === "function" ) {
		callback = options;
		options = {};
	}
	options.noSpinner = true;
	post(options,callback);
}

/**
 * AJAX POST to Community Applications `execURL` with spinner refcount, `tabId` stamp, and script eval.
 *
 * @param {object} [options] action and POST fields; `noSpinner` skips overlay; `tabId` optional override
 * @param {function} [callback] Receives parsed JSON response
 */
function post(options,callback) {
	if ( typeof options === "function" ) {
		callback = options;
		options = {};
	} else {
		var msg = postCount > 0 ? "Embedded Post: " : "Post: ";
		caDebug(msg+JSON.stringify(options));
	}
	if ( ! options || typeof options !== "object" ) {
		options = {};
	}
	/* Stamp the per-tab id on every request so paths.php can suffix the cache
	   files for this tab. Skipped only if the caller already supplied one. */
	if (options && typeof options === "object" && !options.tabId && typeof data !== "undefined" && data.tabId) {
		options.tabId = data.tabId;
	}
	/* Cross-tab feed-update detection. Once a tab has rendered its first
	   content (Apps.page arms caFeedTrackingArmed in get_content's success
	   callback — at which point displayed-{tabId}.json is guaranteed to
	   exist on disk), every subsequent request carries caFeedCheck=true.
	   exec.php's pre-switch guard re-checks the file's existence; if
	   another tab triggered a DownloadApplicationFeed (which wipes
	   tempFiles), the file is gone and the server short-circuits with
	   feedUpdated=true — the done callback below shows the reload banner.
	   No nchan, no buffered-message race; the check only fires when the
	   user actually does something on a stale tab. */
	if (window.caFeedTrackingArmed && !options.caFeedCheck) {
		options.caFeedCheck = true;
	}
	if ( ! options.noSpinner ) {
		if ( postCount == 0) {
			if ( ! $(".ca_sweetalert_open").length ) {
				mySpinner();
			}

		}
		postCount++;
	}

	$.post(execURL,options).done(function(result) {
		/* Server's pre-switch guard short-circuited: another tab triggered
		   a feed download while this tab was sitting around, our per-tab
		   displayed cache is gone, and the action we were about to do
		   would be running against stale state. Show the reload banner
		   and stop processing this response (no script eval, no caller
		   callback — they'd be operating on the empty {feedUpdated:true}
		   payload anyway). */
		if (result && result.feedUpdated) {
			if (typeof caHandleForeignFeedUpdate === "function") {
				caHandleForeignFeedUpdate();
			}
			if ( ! options.noSpinner ) {
				postCount--;
				if (postCount < 0) postCount = 0;
				if (postCount === 0) myCloseSpinner();
			}
			return;
		}
		if (result.script) {
			try {
				eval(result.script);
			} catch(e) {
				alert("Could not execute result.script "+e);
			}
		}
		if (result.globalScript) {
			try {
				eval(result.globalScript);
			} catch(e) {
				alert("Could not execute result.globalScript "+e);
			}
		}
		if ( typeof callback === "function" ) {
			try {
				callback(result);
			} catch(e) {
				if ( ! data.loggedOut ) {
					post({action:'javascriptError',postCall:options.action,retval:result});
					alert("Fatal error during "+options.action+" "+e);
				}
			}
		}

		/* After a user-driven server round-trip, hide any parent-with-subs
		   the user expanded but never picked from. Branches that hold the
		   active selection are left in whatever expanded/compact state the
		   click handler left them in — we don't snap a freshly-picked
		   branch back to compact mid-flow. Skipped for noSpinner / postNoSpin
		   so background polls don't yank UI state. */
		if (!options.noSpinner && typeof caHideUnselectedSubs === "function") {
			caHideUnselectedSubs();
		}


		if ( ! options.noSpinner ) {
			postCount--;
			if (postCount < 0) {
				postCount = 0;
			}
			if ( postCount == 0 ) {
				myCloseSpinner();
			}
		}

	}).fail(function(result){
		if ( ! options.noSpinner ) {
			postCount--;
			if (postCount < 0) {
				postCount = 0;
			}
			if (postCount === 0) {
				myCloseSpinner();
			}
		}
		/* Suppress the failed-communication banner when the user clicked
		   EXIT on the updating-applications popup — the failure is just the
		   in-flight POST being aborted by the impending history.back() nav. */
		if (data.quittingUpdate) return;
		/* Failed POST (network glitch, or a stale csrf_token after the server
		   was reset) leaves this tab unable to talk to the server. Show the
		   error banner over the blocked viewport with explicit Ignore / Download
		   logs / Reload buttons (NOT the click-anywhere feed-update banner) so a
		   stray click can't discard the page or the error detail we append. */
		/* Only enrich on the error banner's FIRST show — gate on ca_fatalErrorActive
		   (not ca_reloadPending, which a benign feed banner may already hold) so a
		   real failure still gets its action / log lines, and repeated in-flight
		   failures while the error banner is up don't stack duplicates. */
		var caFirstShow = ! window.ca_fatalErrorActive;
		caBlockViewportForReload();
		caShowFatalErrorBanner(tr("Unfortunately something went wrong."));
		if (caFirstShow) {
			/* Name the failed action, then pull in the tail of /var/log/phplog,
			   so the banner says more than "something went wrong". Both are
			   best-effort and shown under the message. */
			caAppendFatalActionDetail(options && options.action);
			caAppendPhpLogTail();
		}
	});
}

/**
 * Per-tab session-storage helpers used in place of cookies for transient CA
 * state (sidebar app, plugin-install round-trip, language update, etc.) so two
 * CA tabs in the same browser can't overwrite each other's state. Wrapped in
 * try/catch because sessionStorage access throws in some private-mode contexts.
 *
 * @param {string} key
 * @returns {?string}
 */
function caSessGet(key) {
	try { return sessionStorage.getItem(key); } catch (e) { return null; }
}
function caSessSet(key, value) {
	try { sessionStorage.setItem(key, value); } catch (e) { /* no-op */ }
}
function caSessRemove(key) {
	try { sessionStorage.removeItem(key); } catch (e) { /* no-op */ }
}

/**
 * Persistent (cross-session, cross-tab) storage helpers, used in place of
 * long-lived preference cookies (startup screen, selected menu) so they survive
 * a browser restart and stay shared between tabs. Same try/catch guard.
 *
 * @param {string} key
 * @returns {?string}
 */
function caLocalGet(key) {
	try { return localStorage.getItem(key); } catch (e) { return null; }
}
function caLocalSet(key, value) {
	try { localStorage.setItem(key, value); } catch (e) { /* no-op */ }
}

/**
 * SweetAlert wrapper with search disabled for the duration; mirrors older CA alert API.
 *
 * @param {string} description Title
 * @param {string} textdescription Body HTML
 * @param {string} textimage Unused image slot
 * @param {string} imagesize Default "80"
 * @param {boolean} outsideClick Allow dismiss by backdrop
 * @param {boolean} showCancel
 * @param {boolean} showConfirm
 * @param {string} alertType SweetAlert type
 */
function myAlert(description,textdescription,textimage,imagesize, outsideClick, showCancel, showConfirm, alertType) {
	if ( !outsideClick ) outsideClick = false;
	if ( !showCancel )   showCancel = false;
	if ( !showConfirm )  showConfirm = false;
	if ( imagesize == "" ) { imagesize = "80"; }
	disableSearch();

	swal({
		title: description,
		text: textdescription,
		allowOutsideClick: outsideClick,
		allowEscapeKey: outsideClick,
		showConfirmButton: showConfirm,
		showCancelButton: showCancel,
		cancelButtonText: tr("Cancel"),
		type: alertType,
		animation: false,
		html: true
	});
}

/* caShowTemplateDiff / caHideTemplateDiff / caRenderDiff and friends moved to
   javascript/diff.js — loaded only when developer mode is enabled (the
   <script> tag in Apps.page is wrapped in a caSettings['dev'] PHP guard). */

/* Cache fitText results keyed by class + text content + overFlowType so each
   unique label is measured only once across the entire session. The shrink
   loop calls isOverflown which forces layout/reflow on every iteration, and
   ribbon labels (INSTALLED / UPDATED / Blacklisted / etc.) repeat across
   every page render — without caching we'd re-measure them constantly. */
window.caFitTextCache = window.caFitTextCache || {};
/**
 * Shrink the font-size of each matched element in 10% steps until its contents
 * no longer overflow (using {@link isOverflown}). Results are memoized in
 * `window.caFitTextCache` keyed by class, text, overflow axis, and box size so
 * repeated ribbon labels are measured only once.
 *
 * @param {boolean} [overFlowType=false] When truthy, check horizontal overflow; otherwise vertical.
 * @returns {jQuery} The original jQuery collection for chaining.
 */
jQuery.fn.fitText = function(overFlowType=false) {
	var el = this;
	var cache = window.caFitTextCache;
	$(el).each(function() {
		/* Geometry must be part of the cache key — overflow depends on the
		   element's box, so the same label/class can need different sizes in a
		   wider vs narrower container after a layout change. */
		var w = Math.round(this.clientWidth || 0);
		var h = Math.round(this.clientHeight || 0);
		var key = (this.className || "") + "|" + ((this.textContent || "").trim()) + "|" + (overFlowType ? 1 : 0) + "|" + w + "x" + h;
		if (Object.prototype.hasOwnProperty.call(cache, key)) {
			var cached = cache[key];
			if (cached !== 100) $(this).css("font-size", cached + "%");
			return;
		}
		var test = 100;
		while (isOverflown(this, overFlowType)) {
			test = test - 10;
			if (test < 10) break;
			$(this).css("font-size", test + "%");
		}
		cache[key] = test;
	});
	return el;
}

/**
 * Clone the matched element's contents into `#sidenavContent`, reset the
 * sidenav scroll position, and open the alternate-view sidebar.
 *
 * Used to project a hidden template (e.g. settings/statistics) into the
 * sidebar overlay. Calls the global {@link showAlternateView} after copying.
 *
 * @returns {jQuery} The original jQuery collection for chaining.
 */
jQuery.fn.showAlternateView = function() {
	const $src = $(this);
	const $dest = $("#sidenavContent");
	$dest.empty().append($src.contents().clone(true, true));
	/* `.sidenav` is the overflow-y:scroll container; `#sidenavContent` is just
	   the inner wrapper (no scrollbar of its own), so resetting scrollTop on
	   the wrapper is a no-op. Reset the actual scroller so alternate views
	   always open at the top. */
	$dest.closest(".sidenav").scrollTop(0);
	showAlternateView();
	return this;
}

/**
 * Get the rendered pixel width of the first matched element.
 *
 * @param {boolean} [everything=true] When true, include horizontal padding and margins; when false, content width only.
 * @returns {number} Width in pixels.
 */
jQuery.fn.getWidth = function(everything=true) {
	var width = $(this).css("width").replace("px","");
	if ( ! everything ) {
		return parseInt(width);
	}
	var paddingLeft = $(this).css("padding-left").replace("px","");
	var paddingRight = $(this).css("padding-right").replace("px","");
	var marginLeft = $(this).css("margin-left").replace("px","");
	var marginRight = $(this).css("margin-right").replace("px","");
	return parseInt(width) + parseInt(paddingLeft) + parseInt(paddingRight) + parseInt(marginLeft) + parseInt(marginRight);
}

/**
 * Attach context-menu items (text, divider, external link, or `eval` action) to `el` via `context.attach`.
 *
 * @param {Array<object>} menu Awesomplete/context menu descriptor rows
 * @param {string|JQuery} el Selector or element the menu binds to
 */
function setupContext(menu,el) {
	if ( ! menu ) return;
	var opts = [];
	menu.forEach(function(item,index){
		if ( item.text ) {
			item.text = tr(item.text);
		}
		if ( item.divider ) {
			opts.push({divider:true});
		} else {
			if ( item.link ) {
				opts.push({text:item.text,icon:item.icon,href:item.link,target:'_blank'});
			} else {
				if ( item.action ) {
					opts.push({text:item.text,icon:item.icon,action:function(){
						eval(item.action);
					}});
				}
			}
		}
	});
	if ( opts.length > 0 ) {
		context.attach(el,opts);
	}
}

/**
 * Read the computed value of a CSS custom property on `:root`.
 *
 * @param {string} varName Property name including leading `--`.
 * @returns {string} Computed value (may include leading whitespace).
 */
function cssVar(varName) {
	return window.getComputedStyle(document.documentElement).getPropertyValue(varName);
}

/**
 * True when the unified .ca_modal_overlay scrim is currently up — i.e. the
 * sidebar, mobile menu, search modal, or an nchan-flavored swal is showing.
 * Use from any handler that needs to bail out while a CA modal is active
 * instead of repeating the trigger-class union in each handler.
 *
 * Excludes MagnificPopup, which paints its own .mfp-bg scrim — callers that
 * also need to gate on mfp should add a separate `$(".mfp-bg").length` check.
 */
function caIsModalOverlayUp() {
	var $overlay = $(".ca_modal_overlay");
	return $overlay.length > 0 && $overlay.css("pointer-events") === "auto";
}

/**
 * Invoke `cb` whenever the `class` attribute of any matched element mutates.
 *
 * Sets up a `MutationObserver` per element scoped to `attributeFilter:['class']`.
 *
 * @param {function(HTMLElement, string): void} cb Callback receiving the changed element and its current `className`.
 * @returns {jQuery} The original jQuery collection for chaining.
 */
$.fn.onClassChange = function(cb) {
	return $(this).each((_, el) => {
		new MutationObserver(mutations => {
			mutations.forEach(mutation => cb && cb(mutation.target, mutation.target.className));
		}).observe(el, {
			attributes: true,
			attributeFilter: ['class'] // only listen for class attribute changes
		});
	});
}
/**
 * Invoke `callback` (bound to the element) whenever any matched element
 * transitions to not-intersecting the viewport.
 *
 * Uses `IntersectionObserver` when available; logs a console warning on older
 * browsers and silently does nothing. The observer is stashed on
 * `$.data('visibilityObserver')` for later cleanup by callers.
 *
 * @param {Function} callback Called with `this` = the element that became hidden.
 * @returns {jQuery} The original jQuery collection for chaining.
 */
$.fn.onVisibilityHidden = function(callback) {
	return this.each(function() {
		const $element = $(this);

		if ('IntersectionObserver' in window) {
			const observer = new IntersectionObserver(function(entries) {
				entries.forEach(function(entry) {
					if (!entry.isIntersecting) {
						// Element is hidden/not visible
						callback.call(entry.target);
					}
				});
			}, {
				threshold: 0
			});

			observer.observe(this);

			// Store observer for cleanup
			$element.data('visibilityObserver', observer);
		} else {
			// Fallback for older browsers
			console.warn('IntersectionObserver not supported');
		}
	});
};

/**
 * Persist CA UI state via {@link saveState}, used as the unload hook when
 * Dynamix GUI Search navigates away from the page. saveState() writes to
 * sessionStorage (was cookies pre-refactor); this is the only place where
 * a save fires off-flow from showSidebarApp.
 *
 * @returns {void}
 */
function guiSearchOnUnload() {
	saveState();
}

/**
 * Update `data.*` pagination fields from server-computed nav payload. The
 * UI is fully infinite-scroll; the only thing this still does is keep
 * `data.nextpage` / `data.totalApps` (and friends) accurate so the scroll
 * trigger and display-count math have the truth from the server.
 *
 * @param {object} navigationData `pageNumber`, `totalApps`, `maxPerPage`, `dockerSearch`
 */
function caRenderPageNavigation(navigationData) {
	var nav = navigationData || {};
	var totalApps = Math.max(0, parseInt(nav.totalApps, 10) || 0);
	var maxPerPage = Math.max(1, parseInt(nav.maxPerPage, 10) || 1);
	var totalPages = Math.max(1, Math.ceil(totalApps / maxPerPage));
	var pageNumber = Math.min(Math.max(1, parseInt(nav.pageNumber, 10) || 1), totalPages);

	data.currentpage = pageNumber;
	data.prevpage = pageNumber - 1;
	data.nextpage = (pageNumber < totalPages) ? (pageNumber + 1) : 0;
	data.totalApps = totalApps;
}

/** Sum of offsetTop along offsetParent from el up to ancestor (layout; stable when inner scroll moves getBoundingClientRect). */
function caOffsetTopWithinAncestor(el, ancestor) {
	var y = 0;
	var n = el;
	while (n && n !== ancestor) {
		y += n.offsetTop;
		n = n.offsetParent;
	}
	return n === ancestor ? y : null;
}

/**
 * Cards per request for infinite-scroll / pagination (fixed batch size).
 *
 * @returns {number}
 */
/**
 * Measure the outer box (border-box + margins) of an app card. Prefers a real
 * rendered `.ca_holder`; when none exists yet (first load) it appends a hidden
 * throwaway one so the very first fetch is already viewport-sized. The result
 * is cached on `window._caCardDims` for calls made while the grid is empty
 * (e.g. between views).
 *
 * @returns {{w:number,h:number}|null}
 */
function caMeasureCardDims() {
	/* Card size scales with font-size / zoom, so the fallback cache is keyed to
	   the current viewport geometry — a measurement taken at one size is never
	   reused after a resize/zoom. */
	var key = (window.innerWidth || 0) + "x" + (window.innerHeight || 0);
	var cached = window._caCardDims;

	/* Outer box (border-box + margins) of one card, or null when the element is
	   missing or reports a zero box. The zero guard matters: a hidden card such
	   as the display:none #sampleApp probe measures 0x0, and an early first
	   paint can briefly measure a real card at 0 before layout settles. Either
	   case must fall through to the visibility:hidden temp probe (which still
	   lays out at the fixed .ca_holder CSS size) rather than returning a bogus 0
	   that makes getMaxPerPage silently drop to its DEFAULT page size. */
	var measure = function(el) {
		if (!el) return null;
		var r = el.getBoundingClientRect();
		var cs = getComputedStyle(el);
		var w = r.width + (parseFloat(cs.marginLeft) || 0) + (parseFloat(cs.marginRight) || 0);
		var h = r.height + (parseFloat(cs.marginTop) || 0) + (parseFloat(cs.marginBottom) || 0);
		return (w > 0 && h > 0) ? { w: w, h: h, key: key } : null;
	};

	/* Prefer a real rendered card in the grid. */
	var dims = measure($("#templates_content .ca_holder").first()[0]);

	/* None usable (empty grid, or a zero-box sample): drop a throwaway hidden
	   .ca_holder into the live grid host and measure that. Not the stray
	   $(".ca_holder") fallback we used before — that could latch onto the
	   display:none #sampleApp and keep measuring 0. */
	if (!dims) {
		var $host = $("#templates_content .ca_templatesDisplay").first();
		if (!$host.length) $host = $("#templates_content").first();
		if ($host.length) {
			var $temp = $("<div class='ca_holder'></div>").css("visibility", "hidden").appendTo($host);
			dims = measure($temp[0]);
			$temp.remove();
		}
	}

	if (dims) {
		window._caCardDims = dims;
		return dims;
	}
	return (cached && cached.key === key) ? cached : null;
}
/**
 * Cards to fetch per request: roughly one viewport's worth plus a row of
 * overscan (minimum 12). Sizing each page to the viewport means infinite scroll
 * and backfill pull a screenful per request instead of dribbling 12 at a time,
 * which also gives a fast fling more runway before it reaches a spacer.
 *
 * Stays stable across a session's fetches (the offset/backfill math and server
 * pagination are page-number based); it is only re-evaluated at fresh-query
 * start, and a viewport change forces a fresh re-render anyway.
 *
 * @returns {number}
 */
/**
 * Inner width of the card grid plus the main-area viewport height, or null when
 * the main area isn't present yet. Shared by getMaxPerPage() and caCardsPerRow()
 * so the grid-width lookup (with the home/templates fallbacks) lives in one place.
 *
 * @returns {{gridW:number,viewH:number}|null}
 */
function caMeasureGrid() {
	var ma = $(".mainArea")[0];
	if (!ma) return null;
	var $grid = $("#templates_content .ca_templatesDisplay").first();
	if (!$grid.length) $grid = $("#templates_content").first();
	var gridW = ($grid.length ? $grid[0].clientWidth : 0) || ma.clientWidth;
	return { gridW: gridW, viewH: ma.clientHeight };
}

function getMaxPerPage() {
	var DEFAULT = 12;
	/* Upper bound so 4K / ultrawide viewports don't request hundreds of cards in
	   one fetch (server load + DOM bloat). ~6 viewports of a 12-card layout. */
	var MAX_PER_PAGE = 150;
	var grid = caMeasureGrid();
	if (!grid || grid.gridW <= 0 || grid.viewH <= 0) return DEFAULT;
	var dims = caMeasureCardDims();
	if (!dims || dims.w <= 0 || dims.h <= 0) return DEFAULT;
	var perRow = Math.max(1, Math.floor(grid.gridW / dims.w));
	var rows = Math.max(1, Math.ceil(grid.viewH / dims.h));
	/* ~2 viewports of cards per page (plus a row of overscan). A single fetch
	   then covers a thumb-drag / track-click that lands mid-page without a blank
	   gap, and gives forward/backward scroll extra runway before hitting a
	   spacer. Minimum 12. */
	return Math.min(MAX_PER_PAGE, Math.max(DEFAULT, perRow * (rows * 2 + 1)));
}

/**
 * Cards that fit across one row of the main grid at the current viewport / zoom.
 * Same per-row math as getMaxPerPage(): grid inner width divided by one card's
 * outer (margin-box) width. Used to size the home page sections so each shows a
 * full row of apps rather than a fixed count. Returns 0 when geometry can't be
 * measured yet (caller falls back to its minimum).
 *
 * @returns {number}
 */
function caCardsPerRow() {
	var grid = caMeasureGrid();
	if (!grid || grid.gridW <= 0) return 0;
	var dims = caMeasureCardDims();
	if (!dims || dims.w <= 0) return 0;
	return Math.max(1, Math.floor(grid.gridW / dims.w));
}

/**
 * Override Unraid GUI search hotkey (Cmd/Ctrl+K) while CA is present,
 * so it opens CA search modal instead of Dynamix GUI search.
 */
function caInitGlobalSearchHotkeyOverride() {
	if (window.ca_globalSearchHotkeyOverrideInit) return;
	window.ca_globalSearchHotkeyOverrideInit = true;

	/* Capture phase is required so this beats Dynamix's own GUI-search hotkey handler;
	   jQuery does not expose the capture flag, so this listener stays native. */
	window.addEventListener("keydown", function(e) {
		try {
			var k = e && (e.key || e.keyCode);
			var isK = (k === "k" || k === "K" || k === 75);
			if (!isK) return;
			if (e.shiftKey || e.altKey) return;

			var isMac = (navigator.appVersion || "").indexOf("Mac") !== -1;
			var wants = isMac ? e.metaKey : e.ctrlKey;
			if (!wants) return;

			/* Always block Cmd/Ctrl+K so Dynamix's GUI-search never opens, even when CA can't act on it. */
			e.preventDefault();
			if (typeof e.stopImmediatePropagation === "function") e.stopImmediatePropagation();
			if (typeof e.stopPropagation === "function") e.stopPropagation();

			/* Swallow whenever a CA modal scrim is up. ca_searchModalOpen is
			   exempted so it falls through to the refocus path below.
			   MagnificPopup (.mfp-bg) and SweetAlert (.showSweetAlert) paint
			   their own scrims — separate checks. */
			if (caIsModalOverlayUp() && !$("body").hasClass("ca_searchModalOpen")) return;
			if ($(".mfp-bg").length) return;
			if ($(".showSweetAlert").length) return;

			/* Only open CA search when it's actually present on the page. */
			if (!$("#searchBox").length || typeof caOpenSearchModal !== "function") return;

			if ($("body").hasClass("ca_searchModalOpen")) {
				try { $("#searchBox").trigger("focus"); } catch (err) { /* no-op */ }
			} else {
				try { caOpenSearchModal(); } catch (err) { /* no-op */ }
			}
		} catch (err) { /* no-op */ }
	}, true);
}

/* jQuery's $(fn) covers both not-ready (queues) and already-ready (runs immediately). */
try {
	$(caInitGlobalSearchHotkeyOverride);
} catch (err) { /* no-op */ }

/**
 * HTML-attribute escape for sanitizer output. Single-quote-safe so it can be
 * dropped into `attr='...'` without worrying about the source string.
 */
function caEscapeAttr(s) {
	return String(s == null ? "" : s)
		.replace(/&/g, "&amp;")
		.replace(/</g, "&lt;")
		.replace(/>/g, "&gt;")
		.replace(/"/g, "&quot;")
		.replace(/'/g, "&#39;");
}

/**
 * Browser-side mirror of PHP's caIsPublicHttpUrl (include/helpers.php). Rejects
 * non-http(s) schemes, malformed URLs, and any host that resolves to loopback /
 * RFC1918 / link-local / CGNAT / IPv6 ULA / IPv6 link-local — plus the
 * decimal / hex IPv4 bypasses (`http://2130706433/` for 127.0.0.1, etc.) that
 * some browsers still resolve. Used by caSanitizeReadme to drop README links
 * that would point a click at the user's own GUI or LAN.
 *
 * DNS-rebinding-style attacks (a public hostname that resolves to a LAN IP)
 * are out of scope here — same threat model as the PHP side.
 *
 * @param {string} url
 * @returns {boolean}
 */
function caIsPublicHttpUrlJs(url) {
	if (typeof url !== "string" || url === "") return false;
	var u;
	try { u = new URL(url); } catch (e) { return false; }
	if (u.protocol !== "http:" && u.protocol !== "https:") return false;
	var host = u.hostname.toLowerCase();
	if (host === "") return false;
	/* URL.hostname already strips the brackets from IPv6 literals, but be
	   defensive in case a non-spec input ever lands here. */
	if (host.charAt(0) === "[" && host.charAt(host.length - 1) === "]") {
		host = host.substring(1, host.length - 1);
	}
	if (host === "localhost" || host === "0" || host === "0.0.0.0" || host === "::" || host === "::1" || host === "0:0:0:0:0:0:0:1") return false;
	/* IPv4 private/loopback/link-local/CGNAT ranges. Lifted into a reusable
	   block so the IPv4-mapped IPv6 case below can re-run the same checks
	   against the embedded v4. */
	function isPrivateIPv4(v4) {
		if (/^127(?:\.\d{1,3}){3}$/.test(v4)) return true;
		if (/^10(?:\.\d{1,3}){3}$/.test(v4)) return true;
		if (/^172\.(?:1[6-9]|2\d|3[01])(?:\.\d{1,3}){2}$/.test(v4)) return true;
		if (/^192\.168(?:\.\d{1,3}){2}$/.test(v4)) return true;
		if (/^169\.254(?:\.\d{1,3}){2}$/.test(v4)) return true; // link-local
		if (/^100\.(?:6[4-9]|[7-9]\d|1[01]\d|12[0-7])(?:\.\d{1,3}){2}$/.test(v4)) return true; // CGNAT 100.64/10
		return false;
	}
	if (isPrivateIPv4(host)) return false;
	/* Decimal-encoded IPv4 — a single integer that resolves to the 127/8 range. */
	if (/^\d+$/.test(host)) {
		var n = parseInt(host, 10);
		if (n === 0) return false;
		if (n >= 2130706432 && n <= 2147483647) return false;
	}
	/* Hex-encoded IPv4 (0x7f000001 etc.) — same 127/8 range. */
	if (/^0x[0-9a-f]+$/i.test(host)) {
		var hn = parseInt(host, 16);
		if (hn >= 2130706432 && hn <= 2147483647) return false;
	}
	/* IPv4-mapped IPv6 — the URL constructor normalizes `::ffff:10.0.0.1` to the
	   hex form `::ffff:a00:1` before we ever see it, so we have to match that
	   shape and reconstruct the dotted v4 ourselves. Keep the dotted-form
	   regex as a fallback for hosts that arrived non-normalized (other engines,
	   manually-constructed strings, etc.). Both forms re-run isPrivateIPv4 so
	   a hostile `http://[::ffff:10.0.0.1]/` is treated the same as `http://10.0.0.1/`. */
	var v4mappedHex = host.match(/^::ffff(?::0)?:([0-9a-f]{1,4}):([0-9a-f]{1,4})$/i);
	if (v4mappedHex) {
		var hi = parseInt(v4mappedHex[1], 16);
		var lo = parseInt(v4mappedHex[2], 16);
		var v4 = [(hi >> 8) & 0xff, hi & 0xff, (lo >> 8) & 0xff, lo & 0xff].join(".");
		if (isPrivateIPv4(v4)) return false;
	}
	var v4mappedDotted = host.match(/^::ffff(?::0)?:((?:\d{1,3}\.){3}\d{1,3})$/i);
	if (v4mappedDotted && isPrivateIPv4(v4mappedDotted[1])) return false;
	/* IPv6 ULA (fc00::/7 — first byte 0xfc or 0xfd) and link-local (fe80::/10
	   — fe8x / fe9x / feax / febx). A `:` is required to distinguish from a
	   hostname like "fc-domain.com". */
	if (/^f[cd][0-9a-f]{0,2}:/i.test(host)) return false;
	if (/^fe[89ab][0-9a-f]{0,2}:/i.test(host)) return false;
	/* mDNS (.local) and the conventional "internal" pseudo-TLDs resolve to LAN
	   hosts without DNS — same threat surface as RFC1918 above. `(^|\.)name$`
	   anchors so both `foo.local` and the bare `local` / `internal` / etc.
	   are blocked. Matches PHP caIsPrivateOrLoopbackHost. */
	if (/(^|\.)local$/.test(host)) return false;
	if (/(^|\.)(internal|intranet|lan|home|corp|private)$/.test(host)) return false;
	return true;
}

/**
 * Convert CA's sidebar-search markup tokens — `//term&#92;` (literal `//`,
 * term, literal `&#92;` or `\` depending on whether marked passed the entity
 * through) — into clickable anchors that fire `doSidebarSearch(term)`. Port
 * of PHP `caApplySidebarSearchLinks` (skin_helpers.php), kept inline-onclick
 * to match the convention used by ModeratorComment / CAComment which still
 * emit server-side.
 *
 * Run this AFTER caSanitizeReadme — the constructed anchor is trusted markup
 * (the term is escaped, the JS arg is JSON-encoded then attribute-escaped) so
 * it doesn't need DOMPurify, and running before would mean DOMPurify strips
 * the onclick.
 *
 * @param {string} html
 * @returns {string}
 */
function caApplySidebarSearchLinksJs(html) {
	if (typeof html !== "string" || html === "") return html;
	return html.replace(/\/\/(.*?)(?:&#92;|\\)/g, function(match, term) {
		/* Sanity-check the captured term before building the anchor: empty
		   matches and pathological lengths get left as literal text. The
		   downstream JSON.stringify + caEscapeAttr makes XSS via term content
		   impossible, but a real maintainer search hint is ~30 chars; 200 is
		   generous. Control chars (incl. embedded newlines) become a confusing
		   search filter, so reject them too — the literal pattern stays
		   visible in the rendered text so a broken token is obvious. */
		if (term === "" || term.length > 200) return match;
		if (/[\x00-\x1f\x7f]/.test(term)) return match;
		var safeText = caEscapeAttr(term);
		/* JSON.stringify gives a valid JS string literal with surrounding
		   double quotes; caEscapeAttr then makes the result safe inside the
		   single-quoted onclick attribute. The browser decodes the HTML
		   entities back to actual quote characters before handing the value
		   to the JS engine, so doSidebarSearch sees the original term. */
		var safeJsArg = caEscapeAttr(JSON.stringify(term));
		/* Accessibility: an anchor with no href drops out of the tab order, so
		   keyboard users couldn't reach this control. tabindex='0' restores
		   tab focus and role='button' tells assistive tech what it is. The
		   onkeydown handler fires on Enter (default for buttons) AND Space
		   (which would otherwise scroll the page) — the `&quot;` entities
		   decode to `"` once the browser parses the attribute, so the JS
		   engine ends up seeing event.key === "Enter" / " ". */
		return "<a style='cursor:pointer;' role='button' tabindex='0' onclick='doSidebarSearch(" + safeJsArg + ");' onkeydown='if(event.key===&quot;Enter&quot;||event.key===&quot; &quot;){event.preventDefault();doSidebarSearch(" + safeJsArg + ");}'>" + safeText + "</a>";
	});
}

/**
 * Decode the five XML predefined entities plus numeric character references.
 * Custom DTD entities (e.g. `&name;` from a .plg DOCTYPE) are intentionally
 * left as-is — CHANGES blocks rarely reference them and a full DTD parse
 * would dwarf the win. If a real case shows up, mirror the dev-modal pipeline
 * in diff.js (caExtractXmlEntities) for the same handling.
 */
function caXmlEntityDecode(s) {
	if (typeof s !== "string" || s === "") return "";
	/* String.fromCodePoint throws RangeError for values outside [0, 0x10FFFF],
	   and malformed XML can carry `&#99999999;` or similar — one bad entity
	   inside a remote README/CHANGES would otherwise crash the whole render.
	   Filter to the valid Unicode codepoint range; out-of-band values drop
	   to "" rather than propagating the exception. */
	function safeFromCodePoint(n) {
		if (!isFinite(n) || n < 0 || n > 0x10FFFF) return "";
		try { return String.fromCodePoint(n); } catch (e) { return ""; }
	}
	return s
		.replace(/&amp;/g, "&")
		.replace(/&lt;/g, "<")
		.replace(/&gt;/g, ">")
		.replace(/&quot;/g, "\"")
		.replace(/&apos;/g, "'")
		.replace(/&#x([0-9a-f]+);/gi, function(_, h) { return safeFromCodePoint(parseInt(h, 16)); })
		.replace(/&#([0-9]+);/g, function(_, d) { return safeFromCodePoint(parseInt(d, 10)); });
}

/**
 * Parse `<!ENTITY name "value">` declarations out of an XML document's DOCTYPE.
 *
 * Direct port of PHP `caExtractXmlEntities` (include/exec.php) — predefined
 * entities are decoded inside the captured values, then cross-references
 * between entities (`<!ENTITY full "&name;-&version;">`) are resolved with a
 * 5-pass fixed-point loop so the returned table is already fully expanded by
 * the time the caller uses it. Returns `{}` when no DOCTYPE / no declarations.
 *
 * Regex-based on purpose: malformed DTDs in real-world .plg files would make
 * a strict DOMParser pass fail; we'd rather get whatever entities we can read.
 *
 * @param {string} xmlText
 * @returns {Object<string,string>}
 */
function caExtractXmlEntitiesJs(xmlText) {
	var entities = {};
	if (typeof xmlText !== "string" || xmlText === "") return entities;
	var dtMatch = xmlText.match(/<!DOCTYPE[^\[]*\[([\s\S]*?)\]\s*>/);
	if (!dtMatch) return entities;
	var dtd = dtMatch[1];
	var entRe = /<!ENTITY\s+([A-Za-z_][\w.-]*)\s+(?:"([^"]*)"|'([^']*)')\s*>/g;
	var m;
	while ((m = entRe.exec(dtd)) !== null) {
		var name = m[1];
		var value = m[2] !== undefined ? m[2] : (m[3] !== undefined ? m[3] : "");
		/* Predefined entities first — same as PHP strtr() on extraction. */
		entities[name] = value
			.replace(/&amp;/g, "&")
			.replace(/&lt;/g, "<")
			.replace(/&gt;/g, ">")
			.replace(/&quot;/g, "\"")
			.replace(/&apos;/g, "'");
	}
	/* Resolve entity-in-entity references with the same 5-pass fixed-point loop
	   the PHP side uses. Self-references and unresolved names are left as-is. */
	var refRe = /&([A-Za-z_][\w.-]*);/g;
	for (var pass = 0; pass < 5; pass++) {
		var changed = false;
		for (var n in entities) {
			if (!Object.prototype.hasOwnProperty.call(entities, n)) continue;
			var current = entities[n];
			var selfName = n;
			var expanded = current.replace(refRe, function(match, refName) {
				if (refName === selfName) return match;
				return Object.prototype.hasOwnProperty.call(entities, refName) ? entities[refName] : match;
			});
			if (expanded !== current) {
				entities[n] = expanded;
				changed = true;
			}
		}
		if (!changed) break;
	}
	return entities;
}

/**
 * Pull the markdown text out of a .plg / template-XML CHANGES element with
 * full DTD entity substitution — `&version;`, `&name;`, etc. get expanded
 * using the DOCTYPE entity table before the markdown is rendered.
 *
 * Pipeline:
 *   1. Build the entity table from the DOCTYPE (caExtractXmlEntitiesJs already
 *      cross-resolves entity-in-entity refs so the values are flat).
 *   2. Regex-extract the inner text of `<CHANGES>` / `<Changes>` — case-
 *      insensitive so plugin (uppercase per .plg convention) and container
 *      template (mixed-case) shapes both match.
 *   3. Substitute custom entity references against the table; unknown names
 *      survive verbatim so the user sees what was broken.
 *   4. Decode predefined entities (`&amp;` → `&` etc.) via caXmlEntityDecode.
 *      Done last so a value like `https://example.com/?a=1&amp;b=2` survives
 *      the custom-substitution pass intact and decodes cleanly here.
 *
 * Regex over DOMParser everywhere — real-world .plg files sometimes have
 * malformed DTDs that fail strict parsing, and we'd rather show *most* of a
 * changelog than nothing.
 *
 * @param {string} xmlText Raw .plg or .xml content
 * @returns {string} Inner markdown text of the CHANGES element, "" if missing
 */
function caExtractChangesFromXml(xmlText) {
	if (typeof xmlText !== "string" || xmlText === "") return "";
	var m = xmlText.match(/<CHANGES\b[^>]*>([\s\S]*?)<\/CHANGES>/i);
	if (!m) return "";
	var raw = m[1];
	/* Unwrap any CDATA sections — most .plg files wrap their CHANGES markdown
	   in `<![CDATA[...]]>` so `<`/`>`/`&` don't need entity-escaping. The
	   server-side path used to get this for free via SimpleXML's LIBXML_NOCDATA;
	   our regex extraction has to strip the markers explicitly or the literal
	   `]]>` ends up in the rendered output. `g` flag handles multiple blocks. */
	raw = raw.replace(/<!\[CDATA\[([\s\S]*?)\]\]>/g, "$1");
	var entities = caExtractXmlEntitiesJs(xmlText);
	if (Object.keys(entities).length > 0) {
		raw = raw.replace(/&([A-Za-z_][\w.-]*);/g, function(match, refName) {
			return Object.prototype.hasOwnProperty.call(entities, refName) ? entities[refName] : match;
		});
	}
	return caXmlEntityDecode(raw).trim();
}

/**
 * Client-side replacement for PHP's caDownloadAndRenderReadme sanitizer.
 *
 * Three-pass pipeline — each layer is independently sufficient against most
 * attacks; the chain exists so a bypass in one stage doesn't reach the user:
 *
 *   1. stripTags() — same regex-based tag remover the rest of CA uses. Wipes
 *      every `<...>` construct before markdown sees it, so script/iframe/on*
 *      attrs / javascript:-URL embeds in raw HTML never exist post-strip.
 *      Markdown syntax can't *produce* those constructs, so what comes out of
 *      step 2 is structurally safe.
 *   2. marked() — render markdown to HTML. Loaded as dockerMan's markdown.js.
 *   3. DOMPurify.sanitize() — final defense-in-depth pass via libraries.js's
 *      bundled DOMPurify (Cure53). Catches anything an exotic markdown corner
 *      case might've smuggled through.
 *
 * Then a DOM post-walk enforces caIsPublicHttpUrlJs on every `<a href>` and
 * `<img src>` — DOMPurify's URL filtering blocks `javascript:`/`data:`/etc.
 * but doesn't know about LAN / RFC1918 / loopback IPs, and we don't want a
 * README link or auto-loading image pointing at the user's router admin.
 *
 * @param {string} rawMarkdown Raw README.md text from the source URL
 * @returns {string} Sanitized HTML ready to drop into the sidebar
 */
function caSanitizeReadme(rawMarkdown) {
	if (typeof rawMarkdown !== "string" || rawMarkdown === "") return "";
	if (typeof window.marked !== "function" || typeof window.DOMPurify === "undefined" || typeof window.DOMPurify.sanitize !== "function") {
		/* One of marked / DOMPurify failed to load — refuse to render anything
		   live and fall back to pre-formatted plain text. Better to show the
		   raw README than to ship un-sanitized HTML. */
		return "<pre>" + caEscapeAttr(rawMarkdown) + "</pre>";
	}

	/* Strip-tags via the project's existing helper — wipes raw HTML before
	   markdown sees it. After this, what reaches marked() is pure markdown
	   text and can't carry script/iframe/on*= attributes through. */
	var stripped = stripTags(rawMarkdown);

	/* Compat shim for the old PHP Markdown() renderer (which we used to call
	   server-side). That library accepted ATX headings without the required
	   space — `###2023.04.15` rendered as an h3 — and a lot of existing .plg
	   CHANGES blocks rely on it. marked is strict CommonMark / GFM and would
	   show the leading `###` as literal text. Insert the missing space so
	   those headings render the way maintainers expected. Multi-line flag is
	   essential — the regex must anchor at every line start, not just BOF.

	   The `(?!#)` is load-bearing: without it, `(#{1,6})` backtracks on input
	   like `## 2023.04.15` (a valid h2 — already has a space) to match just
	   the first `#` and pair it with the second `#` as the `\S` group, then
	   replaces `##` with `# #`, breaking a valid heading into `# # 2023…`
	   which marked renders as h1 with `#` content. The lookahead forces the
	   run of `#`s to be exact so backtracking can't fire. */
	stripped = stripped.replace(/^(#{1,6})(?!#)(\S)/gm, "$1 $2");

	var rendered;
	try {
		rendered = window.marked(stripped, { gfm: true, breaks: false });
	} catch (e) {
		return "<pre>" + caEscapeAttr(rawMarkdown) + "</pre>";
	}

	/* DOMPurify pass with its baseline html profile. ALLOWED_URI_REGEXP locks
	   schemes to http(s) — drops javascript:/data:/vbscript:/mailto:/etc.
	   before our post-walk even runs. The DOMPurify result is parseable HTML
	   (it builds a DOM internally), so we can walk it with the browser's own
	   parser for the public-IP enforcement below. */
	var clean = window.DOMPurify.sanitize(rendered, {
		USE_PROFILES: { html: true },
		ALLOWED_URI_REGEXP: /^https?:\/\//i
	});

	/* Post-walk: enforce caIsPublicHttpUrlJs on every surviving href / src and
	   add target=_blank + rel hardening to allowed anchors. Mirrors the PHP
	   server-side behavior — anchors get unwrapped (inner text survives) when
	   the href fails; images get removed entirely (no useful fallback). */
	var container = document.createElement("div");
	container.innerHTML = clean;

	$(container).find("a").each(function() {
		var $a = $(this);
		var href = $a.attr("href") || "";
		if (caIsPublicHttpUrlJs(href)) {
			$a.attr("target", "_blank").attr("rel", "noopener noreferrer");
		} else {
			/* Unwrap: replace the anchor with its children so the visible text
			   stays put but the click goes nowhere. Matches PHP behavior. */
			$a.replaceWith($a.contents());
		}
	});
	$(container).find("img").each(function() {
		var src = $(this).attr("src") || "";
		if (!caIsPublicHttpUrlJs(src)) {
			$(this).remove();
			return;
		}
		/* Privacy: README / Changes images auto-load when the sidebar paints,
		   and we don't want the user's Unraid URL leaking to whatever host the
		   maintainer chose. Matches the PHP-emitted icon/screenshot/etc tags. */
		$(this).attr("referrerpolicy", "no-referrer");
	});

	return container.innerHTML;
}

