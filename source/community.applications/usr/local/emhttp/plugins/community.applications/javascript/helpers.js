/*
########################################
#                                      #
# Community Applications               #
# Copyright 2020-2025, Lime Technology #
# Copyright 2015-2025, Andrew Zawadzki #
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
  clearTimeout(ca_longLoading);
  clearTimeout(ca_veryLongLoading);
  clearTimeout(ca_somethingWrong);
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
  return false;
  // Optimized to minimize forced reflows by using the most efficient DOM properties
  // offsetWidth/offsetHeight are generally faster than clientWidth/clientHeight
  
  if (type) {
    // For horizontal overflow: compare scrollable content width with element's offset width
    return el.scrollWidth > el.offsetWidth;
  } else {
    // For vertical overflow: compare scrollable content height with element's offset height  
    return el.scrollHeight > el.offsetHeight;
  }
}


function disableSearch() {
  $("#searchBox").prop("disabled",true);
}

function enableSearch() {
  $("#searchBox").prop("disabled",false);
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

function tr(string) {
 return _(string);
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

var ca_longLoading = false;
var ca_veryLongLoading = false;
var ca_somethingWrong = false;

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
      ca_longLoading = setTimeout(function() {
        slowPost(tr('Taking longer than expected. Please wait...'));
      }, 20000);
      ca_veryLongLoading = setTimeout(function() {
        clearTimeout(ca_longLoading);
        slowPost(tr('Still taking longer than expected. Please wait...'));
      },30000);
  
      ca_somethingWrong = setTimeout(function() {
        clearTimeout(ca_veryLongLoading);
        slowPost(tr('Taking far longer than expected.  Investigate possible network / internet connection hardware issues. Still attempting to load.  Please wait... Aborting will recover, but might cause Community Applications some issues.')+"<div class='long-loading-abort-button caButton'>"+tr('Abort')+"</div>");
      }, 40000);
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

// Dims the display area
function dimScreen(dim) {
  if ( dim ) {
    $("#header, #menu").addClass("dim",250);
    if ( $(".mobileMenu").is(":visible") ) {
      $(".mainArea").addClass("dim",250);
    } else {
      $(".ca_display_area").addClass("dim",250);
    }
  } else {
    $("#header, #menu, .ca_display_area, .mainArea").removeClass("dim",250);
  }
}

function setupSwalDim() {
  if ( $(".sweet-alert").length == 0 ) {
    setTimeout(function() {
      setupSwalDim();
    }, 100);
    return; // Wait for swal to be initialized
  }
  $(".sweet-alert").onClassChange(function(el,className) {
    if ( className.includes("showSweetAlert") ) {
      dimScreen(true);
      // If the spinner is showing, close it.  It may be showing due to the post calls when updating content.
    } else {
      dimScreen(false);
    }
  });
  $(".sweet-alert").addClass("triggerClassChange");
}
