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

String.prototype.escapeHTML = function() {
	return this.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

Array.prototype.uniqueArrayElements = function() {
	var uniqueEntries = new Array();
	$.each(this, function(i, el) {
		if ($.inArray(el,uniqueEntries) === -1) {
		 uniqueEntries.push(el)
		}
	});
	return uniqueEntries;
}

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

var spinnerTimer = null;

/**
 * Show the global spinner overlay after a short delay (avoids flicker on fast responses).
 */
function mySpinner() {
	if ( ! spinnerTimer ) {
		spinnerTimer = setTimeout(function() {
			spinnerTimer = null;
			$("div.spinner,.spinnerBackground").show();
		}, 250);
	}
}

/**
 * Cancel pending spinner delay and hide overlay.
 */
function myCloseSpinner() {
	clearTimeout(spinnerTimer);
	spinnerTimer = null;

	$("div.spinner,.spinnerBackground").hide();
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
	location.reload();
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

/**
 * Clear optional subtitle under the Home section header.
 */
function caClearHomeSectionSubtitle() {
	var $el = $("#ca_homeSectionSubtitle");
	if (!$el.length) return;
	$el.empty().addClass("ca_hide");
}

/**
 * Set or clear `#ca_homeSectionSubtitle` (empty text hides).
 *
 * @param {string} text Plain text
 */
function caSetHomeSectionSubtitle(text) {
	var $el = $("#ca_homeSectionSubtitle");
	if (!$el.length) return;
	var t = $.trim(String(text || ""));
	if (!t) {
		caClearHomeSectionSubtitle();
		return;
	}
	$el.text(t).removeClass("ca_hide");
}

/** Line under Home: last committed app search (after Enter/submit), not draft typing (non-clickable). */
function caSyncHomeSearchSubtitle() {
	var $el = $("#ca_homeSearchSubtitle");
	if ($el.length && typeof data !== "undefined" && data) {
		var v = $.trim(String(data.committedSearchFilter || ""));
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
			var $homeBtn = $(".startupButton").first();
			if ($homeBtn.length) {
				$homeBtn.trigger("click");
			} else {
				window.location.reload();
			}
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
 * Same as {@link post} but forces `noSpinner` (logging prefix "No Spin Post").
 *
 * @param {object|function} options POST payload or callback when first arg is the callback
 * @param {function} [callback]
 */
function postNoSpin(options,callback) {
	var msg = "No Spin Post: ";
	console.log(msg+JSON.stringify(options));
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
		console.log(msg+JSON.stringify(options));
	}
	if ( ! options || typeof options !== "object" ) {
		options = {};
	}
	/* Stamp the per-tab id on every request so paths.php can suffix the cache
	   files for this tab. Skipped only if the caller already supplied one. */
	if (options && typeof options === "object" && !options.tabId && typeof data !== "undefined" && data.tabId) {
		options.tabId = data.tabId;
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
		/* Suppress the "browser failed to communicate" swal when the user clicked
		   EXIT on the updating-applications popup — the failure is just the
		   in-flight POST being aborted by the impending history.back() nav. */
		if (data.quittingUpdate) return;
		swal({
			title: tr("Browser failed to communicate with Unraid Server"),
			text: tr('For unknown reasons, your browser was unable to communicate with Community Applications running on your server.')+"<br><br>"+tr("Additional information may be within Tools, PHPSettings - View Log"),
			html: true,
			type: 'error',
			showCancelButton: true,
			showConfirmButton: true,
			cancelButtonText: tr("Cancel"),
			confirmButtonText: tr('Attempt to Fix Via Reload Page')
		}, function (isConfirm) {
			if ( isConfirm ) {
				window.location.reload();
			} else {
				history.back();
			}
		});
	});
}

/**
 * Push status HTML into the updating-content swal when spinner visible.
 *
 * @param {string} message HTML/text
 */
function slowPost(message) {
	$(".updateContent-swal").html(message);
	// this isn't working quite right
	if ( $(".spinner").is(":visible") ) {
		$(".long-loading").html(message);
	}
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

/* Cache fitText results keyed by class + text content + overFlowType so each
   unique label is measured only once across the entire session. The shrink
   loop calls isOverflown which forces layout/reflow on every iteration, and
   ribbon labels (INSTALLED / UPDATED / Blacklisted / etc.) repeat across
   every page render — without caching we'd re-measure them constantly. */
window.caFitTextCache = window.caFitTextCache || {};
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

// Get a CSS variable value from the document root
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

// Watch for class changes on an element
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
// Watch for a visibility change
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

// Save the state of CA if GUI Search takes us away from the page
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
function getMaxPerPage() {
	/* Pagination is now infinite-scroll: always fetch 12 per request. */
	return 12;
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

