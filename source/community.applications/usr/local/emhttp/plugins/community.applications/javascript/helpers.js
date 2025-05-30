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

function mySpinner() {
  $("div.spinner").show();
  $(".spinnerBackground").show();
}

function myCloseSpinner() {
  $("div.spinner,.spinnerBackground").hide();
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
  if (type)
    return (el.scrollWidth > el.clientWidth);
  else
    return (el.scrollHeight > el.clientHeight);

  return (el.scrollHeight > el.clientHeight) || (el.scrollWidth > el.clientWidth)||(el.offsetWidth < el.scrollWidth);
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
  if ( typeof options === "function" ) {
    callback = options;
  } else {
    var msg = "No Spin Post: ";
    console.log(msg+JSON.stringify(options));
  }

  if ( typeof callback === "function" ) {
    $.post(execURL,options,function(result){
      try {
        callback(result);
      } catch(e) {
        if ( ! data.loggedOut ) {
          post({action:'javascriptError',postCall:options.action,retval:result});
          alert("Fatal error during "+options.action+" "+e);
        }
      }
      if (result.script) {
        try {
          eval(result.script);
        } catch(e) {
          alert("Could not execute Script "+e);
        }
      }
    });
  } else {
    $.post(execURL,options);
  }
}

function post(options,callback) {
  if ( typeof options === "function" ) {
    callback = options;
  } else {
    var msg = postCount > 0 ? "Embedded Post: " : "Post: ";
    console.log(msg+JSON.stringify(options));
  }

  if ( postCount == 0 && ! options.noSpinner ) {
    mySpinner();
  }
  postCount++;
  console.log("Post Count: "+postCount);
  if ( typeof callback === "function" ) {
    $.post(execURL,options,function(result){
       try {
        callback(result);
      } catch(e) {
        if ( ! data.loggedOut ) {
          post({action:'javascriptError',postCall:options.action,retval:result});
          alert("Fatal error during "+options.action+" "+e);
        }
      }
      if (result.script) {
        try {
          eval(result.script);
        } catch(e) {
          alert("Could not execute Script "+e);
        }
      }
      if (result.globalScript) {
        try {
          eval(result.globalScript);
        } catch(e) {
          alert("Could not execute Script "+e);
        }
      }
      postCount--;
      if (postCount < 0) postCount = 0;
      if ( postCount == 0 && ! options.noSpinner) {
        myCloseSpinner();
      }
    }).fail(function(){
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
  } else {
    $.post(execURL,options);
    postCount--;
    if ( postCount < 0 ) postCount = 0;
    if ( postCount == 0) {
      myCloseSpinner();
    }
  }
  if ( ! cookiesEnabled() ) {
    if ( cookieWarning === false) {
      cookieWarning = addBannerWarning(tr("Community Applications works best when cookies are enabled in your browser.  Certain features may not be available."));
    }
  } else {
    if ( cookieWarning !== false ) {
      removeBannerWarning(cookieWarning);
      cookieWarning = false;
    }
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
    showConfirmButton: showConfirm,
    showCancelButton: showCancel,
    cancelButtonText: tr("Cancel"),
    type: alertType,
    animation: false,
    html: true
  });
}

function guiSearchOnUnload() {
  saveState();
}