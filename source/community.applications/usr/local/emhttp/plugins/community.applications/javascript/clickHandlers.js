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
 * @file Wires click handlers, scrollbars, and interactive UI behavior for Community Applications.
 */

/**
 * Register delegated `body`/`document` handlers for CA (cards, plugins, moderation, sorting, etc.).
 * Call once after DOM ready.
 */
function caInitializeClickHandlers() {
	/**
	 * Install fixed-position overlay scrollbars on the main panes so they
	 * remain visible without the Firefox-style auto-hide behavior.
	 *
	 * Creates per-target horizontal/vertical thumbs inside `#ca_fixed_scroll_root`,
	 * wires drag, wheel, hover, and DOM-mutation handlers, and keeps the mainArea
	 * vertical indicator sized against `data.totalApps` so it reflects full
	 * virtual-list progress (not just loaded DOM rows).
	 */
	function caInitFirefoxFixedHorizontalOverlay() {
		var selector = ".menuItems, .ca_homeTemplates, .mainArea, .sidenav, .ca_diffCol";
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

		/**
		 * Get the bounding rect of `.mainArea`, or null if it is missing or zero-sized.
		 *
		 * @returns {DOMRect|null}
		 */
		var getMainAreaClientRect = function() {
			var m = document.querySelector(".mainArea");
			if (!m) return null;
			var r = m.getBoundingClientRect();
			if (r.width <= 0 || r.height <= 0) return null;
			return r;
		};

		/**
		 * Whether the horizontal track for `elRect` intersects the `.mainArea`
		 * rect (so the overlay should be drawn).
		 *
		 * Horizontal overlay is drawn along the bottom edge of the scroll target;
		 * hide it when that track does not intersect .mainArea (eg. mobile menu /
		 * other panes outside the main pane).
		 *
		 * @param {DOMRect} elRect Bounding rect of the scroll target.
		 * @returns {boolean}
		 */
		var hTrackIntersectsMainArea = function(elRect) {
			var mainRect = getMainAreaClientRect();
			if (!mainRect) return true;
			var hLeft = elRect.left;
			var hRight = elRect.left + elRect.width;
			var hTop = elRect.bottom - trackThicknessPx;
			var hBottom = elRect.bottom;
			return hLeft < mainRect.right && hRight > mainRect.left && hTop < mainRect.bottom && hBottom > mainRect.top;
		};

		/**
		 * True when `el` is the menu items pane and the mobile menu is showing,
		 * meaning its indicators should stay visible.
		 *
		 * @param {HTMLElement} el
		 * @returns {boolean}
		 */
		var shouldAlwaysShowMenuIndicators = function(el) {
			return !!(el && el.classList && el.classList.contains("menuItems") && $(".mobileMenu").hasClass("menuShowing"));
		};

		/**
		 * Cancel a pending hide-indicator timer for `el`.
		 *
		 * @param {HTMLElement} el
		 */
		var clearHideTimer = function(el) {
			var t = hideTimers.get(el);
			if (t) {
				clearTimeout(t);
				hideTimers.delete(el);
			}
		};

		/**
		 * Mark `el`'s overlay indicators as `.visible` and clear any hide timer.
		 *
		 * @param {HTMLElement} el
		 */
		var showIndicator = function(el) {
			var entry = overlays.get(el);
			if (!entry) return;
			clearHideTimer(el);
			if (entry.$hIndicator && entry.$hIndicator.is(":visible")) entry.$hIndicator.addClass("visible");
			if (entry.$vIndicator && entry.$vIndicator.is(":visible")) entry.$vIndicator.addClass("visible");
		};

		/**
		 * Schedule the indicators on `el` to fade out after 250ms unless the user
		 * is still interacting with them (dragging, hovering, or hovering the pane).
		 *
		 * @param {HTMLElement} el
		 */
		var hideIndicatorSoon = function(el) {
			var entry = overlays.get(el);
			if (!entry) return;
			if (shouldAlwaysShowMenuIndicators(el)) return;
			if (entry.dragging || entry.overlayHover || $(el).is(":hover")) return;
			clearHideTimer(el);
			hideTimers.set(el, setTimeout(function() {
				// Don't hide if user is still interacting/hovering the scroll target.
				if (shouldAlwaysShowMenuIndicators(el)) return;
				if (entry.dragging || entry.overlayHover || $(el).is(":hover")) return;
				if (entry.$hIndicator) entry.$hIndicator.removeClass("visible");
				if (entry.$vIndicator && !entry.alwaysShowVertical) entry.$vIndicator.removeClass("visible");
			}, 250));
		};

		/**
		 * Reposition and resize the overlay thumbs for `el` from its current
		 * scroll metrics and bounding rect; hides indicators when they no longer
		 * apply. For the always-on `.mainArea` vertical track, sizes against
		 * `data.totalApps` and `caCardCache` so the thumb tracks full-result-set
		 * progress instead of just the DOM slice.
		 *
		 * @param {HTMLElement} el
		 */
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
				/* Skip the mainArea-intersection check for diff columns — the
				   diff overlay covers the viewport and the cols extend below
				   .mainArea's bottom, so the intersection test always fails
				   and the horizontal indicator never appears. */
				var hSkipMainAreaTest = el.classList.contains("ca_diffCol");
				if (!hasHorizontal || (!hSkipMainAreaTest && !hTrackIntersectsMainArea(rect))) {
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

					/* Read-only "true progress" mode for the always-on mainArea bar:
					   the local scroll only covers the loaded+un-evicted slice of cards,
					   so size/position the thumb against data.totalApps (the full result
					   set, kept current by caRenderPageNavigation's data.* updates).
					   Fall back to the local calc above if any required signal is missing. */
					if (entry.alwaysShowVertical && typeof data !== "undefined" && data.totalApps > 0 && Array.isArray(caCardCache.cache)) {
						var $virtCards = $("#templates_content .ca_templatesDisplay").find(".ca_holder");
						var perRow = (typeof caVirtCardsPerRow === "function") ? caVirtCardsPerRow($virtCards) : 1;
						var rowH = (typeof caVirtRowHeight === "function") ? caVirtRowHeight($virtCards, perRow) : 0;
						if (perRow > 0 && rowH > 0) {
							var firstInDom = (typeof caCardCache.firstInDom === "number") ? caCardCache.firstInDom : 0;
							var scrolledRows = Math.max(0, Math.floor(el.scrollTop / rowH));
							var firstVisibleGlobal = firstInDom + (scrolledRows * perRow);
							var rowsVisible = Math.max(1, Math.ceil(el.clientHeight / rowH));
							var visibleCount = Math.min(data.totalApps, rowsVisible * perRow);
							var fractionSize = visibleCount / data.totalApps;
							var fractionPos = firstVisibleGlobal / data.totalApps;
							thumbHeight = Math.max(20, Math.min(trackHeight, trackHeight * fractionSize));
							vMaxTop = Math.max(0, trackHeight - thumbHeight);
							vTop = Math.max(0, Math.min(vMaxTop, trackHeight * fractionPos));
						}
					}

					entry.$vThumb.css({
						height: thumbHeight + "px",
						transform: "translateY(" + vTop + "px)"
					});
				}
			}

			if (shouldAlwaysShowMenuIndicators(el)) {
				if (entry.$hIndicator && entry.$hIndicator.is(":visible")) entry.$hIndicator.addClass("visible");
				if (entry.$vIndicator && entry.$vIndicator.is(":visible")) entry.$vIndicator.addClass("visible");
			} else if (el.classList.contains("menuItems") && !entry.dragging && !entry.overlayHover && !$(el).is(":hover")) {
				if (entry.$hIndicator) entry.$hIndicator.removeClass("visible");
				if (entry.$vIndicator) entry.$vIndicator.removeClass("visible");
			}
		};

		/**
		 * Build and wire the overlay scrollbar(s) for `el`.
		 *
		 * Creates horizontal/vertical thumbs (when needed), registers them in
		 * the `overlays` Map, and binds mousedown/wheel/scroll/hover handlers
		 * for drag, click-to-scroll, wheel forwarding, and visibility. No-op
		 * when an overlay already exists for `el` or the element does not
		 * actually overflow.
		 *
		 * @param {HTMLElement} el
		 */
		var attachOverlay = function(el) {
			if (overlays.has(el)) return;

			var hasHorizontal = (el.scrollWidth - el.clientWidth) > 1;
			var hasVertical = (el.scrollHeight - el.clientHeight) > 1;
			// Sidebar should never show the horizontal overlay scrollbar.
			var allowHorizontal = !el.classList.contains("sidenav");
			/* Diff view: left column shares scrollTop with the right via the
			   sync in diff.js, so we only render one vertical indicator (on
			   the right column). Both columns still get their horizontal
			   indicator since the lines on each side scroll independently. */
			var allowVertical = !(el.classList.contains("ca_diffCol") && el.matches(".ca_diffCol:first-child"));
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
				alwaysShowVertical: el.classList.contains("mainArea"),
				scrollFxTimer: null
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
			/* Diff overlay sits at z-index 1001 — tag these indicators so the
			   CSS lifts them above the overlay (the default #ca_fixed_scroll_root
			   parks at 1000 alongside everything else). */
			if (el.classList.contains("ca_diffCol")) {
				if (entry.$hIndicator) entry.$hIndicator.addClass("ca_scroll_diff");
				if (entry.$vIndicator) entry.$vIndicator.addClass("ca_scroll_diff");
			}
			if (entry && entry.alwaysShowVertical) {
				if (entry.$vIndicator) entry.$vIndicator.addClass("ca_mainarea_v_always visible");
			}
			$(el).addClass("ca_custom_scroll_target");

			/**
			 * Begin a thumb drag along `axis`, binding document-level mousemove/mouseup
			 * to translate pointer delta into `el.scrollLeft`/`scrollTop`.
			 *
			 * @param {"x"|"y"} axis Drag axis.
			 * @param {MouseEvent} downEvent The mousedown that started the drag.
			 */
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

				/**
				 * Mousemove handler for the active drag: convert pointer delta to a
				 * scrollLeft/scrollTop delta and update the indicator.
				 *
				 * @param {MouseEvent} moveEvent
				 */
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

				/**
				 * Mouseup handler ending the active drag: unbind move/up listeners,
				 * restore body `user-select`, clear dragging state, and queue hide.
				 */
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
			/* The overlay indicators sit on top of the scroll target with
			   pointer-events:auto (so clicks/drags work), which means trackpad
			   and wheel gestures over the bar don't reach the underlying pane.
			   Forward the wheel delta into the scroll target so hovering the
			   bar feels just like hovering the content. */
			if (entry.$hIndicator) entry.$hIndicator.on("wheel", function(e) {
				var oe = e.originalEvent;
				if (!oe) return;
				var dx = oe.deltaX || 0;
				var dy = oe.deltaY || 0;
				if (!dx && !dy) return;
				var modeX = (oe.deltaMode === 1 ? 16 : (oe.deltaMode === 2 ? el.clientWidth  : 1));
				var modeY = (oe.deltaMode === 1 ? 16 : (oe.deltaMode === 2 ? el.clientHeight : 1));
				if (Math.abs(dx) >= Math.abs(dy)) {
					/* Horizontal intent — scroll the strip the indicator belongs to. */
					el.scrollLeft += dx * modeX;
				} else {
					/* Vertical intent — forward to the nearest scrollable ancestor
					   (typically .mainArea) so the page scrolls as if the bar
					   weren't catching the event. */
					var v = el.parentElement;
					while (v && v !== document.body) {
						var oy = getComputedStyle(v).overflowY;
						if ((oy === "auto" || oy === "scroll") && v.scrollHeight - v.clientHeight > 1) {
							v.scrollTop += dy * modeY;
							break;
						}
						v = v.parentElement;
					}
					if (!v || v === document.body) {
						window.scrollBy(0, dy * modeY);
					}
				}
				e.preventDefault();
			});
			if (entry.$vIndicator) entry.$vIndicator.on("wheel", function(e) {
				var oe = e.originalEvent;
				if (!oe || !oe.deltaY) return;
				var delta = oe.deltaY;
				if (oe.deltaMode === 1) delta *= 16;
				else if (oe.deltaMode === 2) delta *= el.clientHeight;
				el.scrollTop += delta;
				e.preventDefault();
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
				if (current.$vIndicator) current.$vIndicator.addClass("ca_scroll_active");
				if (current.$hIndicator) current.$hIndicator.addClass("ca_scroll_active");
				if (current.scrollFxTimer) clearTimeout(current.scrollFxTimer);
				current.scrollFxTimer = setTimeout(function() {
					var latest = overlays.get(el);
					if (!latest) return;
					if (latest.$vIndicator) latest.$vIndicator.removeClass("ca_scroll_active");
					if (latest.$hIndicator) latest.$hIndicator.removeClass("ca_scroll_active");
				}, 450);
				if (current.dragging || current.overlayHover || $(el).is(":hover")) showIndicator(el);
				hideIndicatorSoon(el);
				/* Single hook so .ca_back_to_top / .ca_move_to_end visibility
				   updates uniformly for whichever pane the user is scrolling. */
				if (typeof window.caUpdateScrollControls === "function") {
					window.caUpdateScrollControls();
				}
			});
			$(el).on("mouseenter", function() {
				updateIndicator(el);
				showIndicator(el);
				var entry = overlays.get(el);
				if (entry) {
					if (entry.$hIndicator) entry.$hIndicator.addClass("ca_scroll_target_hover");
					if (entry.$vIndicator) entry.$vIndicator.addClass("ca_scroll_target_hover");
				}
			});
			$(el).on("mouseleave", function() {
				hideIndicatorSoon(el);
				var entry = overlays.get(el);
				if (entry) {
					if (entry.$hIndicator) entry.$hIndicator.removeClass("ca_scroll_target_hover");
					if (entry.$vIndicator) entry.$vIndicator.removeClass("ca_scroll_target_hover");
				}
			});
			updateIndicator(el);
		};

		/**
		 * Attach overlays to any new matching scroll targets, remove overlays
		 * for elements that no longer match, and update the rest. Used as the
		 * DOM-mutation/resize tick.
		 */
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
		/**
		 * Coalesce multiple DOM-mutation events into a single
		 * `refreshTargets()` call on the next animation frame.
		 */
		var queueRefresh = function() {
			if (refreshQueued) return;
			refreshQueued = true;
			requestAnimationFrame(function() {
				refreshQueued = false;
				refreshTargets();
			});
		};
		/* Filter out mutations on our own overlay nodes — this module toggles
		   .visible/.dragging/.ca_scroll_active inside #ca_fixed_scroll_root and
		   would otherwise re-queue refreshTargets() on every hover/scroll. */
		var domObserver = new MutationObserver(function(mutations) {
			for (var i = 0; i < mutations.length; i++) {
				var t = mutations[i].target;
				if (!t) continue;
				if (t.id === "ca_fixed_scroll_root") continue;
				if (t.nodeType === 1 && $(t).closest("#ca_fixed_scroll_root").length) continue;
				queueRefresh();
				return;
			}
		});
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
		/**
		 * Legacy external-link guard: intercept clicks on anchors, `.ca_href`,
		 * and `.dockerPopup`, classify the destination, and either follow it,
		 * silently open it (allowed/internal Unraid domains), or prompt the
		 * user with a SweetAlert before opening. Remembers approved hosts in
		 * the `allowedDomains` cookie so subsequent clicks bypass the prompt.
		 *
		 * @param {jQuery.Event} e
		 */
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
			var parsedHref = null;
			try { parsedHref = new URL(href, window.location.origin); } catch (err) { parsedHref = null; }
			var isInternalUnraid = false;
			if (parsedHref && (parsedHref.protocol === "http:" || parsedHref.protocol === "https:")) {
				var parsedHost = parsedHref.hostname.toLowerCase();
				if (parsedHost === "unraid.net" || parsedHost === "myunraid.net" || parsedHost === "lime-technology.com" || parsedHost.endsWith(".unraid.net") || parsedHost.endsWith(".myunraid.net") || parsedHost.endsWith(".lime-technology.com")) {
					isInternalUnraid = true;
				}
			}
			if (isInternalUnraid) {
				if (ca_href) {
					e.stopPropagation();
					e.preventDefault();
					window.open(href, target);
				}
				return;
			}
			if (href === "#" || href.toLowerCase().indexOf("javascript") === 0 || href.toLowerCase().indexOf("data:") === 0 || href.toLowerCase().indexOf("vbscript:") === 0) return;
			var dom = isValidURL(href);
			/* Protocol-relative URLs (//example.com) — isValidURL returns false because
			   `new URL` requires a base, but parsedHref already resolved it above.
			   Promote parsedHref to dom so the external-link warning still fires. */
			if (dom === false && href.indexOf("//") === 0 && parsedHref) {
				dom = parsedHref;
			}
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
				if (dockerHub || ca_href) {
					if (ca_href) {
						e.stopPropagation();
						e.preventDefault();
					}
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
			var escapeHtml = function(s) { return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;"); };
			var hrefSafe = escapeHtml(href);
			var hostSafe = escapeHtml(host);
			swal({
				title: tr("External Link"),
				text: "<span title='" + hrefSafe + "'>" + tr("Clicking OK will take you to a 3rd party website not associated with Limetech") + "<br><br><b>" + hrefSafe + "<br><br><input id='Link_Always_Allow' type='checkbox'></input>" + tr("Always Allow") + " " + hostSafe + "</span>",
				html: true,
				type: "warning",
				showCancelButton: true,
				allowOutsideClick: true,

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
	$(".closeMenuButton").on("click", function() { closeMenu(); });

	/* Try to click the nchan swal's own "Done" button so the lib's close path
	   runs (cleans up the SSE subscription, etc.) instead of leaving an
	   orphaned half-open swal. Only fires if showSweetAlert.nchan is present
	   AND the Done button (and its container) is visible AND not disabled —
	   avoids closing the swal mid-progress when the lib intentionally hides/
	   disables Done while work is in flight. Returns true iff a click fired. */
	function caTryClickNchanDone() {
		if ( ! $(".sweet-alert.showSweetAlert.nchan").length) return false;
		var $container = $(".sa-confirm-button-container:visible").first();
		if ( ! $container.length) return false;
		var $doneBtn = $container.find("button:visible:not(:disabled)").filter(function() {
			return $(this).html() === tr("Done");
		}).first();
		if ( ! $doneBtn.length) return false;
		$doneBtn.trigger("click");
		return true;
	}

	/**
	 * Dispatch a click on the unified `.ca_modal_overlay` scrim to the
	 * close-action for whichever modal is currently open. Priority order:
	 * nchan SweetAlert > search modal > sidebar > mobile menu.
	 */
	$(".ca_modal_overlay").on("click", function() {
		if ($(".sweet-alert.showSweetAlert.nchan").length) {
			caTryClickNchanDone();
			return;
		}
		if ($("body").hasClass("ca_searchModalOpen")) {
			caCloseSearchModal({ discardDraft: true });
			return;
		}
		if ($(".sidenav").hasClass("sidenavShow")) {
			/* Same close-cascade the old .sidebarClose handler used. */
			if ($(".moderationContainer").length) {
				showStatistics();
			} else if ( ! $(".popUpBack").hasClass("ca_hide")) {
				$(".popUpBack").click();
			} else {
				closeSidebar();
			}
			return;
		}
		if ($(".mobileMenu").hasClass("menuShowing")) {
			closeMenu();
			return;
		}
	});

	/* SweetAlert paints its own .sweet-overlay backdrop on top of our scrim. A
	   click on it should also trigger the nchan Done close path — body-level
	   delegation since the lib can recreate .sweet-overlay across showings. */
	$("body").on("click", ".sweet-overlay", function() {
		caTryClickNchanDone();
	});

	/* MagnificPopup arrows have the .mfp-prevent-close class so the lib's own
	   _checkIfClose() should swallow their bubble — but in mixed-type galleries
	   (image + iframe) we've seen the close fire anyway. Belt-and-suspenders:
	   stop the click from bubbling past the arrow so the wrap-level close
	   handler never runs. The arrow's own click still navigates first. */
	$("body").on("click", ".mfp-arrow", function(e) {
		e.stopPropagation();
	});

	/* #ca_mobile_layout_probe is in-viewport iff max-width 1024px layout (--mobileDevice true); see responsive.css. */
	if (!window.__caMobileLayoutMenuSync) {
		window.__caMobileLayoutMenuSync = true;
		/**
		 * Remove `menuShowing` (and add `menuHidden`) on adjustable layout
		 * elements when the viewport leaves the mobile breakpoint, so the
		 * mobile menu state doesn't persist onto desktop layouts.
		 */
		var caClearMenuShowingForDesktopLayout = function() {
			try {
				$(".menuAdjust,.hideWithMenu,.mobileMenu").addClass("menuHidden").removeClass("menuShowing");
				$(".menuShowing").filter(function() {
					return this === document.body || document.body.contains(this);
				}).removeClass("menuShowing");
			} catch (e) { /* no-op */ }
		};
		var $probe = $("#ca_mobile_layout_probe");
		if ($probe.length === 0) {
			$probe = $("<div>", {
				id: "ca_mobile_layout_probe",
				"aria-hidden": "true"
			}).appendTo("body");
		}
		if (typeof IntersectionObserver !== "undefined") {
			/**
			 * IntersectionObserver callback: when the mobile-layout probe leaves
			 * the viewport (i.e. layout is desktop), clear the mobile menu state.
			 *
			 * @param {IntersectionObserverEntry[]} entries
			 */
			var ioMobileLayout = new IntersectionObserver(function(entries) {
				entries.forEach(function(entry) {
					if (!entry.isIntersecting) caClearMenuShowingForDesktopLayout();
				});
			}, { threshold: 0 });
			ioMobileLayout.observe($probe[0]);
		} else {
			var mqCaMobile = window.matchMedia("(max-width: 1024px)");
			/**
			 * matchMedia change handler (fallback when IntersectionObserver
			 * isn't supported): clear mobile-menu state once layout is desktop.
			 */
			var caMqFallback = function() {
				if (!mqCaMobile.matches) caClearMenuShowingForDesktopLayout();
			};
			if (typeof mqCaMobile.addEventListener === "function") {
				mqCaMobile.addEventListener("change", caMqFallback);
			} else {
				mqCaMobile.addListener(caMqFallback);
			}
			caMqFallback();
		}

		/* Second cleanup boundary at 767px — the slide-in `.mobileMenu`
		   element only renders inside `@media (max-width: 767px)` (see
		   responsive.css), but the `menuShowing` DOM class survives a
		   resize/rotate up across the 768px boundary. Without this
		   listener the modal scrim painted by `body:has(.mobileMenu.menuShowing)`
		   stays visible on tablet/landscape after the menu itself has
		   collapsed back into the desktop layout. Fires regardless of
		   IntersectionObserver support — matchMedia is universal. */
		var mqCaMenuMobile = window.matchMedia("(max-width: 767px)");
		var caMenuMqHandler = function() {
			if (!mqCaMenuMobile.matches) caClearMenuShowingForDesktopLayout();
		};
		if (typeof mqCaMenuMobile.addEventListener === "function") {
			mqCaMenuMobile.addEventListener("change", caMenuMqHandler);
		} else {
			mqCaMenuMobile.addListener(caMenuMqHandler);
		}
	}
	$(".mainArea").on("click", ".actionsButtonContext,.actionsButton,.ca_multiselect", function() {
		data.actions = true;
	});
	$(".searchButton").on("mousedown", function(e) {
		e.preventDefault();
	});
	/**
	 * Search button click: if the search modal is already open, refocus the
	 * input and re-kick Awesomplete; otherwise open the modal.
	 */
	$(".searchButton").on("click", function() {
		if ($("body").hasClass("ca_searchModalOpen")) {
			$("#searchBox").trigger("focus");
			var kick = function() {
				caKickSearchModalAwesomplete();
			};
			requestAnimationFrame(function() {
				kick();
				setTimeout(kick, 40);
				setTimeout(kick, 100);
			});
			return;
		}
		caOpenSearchModal();
	});
	/* Capture phase keeps #searchBox focused when clicking ?/X so #searchFilter focusout does not close the modal.
	   jQuery does not expose the capture flag, so this listener stays native. */
	if (!window.__caSearchModalIconMouseDownCapture) {
		window.__caSearchModalIconMouseDownCapture = true;
		document.addEventListener(
			"mousedown",
			function(e) {
				if (!$(e.target).closest(".searchModalQueryBtn, .searchModalClearBtn").length) return;
				if (!$("body").hasClass("ca_searchModalOpen")) return;
				e.preventDefault();
				$("#searchBox").trigger("focus");
			},
			true
		);
	}
	/**
	 * Search modal query button: close any open Awesomplete, ensure a sort
	 * order is enabled (defaulting to "default" via server POST when none is),
	 * then run `doSearch()` with the current `#searchBox` value.
	 *
	 * @param {jQuery.Event} e
	 */
	$(document).on("click", ".searchModalQueryBtn", function(e) {
		e.stopPropagation();
		if (!$("body").hasClass("ca_searchModalOpen")) return;
		/* Run the search using whatever is currently in #searchBox, mirroring Enter on the input. */
		try {
			if (typeof searchBoxAwesomplete !== "undefined" && searchBoxAwesomplete && typeof searchBoxAwesomplete.close === "function") {
				searchBoxAwesomplete.close();
			}
		} catch (err) { /* no-op */ }
		var sortButton = false;
		$(".sortIcons").each(function() {
			if ($(this).hasClass("enabledIcon") && (!$(this).hasClass("startupMore"))) sortButton = true;
		});
		if (!sortButton) {
			$(".sortIcons").removeClass("enabledIcon").removeClass("startupMore");
			post({ action: "defaultSortOrder" }, function() {
				$("#defaultSort").addClass("enabledIcon");
				if (typeof doSearch === "function") doSearch();
			});
		} else if (typeof doSearch === "function") {
			doSearch();
		}
	});
	$(document).on("click", ".searchModalClearBtn", function(e) {
		e.stopPropagation();
		if ($(this).hasClass("ca_hide")) return;
		$("#searchBox").val("");
		if (typeof doSearch === "function") {
			doSearch(false);
		}
	});
	$(".caChangeLog").on("click", function() { disableSort(); scrollToTop(); caChangeLog(); });
	$(".mainArea").on("click", ".ca_multiselect", function() { enableMultiInstall(); });
	/* body-delegated rather than #sidenavContent-delegated because the pin
	   button gets relocated into .popupCloseAreaButtons (sibling of
	   #sidenavContent, not descendant) by caRelocatePopupActions(). */
	$("body").on("click", ".pinPopup", function() { pinApp(this, $(this).data("repository"), $(this).data("name")); });
	/**
	 * Click the "favourite repository" star: clear all favourite/holder marks
	 * across the grid, swap the clicked icon to non-favourite, refresh the
	 * tooltip, and persist via POST `toggleFavourite`.
	 */
	$(".mainArea").on("click", ".ca_favouriteRepo", function() {
		$(".ca_fav").removeClass("ca_favouriteRepo").addClass("ca_non_favouriteRepo");
		$(".ca_holderFav").removeClass("ca_holderFav");
		$(this).removeClass("ca_favouriteRepo").addClass("ca_non_favouriteRepo");
		setToolTipForFavourite();
		/* No transient banner — see setFavourite() in Apps.page. The icon swap
		   and tooltip update give the user visible confirmation. */
		post({ action: "toggleFavourite", repository: "" }, function() {
			setFavRepoSearch();
		});
	});
	$("body").on("click", ".fav,.nonfav", function() { setFavourite(this); });
	/**
	 * Click a repository search link: close the sidebar, ensure a sort order
	 * is enabled (defaulting if necessary), then run `doSearch(false, repo)`.
	 */
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
	/**
	 * Sidebar "Favourite Repo" menu item click: search for the stored
	 * repository, enabling the default sort if no sort icon is active.
	 */
	$(".favouriteRepo").on("click", function() {
		if ($(this).hasClass("caMenuDisabled")) return;
		var repo = $(this).attr("data-repository");
		/* Same as the section-menu items — wipe the search input + close
		   any open suggestion strip before launching the repo search,
		   so we don't show stale chips from the prior query. doSearch
		   below re-populates #searchBox with the repo name. */
		if (typeof clearSearchBox === "function") clearSearchBox();
		if (typeof inlineSearchAwesomplete !== "undefined" && inlineSearchAwesomplete) {
			try { inlineSearchAwesomplete.close(); } catch (e) { /* no-op */ }
		}
		if (typeof searchBoxAwesomplete !== "undefined" && searchBoxAwesomplete) {
			try { searchBoxAwesomplete.close(); } catch (e) { /* no-op */ }
		}
		caClearHomeSectionSubtitle();
		var sortButton = false;
		$(".sortIcons").each(function() { if ($(this).hasClass("enabledIcon") && (!$(this).hasClass("startupMore"))) sortButton = true; });
		if (!sortButton) {
			$(".sortIcons").removeClass("enabledIcon").removeClass("startupMore");
			post({ action: "defaultSortOrder" }, function() { $("#defaultSort").addClass("enabledIcon"); doSearch(false, repo); });
		} else doSearch(false, repo);
	});

	$("body").on("click", ".templateSearch", function() { caClearHomeSectionSubtitle(); doSearch(false); });
	/**
	 * Click an XML install button: POST `createXML` with the selected
	 * template type and any pending port-adjust answer, then redirect to
	 * the AddContainer page on success.
	 */
	$("body").on("click", ".xmlInstall", function() {
		var type = $(this).data("type");
		var xml = $(this).data("xml");
		/* displayTags() (Apps.page) sets window.caPendingAdjustPorts based on
		   the user's Yes/No answer to the port-conflict prompt. Forward that to
		   createXML so the server can rewrite conflicting host ports. */
		var adjustPorts = !!window.caPendingAdjustPorts;
		window.caPendingAdjustPorts = false;
		/* saveState() is intentionally not called here — by the time this click
		   handler fires, showSidebarApp() has already taken the snapshot. */
		post({ action: "createXML", xml: xml, type: type, adjustPorts: adjustPorts }, function(result) {
			if (result.status == "ok") {
				if (type == "second") type = "default";
				openNewWindow("/Apps/AddContainer?xmlTemplate=" + type + ":" + xml);
			}
		});
	});
	/**
	 * Dev + admin only: from the repo sidebar, render this repo's duplicate-
	 * Name templates into the main cards area. Mirrors the .ca_repoSearchPopUp
	 * flow (close sidebar, swap content) but skips the search-box update —
	 * this isn't a search, just a filtered view, so the search input stays as
	 * the user left it.
	 */
	$("body").on("click", ".ca_repoDuplicates", function(e) {
		e.stopPropagation();
		var repository = $(this).data("repository");
		if (!repository) return;
		caClearHomeSectionSubtitle();
		closeSidebar();
		post({ action: "getRepoDuplicates", repository: repository }, function(result) {
			if (result && result.display_data) {
				updateDisplay(result.display_data);
			}
		});
	});
	$("body").on("click", ".repoPopup,.ca_repoinfo,.ca_repoFromPopUp,.cardDescriptionRepo", function(e) {
		e.stopPropagation();
		var repository = $(this).data("repository") ? $(this).data("repository") : $(this).closest(".ca_holder").data("repository");
		showRepoPopup(repository);
	});
	/**
	 * Card click on `.ca_holder`: open the repository popup, open the sidebar
	 * for the app, or dismiss dropdowns based on the card's classes and the
	 * `data.actions` action-bar flag.
	 *
	 * @param {jQuery.Event} e
	 */
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
	/**
	 * Menu-item click dispatcher: route Statistics/Settings/Credits items to
	 * their renderers; otherwise scroll to top and close the menu.
	 * Suppresses re-clicks on the already-selected item (with a Repositories
	 * exception so the user can leave that category).
	 *
	 * @param {jQuery.Event} e
	 */
	$("body").on("click", ".caMenuItem", function(e) {
		if ($(this).hasClass("caMenuDisabled")) return;
		/* Suppress re-clicks of the already-selected menu item, EXCEPT when
		   the user is on Repositories and clicking a different (previously
		   selected) category to return to it. Re-clicking Repositories itself
		   while it's selected is still a no-op. */
		if ($(this).hasClass("selectedMenu") &&
		    (!$(".caRepositoryMenu").hasClass("selectedMenu") || $(this).hasClass("caRepositoryMenu"))) return;
		if ($(this).hasClass("showStatistics")) { e.stopPropagation(); showStatistics(); }
		else if ($(this).hasClass("showSettings")) { e.stopPropagation(); showSettings(); }
		else if ($(this).hasClass("showCredits")) { e.stopPropagation(); showCredits(); }
		/* Parent of a sub-menu: clicking it only expands the subs (no fetch),
		   so on mobile we keep the menu open until the user actually picks
		   one — the auto "All" entry or a real sub. */
		else if ($(this).next(".subCategory").length) { /* leave menu open */ }
		else { scrollToTop(); closeMenu(); }
	});
	/**
	 * Category-menu click: clear the search box (when no in-progress search),
	 * scroll to top, ensure a sort order is selected, then `changeCategory()`
	 * to render the new category.
	 */
	$(".menuItems").on("click", ".categoryMenu", function() {
		var menu = this;
		if ($(menu).hasClass("caMenuDisabled")) return;
		if ($(menu).hasClass("selectedMenu") &&
		    (!$(".caRepositoryMenu").hasClass("selectedMenu") || $(menu).hasClass("caRepositoryMenu"))) return;
		/* Parent of a sub-menu — let the body handler slide the subs open,
		   but don't fetch anything. The auto-generated "All" sub is the
		   one that fetches the parent's full result set. */
		if ($(menu).next(".subCategory").length) return;
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
	/**
	 * Menu-selection state: highlight the clicked `.caMenuItem` as
	 * `selectedMenu`, slide-open its sub-menu, and collapse siblings when
	 * appropriate (with special-case behavior for the Repositories item).
	 */
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
		/* Parent that has a sub-menu beneath it: clicking only toggles the
		   subs open. Don't move selectedMenu off the active item and don't
		   fetch — the auto-generated "All" sub fetches the parent's results. */
		var isParentWithSubs = $(this).next(".subCategory").length > 0;
		if (!$(this).hasClass("caRepositoryMenu")) {
			if (!isParentWithSubs) $(".caMenuItem").removeClass("selectedMenu");
		}
		if ($(this).hasClass("caRepositoryMenu") && $(".startupButton").hasClass("selectedMenu")) {
			$(".startupButton").removeClass("selectedMenu");
			$(".allApps").addClass("selectedMenu");
		}
		if (!isParentWithSubs) $(this).addClass("selectedMenu");
		if (slideFlag && !$(this).parent().hasClass("actionCentre")) $(this).next().show("fast");
		/* Hide other peek-only expansions. Branches that hold the active
		   selection stay fully expanded — we never collapse the one the user
		   has actually picked from. The exclude argument shields:
		     - parent click  -> this parent's own sub-menu being opened
		     - sub/leaf click -> the wrapper containing the now-selected leaf
		   Skipped for caRepositoryMenu (Repositories has its own state). */
		if (!$(this).hasClass("caRepositoryMenu") && typeof caHideUnselectedSubs === "function") {
			var $keepOpen = isParentWithSubs ? $(this).next(".subCategory") : $(this).closest(".subCategory");
			caHideUnselectedSubs($keepOpen);
		}
	});
	/**
	 * Section-menu click (Installed/Previous/Pinned/Action Centre): dispatch
	 * to the corresponding section renderer, keyed on the element's
	 * `data-category` attribute.
	 */
	$("body").on("click", ".sectionMenu", function() {
		if ($(this).hasClass("caMenuDisabled")) return;
		/* Parent of a sub-menu (Installed Apps / Previous Apps): clicking
		   only peeks at the subs. The auto-generated "All" entry inside the
		   sub list is what dispatches the parent's action. */
		if ($(this).next(".subCategory").length) return;
		/* Switching into Installed / Previous / Pinned / Action Centre /
		   Favourite Repo means the user is leaving the search context —
		   wipe the search input and close the suggestion strip so the
		   new section isn't shown alongside a stale query. */
		if (typeof clearSearchBox === "function") clearSearchBox();
		if (typeof inlineSearchAwesomplete !== "undefined" && inlineSearchAwesomplete) {
			try { inlineSearchAwesomplete.close(); } catch (e) { /* no-op */ }
		}
		if (typeof searchBoxAwesomplete !== "undefined" && searchBoxAwesomplete) {
			try { searchBoxAwesomplete.close(); } catch (e) { /* no-op */ }
		}
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
	/**
	 * "Show more" button on a Home section: change menu selection to the
	 * section's category, set the home subtitle, clear search, scroll to top,
	 * and POST the section's sort order before fetching its full content.
	 */
	$(".mainArea").on("click", ".homeMore", function() {
		var description = $(this).data("des");
		var category = $(this).data("category");
		/* Parent + auto "All" share data-category — prefer the All sub. Reveal
		   its (hidden by default) wrapper so the active selection is visible. */
		var $menuItem = $(".caMenuItem[data-category='" + category + "']");
		if ($menuItem.filter(".caCategoryAll").length) {
			$menuItem = $menuItem.filter(".caCategoryAll");
			$menuItem.closest(".subCategory").show();
		}
		$(".caMenuItem").removeClass("selectedMenu");
		$menuItem.addClass("selectedMenu");
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
	/**
	 * "Debugging" menu click: build a timestamped filename, POST
	 * `downloadDebugging`, and redirect the browser to the resulting zip URL.
	 */
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
	/**
	 * "Show All Results" affordance on the display-count line: widens the
	 * current search by setting the one-shot override flag and re-running
	 * doSearch. Delegated to body since caUpdateDisplayCount re-renders
	 * .ca_displayCount on every refresh and would tear off a directly-
	 * bound handler.
	 */
	$("body").on("click keydown", ".caShowAllResults", function(e) {
		if (e.type === "keydown" && e.key !== "Enter" && e.key !== " ") return;
		e.preventDefault();
		var currentFilter = $.trim($("#searchBox").val() || "");
		if (!currentFilter) return;
		data.searchLimitOverride = true;
		data.searchLimitOverrideFilter = currentFilter;
		/* Bypass doSearch's "same filter" early-return guard so the
		   re-run actually fires against the server. */
		data.committedSearchFilter = "";
		doSearch(false);
	});
	$(".multi_installClear").click(function() { clearMultiInstall(); });
	$(".multi_deleteButton").click(function() { deleteMulti(); });
	$(".multi_installAll").click(function() { selectAllPrevious(); enableMultiInstall(); });
	/**
	 * Home/Startup button click (also the Clear Search variant of this button):
	 * set `data.ignoreUnload` so saveState() does not run on the upcoming
	 * navigation, clear CA-related cookies (with legacy cookie option
	 * fallbacks), then navigate to /Apps. We navigate rather than reload so
	 * any GET arguments in the current URL (e.g. ?search=, ?category=) are
	 * dropped — a true "Home" should land on the clean Apps URL.
	 */
	$("body").on("click", ".startupButton", function() {
		/* Two modes (label-driven, see caSyncHomeMenuLabel):
		   - "Clear Search"  -> a search / docker / category filter is active.
		                        Just unwind that state in-page via appStore()
		                        so the home sections come back without a full
		                        page reload. Same path the search-box clear
		                        uses when there's no .startupButton.
		   - "Home"          -> nothing's active. Fall through to the original
		                        full-navigate behaviour so query-string / state
		                        debris is wiped cleanly. */
		var d = (typeof data !== "undefined" && data) ? data : null;
		var committed = d ? $.trim(String(d.committedSearchFilter || "")) : "";
		var hasActive = d && !!(d.searchActive || d.searchFlag || d.docker);
		var isClearMode = !!committed || hasActive;
		if (isClearMode) {
			if (typeof caCloseSearchModal === "function") caCloseSearchModal();
			/* Prefer the inline-search soft-clear so a snapshot taken
			   when the user started typing restores them to that menu
			   item instead of dumping them on home. Falls back to
			   appStore inside caInlineSoftClear when there's no
			   snapshot. */
			if (typeof caInlineSoftClear === "function") {
				caInlineSoftClear();
				return;
			}
			if (typeof appStore === "function") {
				appStore();
				return;
			}
		}
		/* Home should start fresh — block saveState from re-writing on the way
		   out via showSidebarApp() or guiSearchOnUnload(). */
		try { data.ignoreUnload = true; } catch (e) { /* no-op */ }
		try { sessionStorage.removeItem("ca_state"); } catch (e) { /* no-op */ }
		/* Pre-refactor cookie names — kept in the wipe list one release so any
		   user upgrading from the cookie model gets their stale entries
		   evicted. Drop in a future cleanup once the snapshot has fully moved
		   to sessionStorage. */
		[
			"ca_data",
			"ca_searchActive",
			"ca_installMulti",
			"ca_selectedMenu",
			"ca_filter",
			"ca_categoryName",
			"ca_categoryText"
		].forEach(function(name) {
			try { $.removeCookie(name, { path: "/" }); } catch (e1) { /* no-op */ }
			/* Some legacy calls used a non-standard cookie options string; clear that too just in case. */
			try { $.removeCookie(name, { path: "/;SameSite=Lax" }); } catch (e2) { /* no-op */ }
		});
		window.location.assign("/Apps");
	});
	$(".multi_installButton").click(function() { if ($(".multi_installButton").hasClass("actionCenter")) updateMulti(); else installMulti(); });
	/**
	 * Sort-icon click: set the icon as active, reset paging/search state, and
	 * POST the new `changeSortOrder` before refetching via `changeSortOrder()`.
	 */
	$(".sortIcons").click(function() {
		$(".sortIcons").removeClass("enabledIcon");
		$(this).addClass("enabledIcon");
		data.currentpage = 1;
		data.searchActive = false;
		var sortOrder = { sortBy: $(this).attr("data-sortBy"), sortDir: $(this).attr("data-sortDir") };
		post({ action: "changeSortOrder", sortOrder: sortOrder }, function() { changeSortOrder(); });
	});
	$("body").on("click", ".languageSwitch", function() { CAswitchLanguage($(this).data("language")); });
	/* MFP closeMarkup carries `popUpClose` so the X picks up the same flat
	   styling as the sidebar's own close button. Without this guard, clicking
	   the MFP X fires both this delegate (closeSidebar() with the default
	   keepIdentity=false, wiping data.sidebarapp{path,name} + the cookies +
	   sessionStorage sidebar fields) AND MFP's own close → afterClose, which
	   reopens the sidebar via showSidebarApp(data.sidebarapppath,
	   data.sidebarappname) — except those are now empty, so the reopen
	   silently lands on the first entry in displayed.json. ESC / outside-
	   click don't fire .popUpClose so they survived. Skip any .popUpClose
	   that lives inside an MFP overlay — MFP's own close machinery handles
	   that path. */
	$("body").on("click", ".popUpClose", function() {
		if ($(this).closest(".mfp-wrap, .mfp-container").length) return;
		closeSidebar();
	});
	$("body").on("click", ".popUpStat", function() { showStatistics(); });
	$("body").on("click", ".similarSearch", function() { doSearch(false, $(this).data("search")); });
	$("body").on("click", ".ca_quitUpdate", caQuitUpdate);

	/* MagnificPopup gallery: route arrow keys to the video iframe when the
	   mouse is over it (so YouTube/Vimeo's player can seek), and back to
	   MFP's gallery prev/next when the mouse is over the surrounding scrim.
	   Implementation is focus-based — MFP's document-level keydown handler
	   only fires when the parent document has keyboard focus; an embedded
	   iframe with focus captures keys before they reach the parent. So we
	   focus the iframe on mouseenter and return focus to .mfp-wrap (MFP's
	   own focusable container) on mouseleave. */
	$("body").on("mouseenter", ".mfp-iframe", function() {
		if (this.focus) this.focus();
	});
	$("body").on("mouseleave", ".mfp-iframe", function() {
		if (document.activeElement === this) {
			var $wrap = $(".mfp-wrap");
			if ($wrap.length) $wrap.focus();
			else if (this.blur) this.blur();
		}
	});
}

/**
 * Dismiss the "Updating Applications" SweetAlert, show a short banner, then history.back().
 * Sets `data.quittingUpdate` so aborted POSTs do not show the generic connection error.
 */
function caQuitUpdate() {
	data.quittingUpdate = true;
	try { if (typeof swal !== "undefined") swal.close(); } catch (err) { /* no-op */ }
	$("div.spinner, .spinnerBackground").hide();

	var $banner = $(".ca_bottomBanner");
	var $msg = $banner.find(".ca_pageGeometryChange");
	var savedHTML = $msg.html();
	$msg.html(tr("The download will continue in the background"));
	$banner.removeClass("ca_hide");
	setTimeout(function() {
		try { history.back(); } catch (err) { /* no-op */ }
		/* Clear the flag so any unrelated POST failures on this page after the
		   exit countdown still surface their communication-failure alert. */
		data.quittingUpdate = false;
	}, 5000);
}

/**
 * For flex-wrapped suggestions in the search modal: map arrow keys to 2D movement
 * (left/right within a row, up/down to the nearest item in the adjacent row).
 * Registered in capture phase so Awesomplete's linear list navigation does not run.
 */
function caBuildAwesompleteGridRows(ul) {
	var items = Array.prototype.slice.call(ul.querySelectorAll("li"));
	if (!items.length) return [];
	var tol = 6;
	var rowBuckets = [];
	for (var i = 0; i < items.length; i++) {
		var el = items[i];
		var r = el.getBoundingClientRect();
		var top = r.top;
		var b, found = -1;
		for (b = 0; b < rowBuckets.length; b++) {
			if (Math.abs(rowBuckets[b].y - top) < tol) {
				found = b;
				break;
			}
		}
		var it = { el: el, index: i, left: r.left, right: r.right, center: r.left + 0.5 * r.width };
		if (found === -1) {
			rowBuckets.push({ y: top, items: [it] });
		} else {
			rowBuckets[found].items.push(it);
		}
	}
	for (b = 0; b < rowBuckets.length; b++) {
		rowBuckets[b].items.sort(function(a, c) { return a.left - c.left; });
	}
	rowBuckets.sort(function(a, c) { return a.y - c.y; });
	return rowBuckets;
}

/**
 * Map a flat Awesomplete list index to row/column coordinates in the flex grid.
 *
 * @param {Array<{y: number, items: Array}>} rows From {@link caBuildAwesompleteGridRows}
 * @param {number} listIndex Awesomplete active index
 * @returns {{ri: number, ci: number, item: object}|null}
 */
function caAwesompleteGridFindPos(rows, listIndex) {
	for (var ri = 0; ri < rows.length; ri++) {
		for (var ci = 0; ci < rows[ri].items.length; ci++) {
			if (rows[ri].items[ci].index === listIndex) {
				return { ri: ri, ci: ci, item: rows[ri].items[ci] };
			}
		}
	}
	return null;
}

/**
 * Pick the suggestion in `row` whose center X is nearest to `centerX` (for vertical moves).
 *
 * @param {{items: Array<{index: number, center: number, left: number}>}} row One row from grid rows
 * @param {number} centerX Horizontal center of the current item
 * @returns {number} List index to pass to Awesomplete.goto
 */
function caAwesompleteGridClosestInRow(row, centerX) {
	var bestI = 0, bestD = Infinity;
	for (var i = 0; i < row.items.length; i++) {
		var d = Math.abs(row.items[i].center - centerX);
		if (d < bestD - 0.5) {
			bestD = d;
			bestI = i;
		} else if (Math.abs(d - bestD) < 0.5 && row.items[i].left < row.items[bestI].left) {
			bestD = d;
			bestI = i;
		}
	}
	return row.items[bestI].index;
}

/**
 * Arrow-key handler (capture): navigate Awesomplete suggestions in a 2D grid while the search modal is open.
 *
 * @param {KeyboardEvent} e
 */
function caSearchModalAwesompleteGridKeydown(e) {
	if (!e || e.isComposing === true) return;
	var kc = e.keyCode;
	if (kc < 37 || kc > 40) return;
	if (!document.body.classList.contains("ca_searchModalOpen")) return;
	if (typeof searchBoxAwesomplete === "undefined" || !searchBoxAwesomplete || !searchBoxAwesomplete.opened) return;
	var ac = searchBoxAwesomplete;
	var ul = ac.ul;
	if (!ul || ul.getAttribute("hidden") !== null) return;
	if (!ul.querySelector("li")) return;
	var idx = ac.index;
	/* No active suggestion: only Up/Down enter the grid; leave Left/Right for the input caret. */
	if (idx < 0) {
		if (kc === 38 || kc === 40) {
			e.preventDefault();
			e.stopImmediatePropagation();
			var rows0 = caBuildAwesompleteGridRows(ul);
			if (!rows0.length) return;
			if (kc === 40) ac.goto(0);
			else {
				var lastR = rows0[rows0.length - 1];
				ac.goto(lastR.items[lastR.items.length - 1].index);
			}
			caScrollSearchModalAwesompleteToActive(ac);
		}
		return;
	}
	e.preventDefault();
	e.stopImmediatePropagation();
	var rows = caBuildAwesompleteGridRows(ul);
	if (!rows.length) return;
	var pos = caAwesompleteGridFindPos(rows, idx);
	if (!pos) {
		if (kc === 40) ac.next();
		else if (kc === 38) ac.previous();
		caScrollSearchModalAwesompleteToActive(ac);
		return;
	}
	var nextIdx = -1;
	if (kc === 37) {
		if (pos.ci > 0) nextIdx = rows[pos.ri].items[pos.ci - 1].index;
	} else if (kc === 39) {
		if (pos.ci < rows[pos.ri].items.length - 1) nextIdx = rows[pos.ri].items[pos.ci + 1].index;
	} else if (kc === 38) {
		if (pos.ri > 0) nextIdx = caAwesompleteGridClosestInRow(rows[pos.ri - 1], pos.item.center);
	} else if (kc === 40) {
		if (pos.ri < rows.length - 1) nextIdx = caAwesompleteGridClosestInRow(rows[pos.ri + 1], pos.item.center);
	}
	if (nextIdx >= 0) {
		ac.goto(nextIdx);
		caScrollSearchModalAwesompleteToActive(ac);
	}
}

/**
 * Scroll the active `<li>` into view inside the Awesomplete list (after programmatic index changes).
 *
 * @param {object} ac Awesomplete instance bound to #searchBox
 */
function caScrollSearchModalAwesompleteToActive(ac) {
	if (typeof ac === "undefined" || !ac || ac.index < 0) return;
	setTimeout(function() {
		var li = ac.ul && ac.ul.children[ac.index];
		if (li) li.scrollIntoView({ block: "nearest", inline: "nearest" });
	}, 0);
}

/**
 * Global behaviors used outside click-only paths: grid keydown, hover-to-focus scroll panes, search box, ESC, errors.
 */
function caInitializeEventHandlers() {
	var elSearch = document.getElementById("searchBox");
	if (elSearch) {
		elSearch.addEventListener("keydown", caSearchModalAwesompleteGridKeydown, true);
	}

	/* Cmd/Ctrl+Shift+D triggers the Debugging menu item from anywhere on the page. */
	$(document).on("keydown", function(e) {
		var key = e.key || "";
		if ((e.metaKey || e.ctrlKey) && e.shiftKey && (key === "d" || key === "D")) {
			e.preventDefault();
			$(".debugging").first().trigger("click");
		}
	});

	/* Hovering a scrollable pane focuses it, so the browser's native arrow /
	   PgUp / PgDn / Home / End handling scrolls that pane without us writing a
	   key handler. Skipped while an input/textarea is focused so typing isn't
	   interrupted. preventScroll keeps the focus call from snapping the page.

	   We also bind a throttled mouseover and a keydown-restore so focus stays
	   on the hovered pane even if a downstream operation (re-render, content
	   replace, etc.) drifts focus to <body>. Without that, arrow keys after
	   the first scroll would scroll the document instead of the pane. */
	var caHoverTarget = null;
	/**
	 * Focus a scrollable pane on hover so the browser's native arrow / PgUp /
	 * PgDn / Home / End keys scroll it. Skipped while an input/textarea is
	 * focused; `preventScroll` keeps the focus call from snapping the page.
	 *
	 * @param {HTMLElement} el The pane element to focus.
	 */
	var caFocusOnHoverFn = function(el) {
		if (!el) return;
		caHoverTarget = el;
		if (document.activeElement === el) return;
		var ae = document.activeElement;
		if (ae && (ae.tagName === "INPUT" || ae.tagName === "TEXTAREA" || ae.isContentEditable)) return;
		if (!el.hasAttribute("tabindex")) el.setAttribute("tabindex", "-1");
		try { el.focus({ preventScroll: true }); } catch (err) { el.focus(); }
	};
	$(document).on("mouseenter", ".mainArea, .menuItems, .sidenav", function() {
		caFocusOnHoverFn(this);
	});
	$(document).on("mouseleave", ".mainArea, .menuItems, .sidenav", function() {
		if (caHoverTarget === this) caHoverTarget = null;
	});
	/* Restore focus before a navigational key actually does anything. */
	$(document).on("keydown", function(e) {
		var key = e.key || "";
		if (key !== "ArrowUp" && key !== "ArrowDown" && key !== "PageUp" && key !== "PageDown" &&
		    key !== "Home" && key !== "End" && key !== " " && key !== "Spacebar") return;
		if (!caHoverTarget) return;
		if (document.activeElement === caHoverTarget) return;
		var ae = document.activeElement;
		if (ae && (ae.tagName === "INPUT" || ae.tagName === "TEXTAREA" || ae.isContentEditable)) return;
		caFocusOnHoverFn(caHoverTarget);
	});
	/**
	 * Capture-phase error handler that substitutes a placeholder image when
	 * any `<img>` fails to load (spotlight icons get their dedicated backup).
	 *
	 * Sidebar gallery thumbs (`.caMediaGallery .screenshot img`) are special-
	 * cased: a broken screenshot/video poster almost always means the source
	 * URL is dead, so the whole `.screenshot` tile is dropped from the
	 * gallery rather than replaced with a question-mark image — keeps the
	 * MFP gallery (and the new thumbnail strip) from showing dead entries.
	 * If removing the tile empties the gallery, hide it entirely.
	 *
	 * @param {ErrorEvent} event
	 */
	window.addEventListener("error", function(event) {
		var target = event.target;
		if (!target || target.tagName !== "IMG") return;

		var $screenshot = $(target).closest(".caMediaGallery .screenshot");
		if ($screenshot.length) {
			var $gallery = $screenshot.closest(".caMediaGallery");
			$screenshot.remove();
			if (!$gallery.find(".screenshot").length) $gallery.hide();
			target.onerror = null;
			return;
		}

		if (target.classList.contains("spotlightIcon")) {
			target.src = window.caSpotlightIconBackup || "/plugins/dynamix.docker.manager/images/question.png";
		} else {
			target.src = "/plugins/dynamix.docker.manager/images/question.png";
		}
		target.onerror = null;

		/* Replaced-with-question icons aren't worth fullscreening — strip
		   the .screenshot/.mfp-image classes and the magnificPopup click
		   binding so the placeholder isn't a live MFP trigger. Two markup
		   shapes hit this path:
		     - app/repo icon: <img class='popupIcon caIconOpensGallery' ...>
		     - legacy .screenshot wrapper inside the popup body
		   For the straggler `.screenshot` popups MFP binds the click
		   directly on the element (no delegate), so removing the class
		   alone isn't enough — `.off("click.magnificPopup")` peels the
		   handler off too. caIconOpensGallery uses our own namespaced
		   binding `click.caIconGallery` (added in Apps.page); strip that
		   too along with the class so the placeholder doesn't open the
		   gallery to a broken seed. */
		var $mfpRoot = $(target).hasClass("screenshot")
			? $(target)
			: $(target).closest(".screenshot");
		if ($mfpRoot.length) {
			$mfpRoot.off("click.magnificPopup");
			$mfpRoot.removeClass("screenshot mfp-image");
			$mfpRoot.removeAttr("data-mfp-src");
		}
		if ($(target).hasClass("caIconOpensGallery")) {
			$(target).off("click.caIconGallery").removeClass("caIconOpensGallery");
		}
	}, true);

	/**
	 * Global script-error handler: forward uncaught errors to the server via
	 * POST `javascriptError` for logging.
	 *
	 * @param {string} msg
	 * @param {string} url
	 * @param {number} lineNo
	 * @param {number} columnNo
	 * @param {Error} error
	 */
	window.onerror = function(msg, url, lineNo, columnNo, error) {
		post({ action: "javascriptError", msg: msg, url: url, lineNo: lineNo, columnNo: columnNo, error: error });
	};

	$("#searchBox").on("input", function() {
		caSyncSearchFilterCollapsed();
	});

	/**
	 * Desktop inline search input (skin.html .caDesktopSearchInline):
	 * - Debounced 300ms.
	 * - Fires doSearch once the trimmed value is at least 3 chars.
	 * - When the value drops below 3 (backspace, clear), if a search was
	 *   actually committed before, soft-reset to home via appStore() so the
	 *   sections come back without a full page reload.
	 * - Mirrors the value into #searchBox so the rest of CA's search code
	 *   (which reads from #searchBox) keeps working unchanged.
	 * Bound on body so it survives any future re-render of #searchFilter.
	 */
	(function() {
		var lastCommitted = "";
		/* 300ms debounce coalesces fast typing (and fast backspacing) so a
		   burst of keystrokes doesn't fire one search per char. Concurrency
		   gate on top: when the debounce fires, if a search is already in
		   flight we only stash the latest value in pendingVal (mid-burst
		   keystrokes get dropped), and caInlineSearchOnComplete flushes it
		   when the in-flight chain settles. */
		var inlineTimer = null;
		var inFlightVal = null;
		var pendingVal  = null;
		/* Snapshot of what menu item was selected when the user started
		   typing (and no search was already active). On clear we restore
		   by clicking that menu item so the user lands back where they
		   were instead of being dumped to home. */
		var snapshotState = null;

		function captureSnapshotIfFresh() {
			/* Already snapshotted for this typing session, or nothing
			   selected to record. Stale snapshots (from a prior typing
			   session) get cleared by the body click handler below and by
			   caInlineSearchResetState. */
			if (snapshotState !== null) return;
			/* Pick the actual selected menu (filter to .caCategoryAll first
			   when the data-category is shared between a parent header and
			   its synthetic "All" sub — only the All sub is ever truly
			   selected in that scheme). */
			var $allSelected = $(".selectedMenu");
			if (!$allSelected.length) return;
			var $sel = $allSelected.filter(".caCategoryAll").first();
			if (!$sel.length) $sel = $allSelected.first();
			snapshotState = {
				selectedMenu: $sel.data("category") || "",
				isAllSub:     $sel.hasClass("caCategoryAll"),
				isStartup:    $sel.hasClass("startupButton")
			};
		}

		window.caInlineSearchClearSnapshot = function() { snapshotState = null; };

		/* Exposed so the .startupButton "Clear Search" path can route
		   through the same snapshot-restore-or-appStore-fallback flow
		   the backspace / X paths use. Caller is responsible for any
		   accompanying UI cleanup (we just handle the search-state +
		   restore here). */
		window.caInlineSoftClear = inlineSoftClear;

		/* Any direct user click on a menu item invalidates the snapshot —
		   the next typing session should snapshot from the new menu state,
		   not the one we captured before this click. inlineSoftClear's
		   own restore trigger("click") also lands here, but the snapshot
		   is already nullified by then (we read it into a local var
		   first), so this is a harmless no-op for that path. Same goes
		   for .sectionMenu and .startupButton clicks. */
		$("body").on("click", ".caMenuItem, .startupButton", function() {
			snapshotState = null;
		});

		function fireInlineSearch(val) {
			inFlightVal = val;
			pendingVal  = null;
			$("#searchBox").val(val);
			if (typeof doSearch === "function") doSearch(false, val);
		}

		function inlineSoftClear() {
			inFlightVal = null;
			pendingVal  = null;
			$("#searchBox").val("");

			/* Have a snapshot of where the user was before they started
			   typing? Restore that menu item so they land back where they
			   left off. Startup button gets the in-page appStore path so
			   we don't full-navigate. */
			if (snapshotState !== null && snapshotState.selectedMenu) {
				var snap = snapshotState;
				snapshotState = null;
				if (snap.isStartup) {
					if (typeof appStore === "function") {
						appStore();
					} else if (typeof clearSearchBox === "function") {
						clearSearchBox();
					}
					return;
				}
				var $menu = $(".caMenuItem[data-category='" + snap.selectedMenu + "']");
				if (snap.isAllSub && $menu.filter(".caCategoryAll").length) {
					$menu = $menu.filter(".caCategoryAll");
				}
				if ($menu.length) {
					if (typeof clearSearchBox === "function") clearSearchBox();
					/* If the restored item lives inside a collapsed
					   .subCategory wrapper (e.g. Multimedia → Photos), the
					   sub-tree is display:none and the click handler would
					   land us on the right content but the sidebar would
					   show the parent collapsed with nothing highlighted.
					   Open the wrapper first so the active branch is
					   visible alongside its restored selection. */
					$menu.first().closest(".subCategory").show();
					$menu.first().trigger("click");
					return;
				}
			}

			/* No snapshot — fall back to the home / appStore reset so the
			   user doesn't get stranded on stale search results. */
			var d = (typeof data !== "undefined" && data) ? data : null;
			var hadSearch = d && !!(d.searchActive || d.searchFlag || d.docker ||
				(d.committedSearchFilter && String(d.committedSearchFilter).length));
			if (hadSearch && typeof appStore === "function") {
				appStore();
			}
		}

		/* Exposed so clearSearchBox (Apps.page) can wipe the closure's
		   lastCommitted / queue state synchronously. Without this, a Home
		   click that resets #searchBox externally leaves lastCommitted at
		   the prior term, and the user re-typing the same term within the
		   next 250ms mirror-tick would be treated as a no-op. */
		window.caInlineSearchResetState = function() {
			lastCommitted = "";
			inFlightVal   = null;
			pendingVal    = null;
			snapshotState = null;
			clearTimeout(inlineTimer);
		};

		/* Exposed globally so the search chain in Apps.page can hand control
		   back here once data.searchInProgress flips false again. Safe to
		   call from any doSearch path — if nothing's queued it's a no-op. */
		window.caInlineSearchOnComplete = function() {
			inFlightVal = null;
			if (pendingVal === null) return;
			var next = pendingVal;
			pendingVal = null;
			if (next === "") {
				if (lastCommitted !== "") {
					lastCommitted = "";
					inlineSoftClear();
				}
				return;
			}
			if (next !== lastCommitted) {
				lastCommitted = next;
				fireInlineSearch(next);
			}
		};


		$("body").on("input", "#caInlineSearchBox", function() {
			var $in = $(this);
			var val = $.trim($in.val() || "");
			$(".caInlineSearchClear").toggleClass("ca_hide", val === "");
			/* User started typing into an empty box and there's no active
			   search yet → remember the current menu state so a later
			   clear restores them here. captureSnapshotIfFresh is a no-op
			   when a snapshot already exists or when a search is in flight. */
			if (val !== "") captureSnapshotIfFresh();
			clearTimeout(inlineTimer);
			inlineTimer = setTimeout(function() {
				/* In-flight? Stash latest only — older queued values get
				   dropped. caInlineSearchOnComplete will flush whatever's
				   parked here once the in-flight chain settles. */
				if (inFlightVal !== null) {
					pendingVal = val;
					return;
				}
				if (val === "") {
					if (lastCommitted !== "") {
						lastCommitted = "";
						inlineSoftClear();
					}
				} else if (val !== lastCommitted) {
					lastCommitted = val;
					fireInlineSearch(val);
				}
			}, 300);
		});

		$("body").on("click keydown", ".caInlineSearchClear", function(e) {
			if (e.type === "keydown" && e.key !== "Enter" && e.key !== " ") return;
			e.preventDefault();
			clearTimeout(inlineTimer);
			var $in = $("#caInlineSearchBox");
			$in.val("").trigger("focus");
			$(".caInlineSearchClear").addClass("ca_hide");
			lastCommitted = "";
			inlineSoftClear();
		});

		/* Initial-load focus: when nothing in #searchBox and home is the
		   landing view, drop focus into the inline so the user can start
		   typing immediately. Delayed one tick so the bootstrap's existing
		   focus shuffles (the modal init, etc.) don't fight us. */
		setTimeout(function() {
			if (typeof caFocusInlineSearchIfHome === "function") {
				caFocusInlineSearchIfHome();
			}
		}, 0);

		/* External writes (Home button soft-reset, restoreState filter
		   restore, etc.) land in #searchBox. Mirror those back into the
		   inline input so it doesn't show a stale query. lastCommitted is
		   also re-synced from #searchBox so the input handler doesn't
		   treat the user re-typing the same term as a no-op after the
		   Home button cleared things behind our back. */
		var lastMirror = null;
		setInterval(function() {
			var $inline = $("#caInlineSearchBox");
			if (!$inline.length) return;
			var sb = $("#searchBox").val() || "";
			if (sb === lastMirror) return;
			lastMirror = sb;
			/* Always re-sync lastCommitted so a Home click that clears
			   #searchBox to "" doesn't leave the closure thinking the
			   user is "still" on their previous search term. */
			if (lastCommitted !== sb) lastCommitted = sb;
			if ($inline.is(":focus")) return; // don't fight user typing
			if (($inline.val() || "") !== sb) {
				$inline.val(sb);
				$(".caInlineSearchClear").toggleClass("ca_hide", sb === "");
			}
		}, 250);
	})();

	$("#searchBox").on("focus", function() {
		if ($("body").hasClass("ca_searchModalOpen")) {
			caReopenSearchModalIfNeeded();
			return;
		}
		caOpenSearchModal();
	});
	/* mousedown fires on click even when the input is already focused (no duplicate focus event). */
	$("#searchBox").on("mousedown", function(e) {
		if (e && e.button !== 0) return;
		if ($(this).prop("disabled")) return;
		caReopenSearchModalIfNeeded();
	});

	/**
	 * Focusout from #searchFilter: when focus has actually left the filter
	 * region (and not into the Awesomplete dropdown), close the search modal
	 * (discarding the draft) or sync collapse state.
	 *
	 * @param {jQuery.Event} e
	 */
	$("#searchFilter").on("focusout", function(e) {
		var rt = e.relatedTarget;
		if (rt && $(rt).closest(".awesomplete").length) return;
		setTimeout(function() {
			var el = document.getElementById("searchFilter");
			if (!el) return;
			var active = document.activeElement;
			if (active && (el.contains(active) || $(active).closest(".awesomplete").length)) return;
			if ($("body").hasClass("ca_searchModalOpen")) {
				caCloseSearchModal({ discardDraft: true });
				return;
			}
			caSyncSearchFilterCollapsed();
		}, 150);
	});

	$("#mobileMenu").on("mousedown", function() {
		caRestoreCommittedSearchIfDrafted();
	});

	/**
	 * Enter-key handler for #searchBox: close Awesomplete, ensure a sort
	 * order is enabled (defaulting if not), then run `doSearch()`.
	 *
	 * @param {jQuery.Event} e
	 */
	$("#searchBox").keydown(function(e) {
		if (e.which === 13) {
			e.stopPropagation();
			if (typeof searchBoxAwesomplete !== "undefined" && searchBoxAwesomplete && typeof searchBoxAwesomplete.close === "function") {
				searchBoxAwesomplete.close();
			}
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

	/**
	 * Body keydown: handle ESC to close (in priority order) the sidebar
	 * popup, sidebar, mobile menu, or search modal/clear the search box.
	 *
	 * @param {jQuery.Event} e
	 */
	$("body").keydown(function(e) {
		switch (e.which) {
			case 27: {
				/* Diff overlay takes Esc first — it sits on top of the
				   sidebar's normal "back" / "close" flow and we want it to
				   dismiss without unwinding any of the sidebar state.
				   Guard on the element being present + visible so non-dev
				   pages (where #caDiffView doesn't exist at all) keep their
				   normal Esc behavior. Whole case wrapped in braces so the
				   `var $diff` declaration is block-scoped (Biome
				   `noSwitchDeclarations`). */
				var $diff = $("#caDiffView");
				if ($diff.length && !$diff.hasClass("ca_hide")) {
					e.preventDefault();
					e.stopPropagation();
					/* Synthesize a click on the close button so both paths
					   (mouse + Esc) run the same handler — bound in diff.js. */
					$(".caDiffClose").click();
					return;
				}
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
				if ($(".mobileMenu").hasClass("menuShowing")) {
					e.preventDefault();
					e.stopPropagation();
					closeMenu();
					return;
				}
				if ($("#searchBox").is(":focus") && !$("#searchBox").prop("disabled")) {
					e.preventDefault();
					e.stopPropagation();
					if ($("body").hasClass("ca_searchModalOpen")) {
						caCloseSearchModal({ discardDraft: true });
					} else {
						$("#searchBox").val("");
						data.committedSearchFilter = "";
						caSyncHomeSearchSubtitle();
						caSyncSearchFilterCollapsed();
					}
					return;
				}
				break;
			}
		}
	});
}

/**
 * Wire CA settings form in the sidebar: dirty detection, submit, and unsaved-warning on close.
 *
 * @param {string} initialFormState Serialized form state for dirty comparison
 */
function caBindSettingsFormHandlers(initialFormState) {
	var $sidenav = $("#sidenavContent");
	var $form = $sidenav.find(".ca_settingsForm");
	if (!$form.length) return;

	/* "Use whole display window" only applies on a 7.2+ responsive OS —
	   detected by the .Theme--responsive class that Apps.page puts on
	   <html> only when $responsiveOS is true. On legacy chrome the
	   feature has no meaning, so grey the card and disable the toggle
	   so the user can't flip a setting that wouldn't do anything.
	   Re-checked every time the settings panel opens (not just once at
	   page load) so a future OS upgrade is reflected without a refresh.

	   Disable only the checkbox, not the hidden `useWholeDisplayWindow`
	   "no" fallback alongside it — disabled fields are excluded from
	   form serialization, and we want the hidden "no" to still submit
	   so /update.php always writes a clean value for this key. */
	if (!$("html").hasClass("Theme--responsive")) {
		$form.find(".caUseWholeDisplayWindowCard")
			.addClass("ca_settingDisabled")
			.find("input[type='checkbox'][name='useWholeDisplayWindow']")
				.prop("disabled", true);
		/* Same story for Display usage graphs — the live-stats panel only
		   slots cleanly into the 7.2+ responsive sidebar geometry, so on
		   legacy chrome the card stays visible but greyed and locked. */
		$form.find(".caDisplayUsageGraphsCard")
			.addClass("ca_settingDisabled")
			.find("input[type='checkbox'][name='displayUsageGraphs']")
				.prop("disabled", true);
	}

	/* Stash the initial serialized state on the form so closeSidebar() can
	   detect dirty fields and auto-submit when the panel is dismissed. */
	$form.data("caInitialState", initialFormState);

	/**
	 * Submit handler for `.ca_settingsForm`: guard against re-entry, show a
	 * banner, and reload once the progress iframe fires `load` (or after a
	 * short fallback delay).
	 */
	$sidenav.off("submit.caSettings", ".ca_settingsForm").on("submit.caSettings", ".ca_settingsForm", function(e) {
		/* preventDefault is the crux: the form's native submit goes to
		   /update.php targeting the dynamix progressFrame iframe, and dynamix
		   reloads the page when that iframe completes — which killed our reload
		   countdown a second or two in. We never let that happen: save the
		   toggles ourselves via the saveSettings action, then drive the reload
		   from the countdown modal. */
		e.preventDefault();
		var $localForm = $(this);
		if ($localForm.data("submitting")) return false;
		$localForm.data("submitting", true);
		/* Mark the form clean so closeSidebar() (called from inside the reload
		   modal) doesn't see dirty fields and try to re-submit. */
		$localForm.data("caInitialState", $localForm.serialize());

		/* Collect each toggle's effective value — the checkbox's value when
		   checked, otherwise its paired hidden ("off") input — and persist them. */
		var settings = { action: "saveSettings" };
		$localForm.find("input.caSettingSwitch").each(function() {
			if (!this.name) return;
			settings[this.name] = this.checked
				? this.value
				: ($localForm.find("input[type=hidden][name='" + this.name + "']").val() || this.value);
		});
		postNoSpin(settings, function() {});

		caShowReloadNoticeBanner();
		return false;
	});
}
