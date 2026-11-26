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
 * @file Wires click handlers and interactive UI behavior for Community Applications.
 */

/**
 * Register delegated `body`/`document` handlers for CA (cards, plugins, moderation, sorting, etc.).
 * Call once after DOM ready.
 */
function caInitializeClickHandlers() {
	/* Action buttons (.caButton) carry the action's onclick on their inner
	   <span>, but the wrapper holds the padding and the icon — so clicks on the
	   orange padding or the icon miss the handler and only the text itself fires
	   (the "Install needs two clicks / only the word works" report). Forward a
	   wrapper click to the span so the whole button is the hit target. The guard
	   skips clicks that already landed on the span (or its contents) so the
	   action fires exactly once, and buttons whose handler is elsewhere (no inner
	   span[onclick]) are untouched. */
	$("body").on("click", ".caButton", function(e) {
		var span = $(this).children("span[onclick]")[0];
		if (!span) return;
		if (e.target === span || (span.contains && span.contains(e.target))) return;
		span.click();
	});

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

	$("body").on("click mousedown", "#ca_homeSearchSubtitle", function(e) {
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
		var sortButton = false;
		$(".sortIcons").each(function() { if ($(this).hasClass("enabledIcon") && (!$(this).hasClass("startupMore"))) sortButton = true; });
		if (!sortButton) {
			$(".sortIcons").removeClass("enabledIcon").removeClass("startupMore");
			post({ action: "defaultSortOrder" }, function() { $("#defaultSort").addClass("enabledIcon"); doSearch(false, repo); });
		} else doSearch(false, repo);
	});

	$("body").on("click", ".templateSearch", function() {
		/* The Apps button is the one thing that leaves Docker Hub mode. Clear
		   data.docker first so the doSearch sticky docker intercept does not keep
		   the search in docker, and it runs against the app store instead. Also
		   clear committedSearchFilter: dockerSearch set it to the docker term,
		   which still equals the box text, so the doSearch same term no op guard
		   would otherwise early return and the app search would never run. */
		if (typeof data !== "undefined" && data) {
			data.docker = "";
			data.committedSearchFilter = "";
		}
		doSearch(false);
		/* Re-paint the desktop inline-suggestion strip against the current
		   #searchBox value. Entering Docker Hub mode closed/cleared it and
		   none of the doSearch paths re-evaluate awesomplete, so without
		   this nudge the chips stay empty until the user backspaces or
		   types another character. Mobile dropdown isn't affected — its
		   modal isn't open on this transition. */
		if (typeof inlineSearchAwesomplete !== "undefined" && inlineSearchAwesomplete) {
			try { inlineSearchAwesomplete.evaluate(); } catch (e) { /* no-op */ }
		}
	});
	/**
	 * Click an XML install button: POST `createXML` with the selected
	 * template type, then redirect to the AddContainer page on success.
	 */
	$("body").on("click", ".xmlInstall", function() {
		swal.close();
		mySpinner(0);
		var type = $(this).data("type");
		var xml = $(this).data("xml");
		/* saveState() is intentionally not called here — by the time this click
		   handler fires, showSidebarApp() has already taken the snapshot. */
		post({ action: "createXML", xml: xml, type: type }, function(result) {
			if (result.status == "ok") {
				// Server remapped conflicting host ports — stash the note so the
				// AddContainer page can surface it once (ca_browser_back_helper.page).
				if (result.portAdjustMessage)
					caSessSet("ca_port_adjustments", result.portAdjustMessage);
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
		/* Parent of a sub-menu: clicking it only expands the subs (no fetch),
		   so on mobile we keep the menu open until the user actually picks
		   one — the auto "All" entry or a real sub. */
		if ($(this).next(".subCategory").length) { /* leave menu open */ }
		else { scrollToTop(); closeMenu(); }
	});
	/**
	 * Settings gear (far right of the search area) opens the settings panel.
	 * Delegated on body because the search area is relocated on some themes.
	 * It is a role=button, so Enter and Space activate it as well as a click.
	 */
	$("body").on("click", ".caSettingsGear", function(e) {
		e.stopPropagation();
		showSettings();
	});
	$("body").on("keydown", ".caSettingsGear", function(e) {
		if (e.key === "Enter" || e.key === " " || e.key === "Spacebar") {
			e.preventDefault();
			e.stopPropagation();
			showSettings();
		}
	});
	/**
	 * Info button (search row, left of the gear) opens the Credits panel. The
	 * Credits panel itself carries Statistics and Change Log buttons at the top
	 * that open those panels. All are role=button (Enter / Space activate too) and
	 * body-delegated, so the handlers also reach the Credits buttons after the
	 * panel is cloned into the live sidebar.
	 */
	$("body").on("click keydown", ".caCreditsInfo, .caCreditsStatistics, .caCreditsChangeLog, .caCreditsDebugging", function(e) {
		if (e.type === "keydown" && e.key !== "Enter" && e.key !== " " && e.key !== "Spacebar") return;
		e.preventDefault();
		e.stopPropagation();
		if ($(this).hasClass("caCreditsInfo")) showCredits();
		/* Mark the origin so Statistics / Change Log show a back arrow to Credits
		   (and outside-click returns there). showCredits clears it again. */
		else if ($(this).hasClass("caCreditsStatistics")) { window.caSidebarBackTarget = "credits"; showStatistics(); }
		else if ($(this).hasClass("caCreditsChangeLog")) { window.caSidebarBackTarget = "credits"; caChangeLog(); }
		/* Logs button: build and download the debug zip. Stays on the Credits
		   view, so no back target is set. */
		else if ($(this).hasClass("caCreditsDebugging")) caDownloadDebugging();
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
	/* Exclude .caLiveStatsTab: those tabs also carry .chartMenu but belong to the
	   live-stats block and have their own handler (popupLiveStatsBlock). Without
	   the filter this handler would clear their chartTabActive when a graph tab is
	   clicked. Scope removeClass/hide to the clicked tab's own .popupChartBlock so
	   the two chart strips never fight over each other's active tab. */
	$(".sidebar").on("click", ".chartMenu:not(.caLiveStatsTab)", function() {
		/* chartTabActive (not the nav's selectedMenu) marks the active chart tab
		   so a bare $(".selectedMenu") never mistakes a sidebar chart tab for the
		   selected left-nav menu item. */
		if ($(this).hasClass("chartTabActive")) return;
		var $chartBlock = $(this).closest(".popupChartBlock");
		$chartBlock.find(".chartMenu").removeClass("chartTabActive");
		$(this).addClass("chartTabActive");
		$chartBlock.find(".caChart").hide();
		$("#" + $(this).data("chart")).show();
	});
	$(".sidebar").on("click", ".popUpBack", function() {
		/* Statistics / Change Log opened from Credits route back to Credits;
		   every other sidebar's back arrow returns to the app popup. */
		if (window.caSidebarBackTarget === "credits") { showCredits(); return; }
		showSidebarApp(caSessGet("sidebarAppPath"), caSessGet("sidebarAppName"));
	});
	/**
	 * Menu-selection state: highlight the clicked `.caMenuItem` as
	 * `selectedMenu`, slide-open its sub-menu, and collapse siblings when
	 * appropriate (with special-case behavior for the Repositories item).
	 */
	$("body").on("click", ".caMenuItem", function() {
		if ($(this).hasClass("caMenuDisabled") || $(this).hasClass("noSelect") || $(this).attr("onclick")) return;
		if (!$(this).hasClass("startupButton")) {
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
		var section = $(this).attr("data-category");
		showSortIcons();
		switch (section) {
			case "installed_apps": data.previousAppsSection = ""; previousApps(true); break;
			case "inst_docker": data.previousAppsSection = "docker"; previousApps(true, false, "docker"); break;
			case "inst_plugins": data.previousAppsSection = "plugins"; previousApps(true, false, "plugins"); break;
			case "previous_apps": data.previousAppsSection = ""; previousApps(false, true); break;
			case "prev_docker": data.previousAppsSection = "docker"; previousApps(false, true, "docker"); break;
			case "prev_plugins": data.previousAppsSection = "plugins"; previousApps(false, true, "plugins"); break;
			case "action_centre": data.previousAppsSection = ""; actionCentre(); break;
			case "pinned_apps": pinnedApps(); break;
		}
	});
	/**
	 * Open a Home "Show More" section: select the section's category, set the
	 * home subtitle (which also reveals + highlights the Home submenu), clear
	 * search, scroll to top, and POST the section's sort order before fetching
	 * its full content. Shared by the Show More card overlays and the Home
	 * submenu items so both behave identically.
	 */
	function caOpenHomeSection(description, category, sortby, sortdir) {
		/* Select the matching Home submenu item itself, then treat the Home
		   submenu exactly like any other subcategory: caHideUnselectedSubs keeps a
		   .subCategory expanded while it holds the .selectedMenu item and collapses
		   it only once a leaf elsewhere is picked — so the home sections persist
		   like Installed/Previous do, instead of snapping shut. */
		$(".caMenuItem").removeClass("selectedMenu");
		var $homeItem = $(".caHomeSectionItem").filter(function() {
			return String($(this).data("des")) === description;
		});
		$homeItem.addClass("selectedMenu");
		$homeItem.closest(".subCategory").show("fast");
		var sortOrder = {};
		if (sortby) {
			sortOrder.sortBy = sortby;
			sortOrder.sortDir = sortdir;
			$(".sortIcons").removeClass("enabledIcon").addClass("startupMore");
		}
		$("#searchBox").val("");
		data.committedSearchFilter = "";
		caSyncSearchFilterCollapsed();
		caSyncHomeSearchSubtitle();
		scrollToTop();
		post({ action: "changeSortOrder", sortOrder: sortOrder }, function() { getContent(false, category, description, false); });
	}
	/* Partial "Show more" card at the end of a Home section. */
	$(".mainArea").on("click", ".homeMore", function(e) {
		e.stopPropagation();
		caOpenHomeSection($(this).data("des"), $(this).data("category"), $(this).data("sortby"), $(this).data("sortdir"));
	});
	/* Home-section submenu item (revealed under Home once a Show More is used):
	   opens that section exactly like its Show More overlay. Bound on .menuItems
	   and stops propagation so the generic body ".caMenuItem" handler (which would
	   just scroll/closeMenu) doesn't double-fire. */
	$(".menuItems").on("click", ".caHomeSectionItem", function(e) {
		e.stopPropagation();
		caOpenHomeSection($(this).data("des"), $(this).data("cat"), $(this).data("sortby"), $(this).data("sortdir"));
		closeMenu();
	});
	$(".dockerSearch").click(function() { initDockerSearch(); });
	$("body").on("click", "#caAlphaBar .caAlphaLetter:not(.caAlphaOff)", function() {
		if ($(this).hasClass("caAlphaActive")) return;
		caJumpToLetter($(this).attr("data-letter"), $(this).attr("data-index"));
	});
	$("body").on("mouseenter", "#caAlphaBar .caAlphaLetter:not(.caAlphaOff)", function() {
		if ($(this).hasClass("caAlphaActive")) { $("#caAlphaHover").css("display", "none"); return; }
		var $h = $("#caAlphaHover");
		if (!$h.length) $h = $("<div id='caAlphaHover' aria-hidden='true'></div>").appendTo("body");
		$h.toggleClass("caAlphaWide", !!(typeof data !== "undefined" && data && data.alphaWide));
		var letter = $(this).attr("data-letter") || "";
		var r = this.getBoundingClientRect();
		var bar = document.getElementById("caAlphaBar");
		var br = bar ? bar.getBoundingClientRect() : r;
		$h.text((String(letter).length === 1) ? String(letter).toUpperCase() : letter).css("display", "block");
		$h.css({
			top: (r.top + r.height / 2 - $h.outerHeight() / 2) + "px",
			right: (window.innerWidth - br.left + 8) + "px",
			left: "auto"
		});
	});
	$("body").on("mouseleave", "#caAlphaBar", function() {
		$("#caAlphaHover").css("display", "none");
	});
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
		/* Always dismiss the mobile menu + blurred scrim. The generic
		   .caMenuItem handler skips closeMenu() for Home because Home is
		   usually the selected item (re-click suppression) and it sits
		   adjacent to the Home-sections .subCategory (treated as a sub-parent
		   that keeps the menu open) — so without this, clicking Home on mobile
		   leaves the menu and overlay up. No-op when the menu isn't showing. */
		closeMenu();
		/* Two modes (label-driven, see caSyncHomeMenuLabel):
		   - "Clear Search"  -> a search / docker / category filter is active.
		                        Just unwind that state in-page via appStore()
		                        so the home sections come back without a full
		                        page reload. Same path the search-box clear
		                        uses when there's no .startupButton.
		   - "Home"          -> nothing's active. Soft-reset back to the home view
		                        in-page via appStore() (no full page reload). */
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
		/* "Home": return to the home view in-page via appStore() — the same
		   soft-reset the search-clear uses — instead of a full page reload. */
		if (typeof appStore === "function") appStore();
	});
	/* Admin-only: the CA logo at the top of the menu reloads the page when
	   clicked. The .caMenuLogoReload class is emitted by skin.html only when
	   caIsAdmin() (along with role=button / tabindex), so this handler is inert
	   for non-admin users. Keydown mirrors the click for keyboard activation. */
	$("body").on("click", ".caMenuLogoReload", function(e) {
		e.preventDefault();
		window.location.reload();
	});
	$("body").on("keydown", ".caMenuLogoReload", function(e) {
		if (e.key === "Enter" || e.key === " " || e.key === "Spacebar") {
			e.preventDefault();
			window.location.reload();
		}
	});
	$(".multi_installButton").click(function() { if ($(".multi_installButton").hasClass("actionCenter")) updateMulti(); else installMulti(); });
	/**
	 * Sort-icon click: set the icon as active, reset paging/search state, and
	 * POST the new `changeSortOrder` before refetching via `changeSortOrder()`.
	 */
	$(".sortIcons").click(function() {
		/* Clear startupMore too: opening a Home section flags every icon
		   startupMore (a non-real, placeholder sort). A manual click is a real
		   sort, so drop that flag or the AZ-fallback checks (enabledIcon &&
		   !startupMore) would treat this pick as "no sort" and reset it. */
		$(".sortIcons").removeClass("enabledIcon").removeClass("startupMore");
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
	$("body").on("click", ".similarSearch", function() {
		var term = String($(this).data("search") || "");
		/* Reflect the term in the visible search input so the user sees what's
		   being searched (in docker mode the sticky intercept keeps it a Docker
		   Hub search for similar containers). */
		$("#caInlineSearchBox").val(term);
		$(".caInlineSearchClear").toggleClass("ca_hide", term === "");
		doSearch(false, term);
	});
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
 * Pop the bottom banner with a short message, then auto-hide it after 5s.
 * Lightweight toast - no modal, no reload, no countdown. Saves and restores
 * whatever the banner was showing so the page-geometry notice survives if it
 * happened to be up, and re-arms the timer cleanly on repeat calls.
 */
function caShowTransientBanner(message) {
	var $banner = $(".ca_bottomBanner");
	var $msg = $banner.find(".ca_pageGeometryChange");
	if ( ! $banner.length || ! $msg.length ) return;
	if ( window.caTransientBannerTimer ) {
		clearTimeout(window.caTransientBannerTimer);
	} else {
		window.caTransientBannerSaved = $msg.html();
	}
	$msg.text(message);
	$banner.removeClass("ca_hide");
	window.caTransientBannerTimer = setTimeout(function() {
		$banner.addClass("ca_hide");
		$msg.html(window.caTransientBannerSaved);
		window.caTransientBannerTimer = null;
	}, 5000);
}

/**
 * Build a timestamped filename, POST downloadDebugging, and redirect the
 * browser to the resulting zip URL. Wired to the Logs button on the Credits
 * panel and to the Cmd/Ctrl+Shift+D shortcut. Pops a transient banner so the
 * user gets feedback that the zip is being prepared.
 */
function caDownloadDebugging() {
	caShowTransientBanner(tr("Debugging Logs Downloading"));
	var tzoffset = (new Date()).getTimezoneOffset() * 60000;
	var localISOTime = (new Date(Date.now() - tzoffset)).toISOString().slice(0, -1);
	var filename = "CA-Logging-" + localISOTime.substr(0, 16).replace(/[-:]/g, "").replace("T", "-") + ".zip";
	post({ action: "downloadDebugging", file: filename }, function(result) {
		if (result && result.zip) {
			location = result.zip;
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
	myCloseSpinner();

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

	/* Cmd/Ctrl+Shift+D downloads the debug logs from anywhere on the page. */
	$(document).on("keydown", function(e) {
		var key = e.key || "";
		if ((e.metaKey || e.ctrlKey) && e.shiftKey && (key === "d" || key === "D")) {
			e.preventDefault();
			caDownloadDebugging();
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

		target.src = "/plugins/dynamix.docker.manager/images/question.png";
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
			if (typeof doSearch !== "function") return;
			/* Home sections (Recent / Spotlight / Trending / ...) leave the sort
			   icons in the startupMore state, which is not a real, search-meaningful
			   order. Mirror the Enter / search-button behavior: when no real sort is
			   active (no enabledIcon that isn't startupMore), fall back to the
			   default order (Name ascending / AZ) before searching. */
			var sortButton = false;
			$(".sortIcons").each(function() {
				if ($(this).hasClass("enabledIcon") && (!$(this).hasClass("startupMore"))) sortButton = true;
			});
			if (!sortButton) {
				$(".sortIcons").removeClass("enabledIcon").removeClass("startupMore");
				post({ action: "defaultSortOrder" }, function() {
					$("#defaultSort").addClass("enabledIcon");
					doSearch(false, val);
				});
			} else {
				doSearch(false, val);
			}
		}

		function inlineSoftClear() {
			inFlightVal = null;
			pendingVal  = null;
			$("#searchBox").val("");
			/* Clearing the search leaves Docker Hub mode (back to the app store) —
			   appStore() below resets data.docker. */

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

		/* Seed the closure committed term without firing a search. Used by
		   caShowInApps, which drops a synthetic URL label into the box
		   programmatically. Setting lastCommitted to match makes the box treat
		   it as a normal active term, so backspacing to empty triggers the
		   usual clear and restore instead of being read as a no op. */
		window.caInlineSearchSetCommitted = function(v) {
			lastCommitted = String(v == null ? "" : v);
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
			/* Same docker rule as the input handler: a parked sub 2 char term
			   must not auto revert to the app store. Stay in docker and wait for
			   the user to type a real term or hit Apps or Clear Search. */
			if (typeof data !== "undefined" && data && data.docker && next.length < 2) {
				return;
			}
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
			/* Docker Hub searches hit Docker's API live (no cache), so use a
			   slower 1s debounce in docker mode vs 300ms for the local app store. */
			var debounceMs = (typeof data !== "undefined" && data && data.docker) ? 1000 : 300;
			inlineTimer = setTimeout(function() {
				/* Docker mode: while the term is below the 2 char minimum,
				   including empty, do nothing. Do not fire a docker search and
				   do not fall back to the app store. The user is mid edit, so
				   let them backspace all the way out and type a new term.
				   Leaving docker only happens via the Apps or Clear Search
				   buttons, never an auto revert on backspace. */
				if (typeof data !== "undefined" && data && data.docker && val.length < 2) {
					return;
				}
				/* In-flight? Stash latest only. Older queued values get
				   dropped. caInlineSearchOnComplete will flush whatever is
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
			}, debounceMs);
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
			/* Bookkeeping is gated on a change since the last tick. Always re-sync
			   lastCommitted so a Home click that clears #searchBox to "" doesn't
			   leave the closure thinking the user is "still" on their previous
			   search term. */
			if (sb !== lastMirror) {
				lastMirror = sb;
				if (lastCommitted !== sb) lastCommitted = sb;
			}
			/* Reconcile the visible inline input every tick, NOT only when sb
			   changed since last tick. A clearSearchBox()+doSearch() pair (e.g.
			   re-clicking Favourite Repo) drives #searchBox value -> "" -> value
			   within one synchronous turn, so the interval never observes the
			   intermediate "". Gating the inline reconcile on sb !== lastMirror
			   then skipped it, leaving the box blank while clearSearchBox had
			   already emptied it. */
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
 * Snapshots the initial (post-legacy-disable) form state itself for dirty comparison.
 */
function caBindSettingsFormHandlers() {
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
	/* Re-appliable so the factory-reset toggle below can restore these locks
	   after it temporarily disables every switch. */
	function caApplyLegacyDisables() {
		if ($("html").hasClass("Theme--responsive")) return;
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
	caApplyLegacyDisables();

	/* Stash the initial serialized state on the form so closeSidebar() can
	   detect dirty fields and auto-submit when the panel is dismissed. Snapshot
	   AFTER caApplyLegacyDisables() above, not in the caller: a disabled checkbox
	   is dropped from serialize(), so a baseline taken while it was still enabled
	   (and checked, i.e. its value differs from the default) would never match the
	   post-disable serialize() at close time — the form would look permanently
	   dirty and auto-submit/reload on every close even though nothing changed. */
	$form.data("caInitialState", $form.serialize());

	/* Cancel / Default / factory-reset controls. Bound delegated on the
	   persistent #sidenavContent (off/on so re-opening doesn't stack them).
	   Nothing here saves - the existing dirty-on-close submit handles that. */
	/* The Cancel / Default controls are <div role=button> (caButton), so their
	   disabled state is the .ca_disabled class, not the disabled property. The
	   real <input> switches still use prop("disabled"). */
	$sidenav.off("change.caSettingsDirty", "input.caSettingSwitch")
		.on("change.caSettingsDirty", "input.caSettingSwitch", function() {
			$sidenav.find(".caSettingsCancel").removeClass("ca_disabled");
		});
	/* Cancel: restore every switch to the user's saved (server-rendered) state
	   via defaultChecked, drop the factory-reset row, re-enable everything. */
	$sidenav.off("click.caSettingsCancel", ".caSettingsCancel")
		.on("click.caSettingsCancel", ".caSettingsCancel", function() {
			if ($(this).hasClass("ca_disabled")) return;
			/* Setting .checked / .prop("checked") programmatically does NOT fire a
			   change event, so the change.caSettingsDirty handler below stays quiet
			   during this reset - the final addClass("ca_disabled") is not racing
			   any removeClass and the form ends up clean + Cancel disabled. */
			$form.find("input.caSettingSwitch").each(function() { this.checked = this.defaultChecked; });
			$form.find(".caFactoryReset").prop("checked", false);
			$form.find("input.caSettingSwitch").prop("disabled", false);
			$form.find(".ca_settingCard").not(".caFactoryResetCard").removeClass("ca_settingDisabled");
			$sidenav.find(".caSettingsDefault").removeClass("ca_disabled");
			caApplyLegacyDisables();
			$(this).addClass("ca_disabled");
		});
	/* Default: set every switch to its default.cfg value (data-default-on) and
	   reveal the factory-reset row. Counts as a change, so Cancel turns on. */
	$sidenav.off("click.caSettingsDefault", ".caSettingsDefault")
		.on("click.caSettingsDefault", ".caSettingsDefault", function() {
			if ($(this).hasClass("ca_disabled")) return;
			$form.find("input.caSettingSwitch").each(function() {
				this.checked = ($(this).attr("data-default-on") === "1");
			});
			$sidenav.find(".caSettingsCancel").removeClass("ca_disabled");
		});
	/* Factory reset: while checked, lock out every other setting option. */
	$sidenav.off("change.caFactoryReset", ".caFactoryReset")
		.on("change.caFactoryReset", ".caFactoryReset", function() {
			var on = this.checked;
			$sidenav.find(".caSettingsCancel").removeClass("ca_disabled");
			$form.find("input.caSettingSwitch").prop("disabled", on);
			$form.find(".ca_settingCard").not(".caFactoryResetCard").toggleClass("ca_settingDisabled", on);
			$sidenav.find(".caSettingsDefault").toggleClass("ca_disabled", on);
			if (!on) caApplyLegacyDisables();
		});

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
		/* Factory reset: tell saveSettings to delete the cfg + pinned / accepted /
		   admin files instead of writing the toggles. The page reload that follows
		   then comes up on stock defaults. */
		if ($localForm.find(".caFactoryReset").is(":checked")) settings.caFactoryReset = "1";
		$localForm.find("input.caSettingSwitch").each(function() {
			if (!this.name) return;
			settings[this.name] = this.checked
				? this.value
				: ($localForm.find("input[type=hidden][name='" + this.name + "']").val() || this.value);
		});
		/* Only raise the reload countdown once the save is confirmed — a failed
		   save (network/server error) shouldn't reload the page onto settings
		   that never persisted. On failure, clear the submitting guard and warn
		   so the user can retry. */
		postNoSpin(settings, function(result) {
			if (!result || !result.ok) {
				$localForm.data("submitting", false);
				addBannerWarning(tr("Failed to save settings. Please try again."), false, true);
				return;
			}
			caShowReloadNoticeBanner();
		});
		return false;
	});
}
