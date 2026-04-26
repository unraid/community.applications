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

/** Collapse search input to icon-only when empty; keep open if there is text or an active search (X icon). */
function caSyncSearchFilterCollapsed() {
  var $f = $("#searchFilter");
  var $box = $("#searchBox");
  if (!$f.length || !$box.length) return;
  if ($.trim($box.val()) !== "" || $("#searchButton").hasClass("fa-remove")) {
    $f.removeClass("ca_searchInputCollapsed");
    return;
  }
  if ($box.is(":focus")) {
    return;
  }
  $f.addClass("ca_searchInputCollapsed");
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
  if (!$el.length) return;
  if (typeof data === "undefined" || !data) return;
  var v = $.trim(String(data.committedSearchFilter || ""));
  if (!v) {
    $el.empty().addClass("ca_hide");
    return;
  }
  $el.text(v).removeClass("ca_hide");
}

/** If the box was edited (e.g. backspaced) but not submitted, put the last committed search back. Called from the nav menu (#mobileMenu) or page change (changePage / dockerSearch). */
function caRestoreCommittedSearchIfDrafted() {
  if (typeof data === "undefined" || !data) return;
  var c = $.trim(String(data.committedSearchFilter || ""));
  if (!c) return;
  var cur = $.trim(String($("#searchBox").val() || ""));
  if (cur === c) return;
  $("#searchBox").val(c);
  $("#searchButton").removeClass("fa-search").addClass("fa-remove");
  caSyncSearchFilterCollapsed();
}

function tr(string) {
 return _(string);
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

    setTimeout(function() { window.location.reload(); }, ms);
  } catch(e) {
    setTimeout(function() { window.location.reload(); }, 10000);
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
  const templatesContent = document.querySelector('#templates_content');
  const caDisplayArea = document.querySelector('.ca_display_area');

  if (!templatesContent || !caDisplayArea) return 0;

  const sampleApp = document.querySelector('#sampleApp');
  const hasTemplatesDisplayChild = Array.from(templatesContent.children).some(function(el) {
    return el.classList && el.classList.contains('ca_templatesDisplay');
  });
  const shouldUseSample = !!(sampleApp && !hasTemplatesDisplayChild);

  if (shouldUseSample) {
    templatesContent.innerHTML = sampleApp.innerHTML;
  }

  try {
    const sample = templatesContent.querySelector('.ca_holder');
    if (!sample) return 0;

    const rect = sample.getBoundingClientRect();
    const style = getComputedStyle(sample);
    const fullWidth = rect.width + (parseFloat(style.marginLeft) || 0) + (parseFloat(style.marginRight) || 0);
    const fullHeight = rect.height + (parseFloat(style.marginTop)  || 0) + (parseFloat(style.marginBottom) || 0);

    const templatesDisplay = templatesContent.querySelector('.ca_templatesDisplay');
    if (!templatesDisplay) return 0;
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

    const perRow = Math.floor(availableWidth  / fullWidth);
    const perCol = Math.floor(availableHeight / fullHeight);
    if (perRow < 3) return 4;
    return perRow * perCol;
  } finally {
    if (shouldUseSample) {
      // Keep copied sample markup in place for inspection/debugging.
    }
  }
}