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

		var getMainAreaClientRect = function() {
			var m = document.querySelector(".mainArea");
			if (!m) return null;
			var r = m.getBoundingClientRect();
			if (r.width <= 0 || r.height <= 0) return null;
			return r;
		};

		/* Horizontal overlay is drawn along the bottom edge of the scroll target; hide it when that
		   track does not intersect .mainArea (eg. mobile menu / other panes outside the main pane). */
		var hTrackIntersectsMainArea = function(elRect) {
			var mainRect = getMainAreaClientRect();
			if (!mainRect) return true;
			var hLeft = elRect.left;
			var hRight = elRect.left + elRect.width;
			var hTop = elRect.bottom - trackThicknessPx;
			var hBottom = elRect.bottom;
			return hLeft < mainRect.right && hRight > mainRect.left && hTop < mainRect.bottom && hBottom > mainRect.top;
		};

		var shouldAlwaysShowMenuIndicators = function(el) {
			return !!(el && el.classList && el.classList.contains("menuItems") && $(".mobileMenu").hasClass("menuShowing"));
		};

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
				if (!hasHorizontal || !hTrackIntersectsMainArea(rect)) {
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
					   set from caRenderPageNavigation) instead. Fall back to the local
					   calc above if any required signal is missing. */
					if (entry.alwaysShowVertical && typeof data !== "undefined" && data.totalApps > 0 && Array.isArray(data.cardCache)) {
						var $virtCards = $("#templates_content .ca_templatesDisplay").find(".ca_holder, .dockerHubHolder");
						var perRow = (typeof caVirtCardsPerRow === "function") ? caVirtCardsPerRow($virtCards) : 1;
						var rowH = (typeof caVirtRowHeight === "function") ? caVirtRowHeight($virtCards, perRow) : 0;
						if (perRow > 0 && rowH > 0) {
							var firstInDom = (typeof data.firstInDom === "number") ? data.firstInDom : 0;
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
				if (parsedHost === "unraid.net" || parsedHost === "lime-technology.com" || parsedHost.endsWith(".unraid.net") || parsedHost.endsWith(".myunraid.net") || parsedHost.endsWith(".lime-technology.com")) {
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

	/* Single click handler for the unified .ca_modal_overlay scrim. Dispatches
	   to the close action for whichever modal is currently open. Priority:
	   search modal > sidenav (sidebar) > mobile menu — only one of these is
	   ever open at a time in practice, but the order codifies the rule. */
	$(".ca_modal_overlay").on("click", function() {
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

	/* #ca_mobile_layout_probe is in-viewport iff max-width 1024px layout (--mobileDevice true); see responsive.css. */
	if (!window.__caMobileLayoutMenuSync) {
		window.__caMobileLayoutMenuSync = true;
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
			var ioMobileLayout = new IntersectionObserver(function(entries) {
				entries.forEach(function(entry) {
					if (!entry.isIntersecting) caClearMenuShowingForDesktopLayout();
				});
			}, { threshold: 0 });
			ioMobileLayout.observe($probe[0]);
		} else {
			var mqCaMobile = window.matchMedia("(max-width: 1024px)");
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
	}
	$(".mainArea").on("click", ".actionsButtonContext,.actionsButton,.supportButton,.supportButtonCardContext,.ca_multiselect", function() {
		data.actions = true;
	});
	$(".searchButton").on("mousedown", function(e) {
		e.preventDefault();
	});
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
		/* displayTags() (Apps.page) sets window.caPendingAdjustPorts based on
		   the user's Yes/No answer to the port-conflict prompt. Forward that to
		   createXML so the server can rewrite conflicting host ports. */
		var adjustPorts = !!window.caPendingAdjustPorts;
		window.caPendingAdjustPorts = false;
		saveState();
		post({ action: "createXML", xml: xml, type: type, adjustPorts: adjustPorts }, function(result) {
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
		else { scrollToTop(); closeMenu(); }
	});
	$(".menuItems").on("click", ".categoryMenu", function() {
		var menu = this;
		if ($(menu).hasClass("caMenuDisabled")) return;
		if ($(menu).hasClass("selectedMenu") &&
		    (!$(".caRepositoryMenu").hasClass("selectedMenu") || $(menu).hasClass("caRepositoryMenu"))) return;
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
	$("body").on("click", ".startupButton", function() {
		/* Home should start fresh; prevent onbeforeunload from writing saveState cookies back. */
		try { data.ignoreUnload = true; } catch (e) { /* no-op */ }
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
		window.location.reload();
	});
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
	$("body").on("click", ".ca_quitUpdate", caQuitUpdate);
}

/* EXIT button on the "Updating Applications" swal: close the swal, briefly show
   a bottom-banner notice that the download continues in the background, then
   navigate to the previous page. */
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
function caScrollSearchModalAwesompleteToActive(ac) {
	if (typeof ac === "undefined" || !ac || ac.index < 0) return;
	setTimeout(function() {
		var li = ac.ul && ac.ul.children[ac.index];
		if (li) li.scrollIntoView({ block: "nearest", inline: "nearest" });
	}, 0);
}

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

	$("#searchBox").on("input", function() {
		caSyncSearchFilterCollapsed();
	});

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
	});
}

function caBindSettingsFormHandlers(initialFormState) {
	var $sidenav = $("#sidenavContent");
	var $form = $sidenav.find(".ca_settingsForm");
	if (!$form.length) return;

	/* Stash the initial serialized state on the form so closeSidebar() can
	   detect dirty fields and auto-submit when the panel is dismissed. */
	$form.data("caInitialState", initialFormState);

	$sidenav.off("submit.caSettings", ".ca_settingsForm").on("submit.caSettings", ".ca_settingsForm", function() {
		var $localForm = $(this);
		if ($localForm.data("submitting")) return false;
		$localForm.data("submitting", true);

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
