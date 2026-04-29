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

function stripTags(str) {
	if ( ! str )
		return "";

	return str.replace(/(<([^>]+)>)/ig,"");
}

var spinnerTimer = null;
function mySpinner() {
	if ( ! spinnerTimer ) {
		spinnerTimer = setTimeout(function() {
			spinnerTimer = null;
			$("div.spinner,.spinnerBackground").show();
		}, 250);
	}
}

function myCloseSpinner() {
	clearTimeout(spinnerTimer);
	spinnerTimer = null;

	$("div.spinner,.spinnerBackground").hide();
	$(".long-loading").html("");
}

function enableButtons() {
	data.selected_category = "";
}

function refreshDisplay() {
	changeSortOrder(null,null,null);
}

function makePlural(string,count) {
	return ( (count > 1) || (count == 0) ) ? string + "s" : string;
}

function installSort(a,b) {
	if (a[0] === b[0]) {
		return 0;
	} else {
		return (a[0] < b[0]) ? -1 : 1;
	}
}

function reloadPage() {
	location.reload();
}

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


function disableSearch() {
	$("#searchBox").prop("disabled",true);
	$("#searchBox").blur();
}

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
	$("#caSearchModalBackdrop").removeClass("ca_hide");
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
	$("#caSearchModalBackdrop").addClass("ca_hide");
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


function evaluateBoolean(str) {
	regex=/^\s*(true|1|on)\s*$/i
	return regex.test(str);
}

function cookiesEnabled() {
	return evaluateBoolean(navigator.cookieEnabled);
}

function scrollToTop() {
	$('html,body').animate({scrollTop:0},0);
}

function caClearHomeSectionSubtitle() {
	var $el = $("#ca_homeSectionSubtitle");
	if (!$el.length) return;
	$el.empty().addClass("ca_hide");
}

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

function tr(string) {
 return _(string);
}

function caBlockViewportForReload() {
	try {
		$("#caViewportBlocker").removeClass("ca_hide");
	} catch(e) {}
}

function caShowFatalReloadBanner(message, reloadDelayMs) {
	try {
		if (window.ca_reloadPending) return;
		window.ca_reloadPending = true;
		try {
			if (typeof closeSidebar === "function") closeSidebar(true, true);
		} catch(e) {}
		var ms = parseInt(reloadDelayMs, 10);
		if (!ms || ms < 0) ms = 10000;
		var msg = (typeof message === "string" && message) ? message : tr("An error occurred. Reloading the page...");

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
		setTimeout(doHomeReload, ms);
	} catch(e) {
		setTimeout(function() {
			var $homeBtn = $(".startupButton").first();
			if ($homeBtn.length) {
				$homeBtn.trigger("click");
			} else {
				window.location.reload();
			}
		}, 10000);
	}
}

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

function post(options,callback) {
	if ( typeof options === "function" ) {
		callback = options;
	} else {
		var msg = postCount > 0 ? "Embedded Post: " : "Post: ";
		console.log(msg+JSON.stringify(options));
	}
	if ( ! options.noSpinner ) {
		if ( postCount == 0) {
			if ( ! $(".sweet-overlay").is(":visible") ) {
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
		myCloseSpinner();
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

function slowPost(message) {
	$(".updateContent-swal").html(message);
	// this isn't working quite right
	if ( $(".spinner").is(":visible") ) {
		$(".long-loading").html(message);
	}
}

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

jQuery.fn.fitText = function(overFlowType=false) {
	var el = this;
	$(el).each(function() {
		var test = 100;
		while (isOverflown(this,overFlowType)) {
			test = test - 10;
			if ( test < 10 ) {
				break;
			}
			$(this).css("font-size",test+"%");
		}
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

function getMaxPerPage() {
	const $caDisplayArea = $(".ca_display_area").first();
	if (!$caDisplayArea.length) return 0;

	/* Reset the JS-driven compact-cards override before measuring so the sample
	   card reflects its default (non-compact) size. We re-apply below if the
	   resulting column count would drop under 3 and .Theme--responsive exists
	   anywhere in the DOM. */
	const isResponsive = $(".Theme--responsive").length > 0;
	if (isResponsive) $("body").removeClass("ca-compact-cards");

	/* We need a measurable .ca_holder inside #templates_content > .ca_templatesDisplay. If either
	   .ca_templatesDisplay is absent or it has no .ca_holder descendant, replace #templates_content's
	   html with the #sampleApp markup (which is already wrapped in .ca_templatesDisplay containing a
	   .ca_holder). This covers both the initial empty-page state and the post-"No Matching
	   Applications Found" state — without it, maxPerPage returns 0 and the next search dumps every
	   result onto page 1. */
	const $templatesContent = $("#templates_content");
	if (!$templatesContent.length) return 0;

	const $existingDisplay = $templatesContent.find(".ca_templatesDisplay").first();
	const needsSample = !$existingDisplay.length || !$existingDisplay.find(".ca_holder").length;
	if (needsSample) {
		const $sampleApp = $("#sampleApp");
		if ($sampleApp.length) {
			$templatesContent.html($sampleApp.html());
		}
	}

	try {
		const $sample = $templatesContent.find(".ca_holder").first();
		const sample = $sample.length ? $sample[0] : null;
		if (!sample) return 0;

		const rect = sample.getBoundingClientRect();
		const style = getComputedStyle(sample);
		const fullWidth = rect.width + (parseFloat(style.marginLeft) || 0) + (parseFloat(style.marginRight) || 0);
		const fullHeight = rect.height + (parseFloat(style.marginTop)  || 0) + (parseFloat(style.marginBottom) || 0);

		const $templatesDisplay = $templatesContent.find(".ca_templatesDisplay").first();
		const templatesDisplay = $templatesDisplay.length ? $templatesDisplay[0] : null;
		if (!templatesDisplay) return 0;
		const templatesContent = $templatesContent[0];
		const caDisplayArea = $caDisplayArea[0];
		const tRect = templatesContent.getBoundingClientRect();
		const displayRect = caDisplayArea.getBoundingClientRect();
		const remPx = parseFloat(getComputedStyle(caDisplayArea).fontSize) || 16;

		const availableWidth = templatesDisplay.getBoundingClientRect().width;
		var templatesTopInDisplay = caOffsetTopWithinAncestor(templatesContent, caDisplayArea);
		if (templatesTopInDisplay == null) {
			templatesTopInDisplay = tRect.top - displayRect.top;
		}
		const availableHeight = displayRect.height - templatesTopInDisplay - remPx;

		if (availableWidth <= 0 || availableHeight <= 0 || !fullWidth || fullWidth <= 0) return 0;

		let perRow = Math.floor(availableWidth  / fullWidth);
		let perCol = Math.floor(availableHeight / fullHeight);

		/* If the natural card size can't fit three columns — or fits exactly three
		   but only a single row — and Theme--responsive is active, switch to the
		   compact card size and remeasure. */
		if (isResponsive && (perRow < 3 || (perRow === 3 && perCol === 1))) {
			$("body").addClass("ca-compact-cards");
			const compactRect = sample.getBoundingClientRect();
			const compactStyle = getComputedStyle(sample);
			const compactWidth  = compactRect.width  + (parseFloat(compactStyle.marginLeft) || 0) + (parseFloat(compactStyle.marginRight)  || 0);
			const compactHeight = compactRect.height + (parseFloat(compactStyle.marginTop)  || 0) + (parseFloat(compactStyle.marginBottom) || 0);
			if (compactWidth > 0 && compactHeight > 0) {
				perRow = Math.floor(availableWidth  / compactWidth);
				perCol = Math.floor(availableHeight / compactHeight);
			}
		}

		if (perRow < 3) return 4;
		return perRow * perCol;
	} catch (err) {
		return 0;
	}
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

			/* Swallow without opening CA search if the sidebar or a SweetAlert overlay is showing. */
			if ($(".sidenavShow, .sidebarShow, .sidebarshow").length) return;
			if ($(".sweet-overlay").is(":visible")) return;

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

