/**
 * Validate and parse a value as a URL.
 * @param {string} url - The input to parse as a URL.
 * @returns {URL|false} The parsed `URL` object when `url` is a valid URL, `false` otherwise.
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
 * Remove HTML/XML tags from a string.
 * @param {string} str - The input string that may contain markup.
 * @returns {string} The input with all `<...>` tag sequences removed; returns an empty string when `str` is falsy.
 */
function stripTags(str) {
	if ( ! str )
		return "";

	return str.replace(/(<([^>]+)>)/ig,"");
}

var spinnerTimer = null;
/**
 * Schedules the global loading spinner to appear after a short delay.
 *
 * If a spinner show is already pending, this call is a no-op. When the delay
 * elapses the function makes visible the elements matching `div.spinner` and
 * `.spinnerBackground` and clears the pending timer.
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
 * Stops and hides the global loading spinner.
 *
 * Clears any scheduled spinner timer, resets the shared `spinnerTimer` handle,
 * hides spinner UI elements (`div.spinner` and `.spinnerBackground`), and
 * clears the `.long-loading` container's HTML.
 */
function myCloseSpinner() {
	clearTimeout(spinnerTimer);
	spinnerTimer = null;

	$("div.spinner,.spinnerBackground").hide();
	$(".long-loading").html("");
}

/**
 * Clear the current category selection.
 *
 * Resets data.selected_category to an empty string to restore the default (no-category) state.
 */
function enableButtons() {
	data.selected_category = "";
}

/**
 * Reloads display data for pages 1 through the current page, rebuilds the visible card list, and restores the previous scroll position.
 *
 * Clears incremental-load/search state, requests enough items to cover pages 1..currentpage, updates data.currentpage and data.maxPerPage to their saved values, replaces the displayed content with the fetched data, and restores the .mainArea scrollTop so the user's viewport remains at the same content position.
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
 * Produce the plural form of a word by appending "s" when the quantity is 0 or greater than 1.
 * @param {string} string - The singular word to pluralize.
 * @param {number} count - The quantity determining plurality.
 * @returns {string} The pluralized word when `count` is 0 or greater than 1, otherwise the original word.
 */
function makePlural(string,count) {
	return ( (count > 1) || (count == 0) ) ? string + "s" : string;
}

/**
 * Compare two tuple-like arrays by their first element for use with Array.prototype.sort.
 * @param {Array} a - First array/tuple whose element at index 0 will be compared.
 * @param {Array} b - Second array/tuple whose element at index 0 will be compared.
 * @returns {number} `-1` if `a[0] < b[0]`, `0` if `a[0] === b[0]`, `1` otherwise.
 */
function installSort(a,b) {
	if (a[0] === b[0]) {
		return 0;
	} else {
		return (a[0] < b[0]) ? -1 : 1;
	}
}

/**
 * Reloads the current document.
 */
function reloadPage() {
	location.reload();
}

/**
 * Checks whether an element's content overflows its bounds.
 * @param {Element} el - The element to test for overflow.
 * @param {boolean} [type=false] - When truthy, test horizontal overflow; otherwise test vertical overflow.
 * @returns {boolean} `true` if the element's content overflows in the checked direction, `false` otherwise.
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
 * Disable the search input control (#searchBox) and remove focus from it.
 */
function disableSearch() {
	$("#searchBox").prop("disabled",true);
	$("#searchBox").blur();
}

/**
 * Enables the main search input element.
 *
 * Re-enables the #searchBox input so users can type into it.
 */
function enableSearch() {
	$("#searchBox").prop("disabled",false);
}

/**
 * Aligns the CA search modal to the visible .mainArea by computing fixed top/left/width values.
 *
 * When the modal is open and .mainArea exists, computes coordinates from the element's
 * getBoundingClientRect() and the root font size, then sets the CSS variables
 * `--ca-search-modal-top`, `--ca-search-modal-left`, and `--ca-search-modal-width` on the
 * document root.
 */
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
 * Restore the committed search term into #searchBox when the input is empty and a committed term exists.
 * @returns {boolean} `true` if the committed term was restored into #searchBox, `false` otherwise.
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
 * Open the CA search modal and prepare the shared search input, its suggestion handling, and layout.
 *
 * Restores a committed search term into the input if needed, initializes suggestion input mode, updates
 * modal layout and window resize/orientation handlers, optionally focuses #searchBox, triggers Awesomplete
 * evaluation/kick routines, and ensures the suggestions dropdown is opened or closed based on the input length.
 *
 * @param {Object} [options] - Optional flags controlling open behavior.
 * @param {boolean} [options.noRefocus] - If true, do not move focus to #searchBox after opening.
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

/**
 * Force Awesomplete to re-evaluate the current #searchBox value.
 *
 * If the global `searchBoxAwesomplete` instance exposes an `evaluate()` method it is invoked.
 * Otherwise a native `"input"` event is dispatched on the `#searchBox` element (attempts `InputEvent` then falls back to `Event`).
 * The function does nothing if the search box element or the Awesomplete instance is not present.
 */
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
 * Refreshes the Awesomplete suggestion list for the search modal and closes the dropdown when input is below the minimum character threshold.
 *
 * Safely no-ops if the Awesomplete instance or search box is unavailable. Always triggers evaluation so any stale list items are cleared; if the current input length is less than Awesomplete's `minChars`, attempts to close the dropdown.
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
 * Initialize suggestion input mode so a mouse-hovered suggestion suppresses keyboard selection until arrow keys are used.
 *
 * Registers handlers that:
 * - mark the suggestion input as "mouse used" on suggestion mouseenter and keep the search input focused,
 * - clear the "mouse used" state on ArrowUp/ArrowDown so keyboard navigation resumes,
 * - in capture phase, force-focus the search input on Arrow keys while the modal is open,
 * - prevent Enter from accepting a hidden keyboard-selected suggestion when the mouse was used and no suggestion is hovered.
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
 * Reopens the CA search modal when the search box contains text so in-modal suggestions use the chip layout.
 *
 * If the search box is empty, attempts to restore a committed search term before deciding. When a term exists,
 * opens the modal without moving focus and repeatedly triggers Awesomplete evaluation (and optional population)
 * until suggestion data becomes available.
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
 * Closes the CA search modal and optionally discards the current draft in the input.
 *
 * When `options.discardDraft` is true, the input is restored to the committed search term
 * if one exists and there is an active search context; otherwise the input is cleared.
 * The function also removes modal state/classes, unregisters modal layout listeners,
 * closes the Awesomplete dropdown if present, blurs the search input, and updates the
 * search filter collapsed state.
 *
 * @param {Object} [options] - Optional flags controlling close behavior.
 * @param {boolean} [options.discardDraft=false] - If true, discard the current draft per the rules above.
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

/**
 * Show or hide the search-modal clear ("X") button based on the current input or an active search context.
 *
 * Reads the trimmed value of `#searchBox` and `data.committedSearchFilter`; if the input has text, or if the input is empty but a committed search exists while a search/docker context is active (`data.searchActive || data.searchFlag || data.docker`), the clear button is shown. Otherwise the clear button is hidden by toggling the `.ca_hide` class on `.searchModalClearBtn`.
 */
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

/**
 * Toggle the search filter bar between collapsed (icon-only) and expanded states based on whether the CA search modal is open.
 *
 * Also updates the modal clear button visibility after adjusting the collapsed state.
 */
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
 * Checks whether a value represents a truthy token: "true", "1", or "on".
 * @param {any} str - Value to test; converted to string before matching.
 * @returns {boolean} `true` if `str` (after trimming) equals `true`, `1`, or `on` (case-insensitive), `false` otherwise.
 */
function evaluateBoolean(str) {
	var regex=/^\s*(true|1|on)\s*$/i
	return regex.test(str);
}

/**
 * Determine whether the browser has cookies enabled.
 * @returns {boolean} `true` if cookies are enabled, `false` otherwise.
 */
function cookiesEnabled() {
	return evaluateBoolean(navigator.cookieEnabled);
}

/**
 * Scrolls the document and the primary content scroller to the top.
 *
 * Ensures both the page (html/body) and the CA main content element `.mainArea`
 * have their scroll position set to the top so view state is consistent.
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
 * Clear the home section subtitle and hide its element.
 *
 * Locates the element with id "ca_homeSectionSubtitle"; if found, removes its contents and adds the "ca_hide" class.
 */
function caClearHomeSectionSubtitle() {
	var $el = $("#ca_homeSectionSubtitle");
	if (!$el.length) return;
	$el.empty().addClass("ca_hide");
}

/**
 * Update the home section subtitle element with the provided text.
 *
 * Trims the input; if the result is empty, clears and hides the subtitle. If a non-empty string is provided,
 * sets it as the subtitle text and makes the element visible. No action is taken if the subtitle element is absent.
 *
 * @param {string} text - Text to display in the home section subtitle; may include surrounding whitespace.
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

/**
 * Update the Home subtitle to show the last committed search term (the value saved on submit), hiding the subtitle when no committed term exists.
 */
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

/**
 * Restore the last committed search term into the search box when the user has an unsaved draft.
 *
 * If a committed search term exists on the global `data` object and it differs from the current
 * value of `#searchBox`, this function overwrites the box with the committed term and updates
 * the search filter collapsed state.
 */
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
 * Translate a message key or literal into the current locale.
 * @param {string} string - The message key or text to translate.
 * @returns {string} The translated string for the current locale.
 */
function tr(string) {
 return _(string);
}

/**
 * Shows the full-page reload blocker overlay.
 *
 * Removes the `ca_hide` class from the element with id `caViewportBlocker` to reveal the overlay.
 * If the element is missing or an error occurs, the function fails silently.
 */
function caBlockViewportForReload() {
	try {
		$("#caViewportBlocker").removeClass("ca_hide");
	} catch(e) {}
}

/**
 * Shows a fatal reload banner (or alert) instructing the user to click or press a key to reload the page, and registers a one-time capture-phase handler to perform the reload.
 * @param {string} message - Custom message to show; when empty or omitted a default "Click anywhere to reload the page." message is used.
 * @param {*} _unusedDelay - Present for API compatibility but ignored.
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
 * Performs a POST request while suppressing the global spinner.
 *
 * If the first argument is a function it is treated as the callback and `options` will be an empty object.
 * This function sets `options.noSpinner = true` and delegates to `post`.
 *
 * @param {Object|Function} [options] - Request options object to send to the server, or the callback if omitted.
 * @param {Function} [callback] - Callback invoked with the server result.
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
 * Send a POST request to the server, manage global spinner state, execute any scripts returned by the server, and invoke a callback with the result.
 *
 * The function stamps a per-tab `tabId` onto `options` when available, increments/decrements a global `postCount` to drive spinner visibility (unless `options.noSpinner`), evaluates `result.script` and `result.globalScript` if present, and shows an error dialog on request failure (suppressed when `data.quittingUpdate` is true).
 *
 * @param {Object} [options] - Request payload and flags. Common keys: `action` (server action), `noSpinner` (boolean to suppress spinner), and optional `tabId` (automatically added when available).
 * @param {Function} [callback] - Called with the parsed server response object when the request succeeds.
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
 * Updates the SweetAlert update area with a status message and, if a spinner is visible, also updates the long-loading display.
 * @param {string} message - Text or HTML to display in the `.updateContent-swal` element (and in `.long-loading` when a visible `.spinner` exists).
 */
function slowPost(message) {
	$(".updateContent-swal").html(message);
	// this isn't working quite right
	if ( $(".spinner").is(":visible") ) {
		$(".long-loading").html(message);
	}
}

/**
 * Show a SweetAlert modal with the given title and HTML content and disable search input while open.
 *
 * @param {string} description - Modal title text.
 * @param {string} textdescription - Modal body content; interpreted as HTML.
 * @param {string} textimage - Accepted parameter for an image reference (currently unused by this implementation).
 * @param {string|number} imagesize - Accepted image size; if passed as empty string it defaults to "80" (currently not applied to the modal).
 * @param {boolean} outsideClick - Whether clicking outside or pressing Escape closes the modal.
 * @param {boolean} showCancel - Whether to display a Cancel button.
 * @param {boolean} showConfirm - Whether to display a Confirm button.
 * @param {string} alertType - SweetAlert `type` value (e.g., "warning", "error", "success", "info").
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
	$dest.empty().append($src.contents().clone(true, true)).scrollTop(0);
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
 * Attaches a contextual menu to an element based on a descriptor array.
 *
 * Each menu item may include:
 * - `text` (string): label (will be translated via `tr()` before use).
 * - `divider` (boolean): when true, inserts a divider.
 * - `link` (string): when present, renders an external link opened in a new tab.
 * - `action` (string): when present, evaluated as code when the item is activated.
 * - `icon` (string): optional icon identifier included with the item.
 *
 * Only non-empty option lists are attached via `context.attach`.
 *
 * @param {Array<Object>} menu - Array of menu item descriptors.
 * @param {Element|jQuery} el - Target DOM element (or jQuery-wrapped element) to attach the context menu to.
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
 * Retrieve the computed value of a CSS custom property from the document root.
 * @param {string} varName - The CSS variable name, including the leading `--` (e.g. `--my-color`).
 * @returns {string} The computed value of the CSS variable as returned by getComputedStyle.
 */
function cssVar(varName) {
	return window.getComputedStyle(document.documentElement).getPropertyValue(varName);
}

/**
 * Determine whether the CA modal overlay scrim is currently visible and interactive.
 *
 * Checks for a `.ca_modal_overlay` element that is present and has `pointer-events: auto`.
 * This excludes MagnificPopup scrims (`.mfp-bg`); callers that must also consider MagnificPopup
 * should check for `.mfp-bg` separately.
 *
 * @returns {boolean} `true` if the CA modal overlay scrim exists and accepts pointer events, `false` otherwise.
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

/**
 * Save CA UI state when the Unraid GUI search causes navigation away from the page.
 */
function guiSearchOnUnload() {
	saveState();
}

/**
 * Builds pagination HTML for the given navigation state using the #caPageNavigationTemplate.
 *
 * @param {Object} nav - Pagination options and state.
 * @param {number|string} [nav.pageNumber] - Current page index (1-based). Non-numeric values default to 1.
 * @param {number|string} [nav.totalApps] - Total number of items across pages. Non-numeric values default to 0.
 * @param {number|string} [nav.maxPerPage] - Items per page. Non-numeric or values <1 default to 1.
 * @param {string} [nav.pageFunction] - Name of the client-side page handler; "dockerSearch" selects that handler, any other value selects "changePage".
 * @param {number|string} [nav.maxMiddlePages] - Maximum number of contiguous middle page links to display; non-numeric values default to 3.
 * @returns {string} HTML string containing the rendered pagination controls, or an empty string if the #caPageNavigationTemplate is not present.
 */
function caBuildPageNavigationHtml(nav) {
	var pageNumber = parseInt(nav.pageNumber, 10) || 1;
	var totalApps = Math.max(0, parseInt(nav.totalApps, 10) || 0);
	var maxPerPage = Math.max(1, parseInt(nav.maxPerPage, 10) || 1);
	var totalPages = Math.max(1, Math.ceil(totalApps / maxPerPage));
	pageNumber = Math.min(Math.max(1, pageNumber), totalPages);
	var pageFunction = nav.pageFunction === "dockerSearch" ? "dockerSearch" : "changePage";
	var maxMiddlePages = Math.max(1, parseInt(nav.maxMiddlePages, 10) || 3);
	var halfMiddlePages = Math.floor(maxMiddlePages / 2);
	var startingPage = Math.max(1, Math.min(pageNumber - halfMiddlePages, totalPages - maxMiddlePages + 1));
	var endingPage = Math.min(totalPages, startingPage + maxMiddlePages - 1);
	var $template = $("#caPageNavigationTemplate");
	if (!$template.length) return "";
	var $nav = $template.children(".pageNavigation").first().clone();
	var $templates = $template.find(".caPageNavTemplates").first();
	var $left = $nav.find(".pageLeft");
	var $right = $nav.find(".pageRight");
	var fixedMiddleSlots = maxMiddlePages + 4; // first + left dots + middle pages + right dots + last
	var middleItems = [];
	var i;

	if (pageNumber === 1) {
		$left.addClass("pageNavNoClick");
	} else {
		$left.addClass("pageNavClick").attr("data-page", pageNumber - 1).attr("data-page-function", pageFunction);
	}

	var appendLink = function(page) {
		var $link = $templates.find(".caPageNavLink").first().clone();
		$link.text(page).attr("data-page", page).attr("data-page-function", pageFunction);
		middleItems.push($link);
	};
	var appendSelected = function(page) {
		var $selected = $templates.find(".caPageNavSelected").first().clone();
		$selected.text(page);
		middleItems.push($selected);
	};
	var appendDots = function() {
		middleItems.push($templates.find(".caPageNavDots").first().clone());
	};
	var createSpacer = function() {
		var $spacer = $templates.find(".caPageNavSelected").first().clone();
		$spacer
			.addClass("pageNavSpacer")
			.removeClass("pageSelected")
			.attr("aria-hidden", "true")
			.html("&nbsp;");
		return $spacer;
	};

	if (startingPage > 1) {
		appendLink(1);
		if (startingPage > 2) {
			appendDots();
		}
	}

	for (i = startingPage; i <= endingPage; i++) {
		if (i === pageNumber) {
			appendSelected(i);
		} else {
			appendLink(i);
		}
	}

	if (endingPage < totalPages) {
		if (endingPage < (totalPages - 1)) {
			appendDots();
		}
		appendLink(totalPages);
	}

	var missingMiddleItems = Math.max(0, fixedMiddleSlots - middleItems.length);
	var leadingSpacers = Math.floor(missingMiddleItems / 2);
	var trailingSpacers = missingMiddleItems - leadingSpacers;

	for (i = 0; i < leadingSpacers; i++) {
		$right.before(createSpacer());
	}

	for (i = 0; i < middleItems.length; i++) {
		$right.before(middleItems[i]);
	}

	for (i = 0; i < trailingSpacers; i++) {
		$right.before(createSpacer());
	}

	if (pageNumber < totalPages) {
		$right.addClass("pageNavClick").attr("data-page", pageNumber + 1).attr("data-page-function", pageFunction);
	} else {
		$right.addClass("pageNavNoClick");
	}

	return $("<div>").append($nav).html();
}

/**
 * Render pagination controls into the element with the given ID and update pagination state.
 *
 * Updates global pagination state fields on `data` (`currentpage`, `prevpage`, `nextpage`, `totalApps`), and replaces the target element's HTML with the pagination built by `caBuildPageNavigationHtml`. If the target element does not exist, or if there is only one page (or a small Docker search result set), the target is cleared and hidden.
 *
 * @param {string} targetId - ID of the container element to render pagination into (without `#`).
 * @param {Object} [navigationData] - Pagination parameters.
 * @param {number} [navigationData.totalApps] - Total number of items available.
 * @param {number} [navigationData.maxPerPage] - Maximum items per page (used to compute total pages).
 * @param {number} [navigationData.pageNumber] - Desired current page (will be clamped into the valid range).
 * @param {boolean} [navigationData.dockerSearch] - When true, apply Docker-specific suppression rules for small result sets.
 */
function caRenderPageNavigation(targetId, navigationData) {
	var $target = $("#" + targetId);
	if (!$target.length) return;
	var nav = navigationData || {};
	var totalApps = Math.max(0, parseInt(nav.totalApps, 10) || 0);
	var maxPerPage = Math.max(1, parseInt(nav.maxPerPage, 10) || 1);
	var totalPages = Math.max(1, Math.ceil(totalApps / maxPerPage));
	var pageNumber = Math.min(Math.max(1, parseInt(nav.pageNumber, 10) || 1), totalPages);
	var isDockerSearch = !!nav.dockerSearch;

	data.currentpage = pageNumber;
	data.prevpage = pageNumber - 1;
	data.nextpage = (pageNumber < totalPages) ? (pageNumber + 1) : 0;
	data.totalApps = totalApps;

	if ((isDockerSearch && totalApps <= 25) || totalApps < 2 || totalPages <= 1) {
		$target.empty();
		$target.removeClass("ca_navVisible");
		return;
	}

	nav.pageNumber = pageNumber;
	nav.totalPages = totalPages;
	$target.html(caBuildPageNavigationHtml(nav));
	$target.addClass("ca_navVisible");
	$target.find(".caPageNavLink,.caPageNavSelected").fitText(true);
}

/**
 * Compute the vertical offset of an element relative to a given ancestor by summing offsetTop through the offsetParent chain.
 * @param {HTMLElement} el - The descendant element whose offset is measured.
 * @param {HTMLElement} ancestor - The ancestor element to measure against.
 * @returns {number|null} The distance in pixels from the ancestor's top to the element's top, or `null` if `ancestor` is not found in the chain.
 */
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
 * Provide the configured number of items to request per page for pagination.
 *
 * @returns {number} The number of items fetched per request (12).
 */
function getMaxPerPage() {
	/* Pagination is now infinite-scroll: always fetch 12 per request. */
	return 12;
}

/**
 * Install a one-time global capture-phase handler for Cmd/Ctrl+K that opens or focuses the CA search modal.
 *
 * The function is idempotent and attaches a native keydown listener which prevents the default Dynamix GUI search
 * behavior and, when the CA search UI is available, either focuses the search input or opens the CA search modal.
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

