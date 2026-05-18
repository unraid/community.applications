/**
 * Community Applications — dev-mode template diff renderer.
 *
 * Loaded only when developer mode is enabled (Apps.page guards the <script>
 * tag with a PHP `caSettings['dev']` check). The companion server endpoint
 * `getTemplateDiff` returns the two XML strings + URL; everything here is
 * client-side: line-level LCS diff, paired row alignment, HTML build, and
 * scroll synchronization between the two diff columns.
 *
 * Public entry points:
 *   caShowTemplateDiff(appPath, appName) — fetch + render + open overlay
 *   caHideTemplateDiff()                 — dismiss overlay
 *
 * Both are referenced by inline onclick handlers (skin.html / supportContext)
 * and the body keydown handler in clickHandlers.js, so they need to be
 * globally addressable — keep them as plain function declarations.
 */

/**
 * Dev-mode Diff button: fetch the unified diff between the appfeed entry and
 * the upstream template/.plg, then render it into the #caDiffView overlay.
 *
 * @param {string} appPath The template Path (matches appfeed Path/InstallPath)
 * @param {string} appName Display name for the dialog title
 */
function caShowTemplateDiff(appPath, appName, mode) {
	mode = mode || "feed";
	var $content = $("#caDiffContent");
	if (!$content.length) return;
	/* Open the overlay immediately with the loading template from skin.html.
	   Using postNoSpin so the rest of the page stays interactive while the
	   applicationFeed.json download runs — and so the nchan subscriber for
	   ca_downloadProgress (Apps.page) gets to take over after 5s and show
	   live progress text in the .caDownloadProgress strip. */
	$content.html($(".caDiffLoadingTemplate").html() || "");
	$("#caDiffView").removeClass("ca_hide");

	/* Stamp this click with a monotonic id and capture it in the callback's
	   closure — if a newer click has fired by the time the callback runs
	   (or the user closed the overlay), drop the stale response on the floor
	   so we never paint result N-1 on top of the loading screen for result N. */
	caShowTemplateDiff._seq = (caShowTemplateDiff._seq || 0) + 1;
	var myReqId = caShowTemplateDiff._seq;

	postNoSpin({ action: "getTemplateDiff", appPath: appPath, mode: mode }, function(result) {
		if (myReqId !== caShowTemplateDiff._seq) return;
		if ($("#caDiffView").hasClass("ca_hide")) return;
		$content.empty();
		if (!result || !result.ok) {
			var msg = (result && result.message) ? result.message : tr("Could not generate diff");
			$content.html("<div class='ca_diffEmpty'>" + caEscapeHtml(msg) + "</div>");
			return;
		}
		if (result.identical) {
			$content.html("<div class='ca_diffEmpty'>" + tr("No differences found") + "</div>");
			return;
		}
		/* Server supplies title + column labels (already escaped / wrapped in
		   anchor as needed) — JS just renders. Title falls back to the appName
		   + a localized suffix if the server didn't provide one. */
		var titleText = result.title || ((appName ? stripTags(appName) + " " : "") + tr("Template Diff"));
		var html = caRenderDiff(
			result.left  || "",
			result.right || "",
			result.leftLabel  || caEscapeHtml(tr("source")),
			result.rightLabel || caEscapeHtml(tr("appfeed")),
			titleText
		);
		$content.html(html);
		/* Sync vertical scroll between the two diff columns so matched-row
		   alignment stays intact when the user scrolls. Horizontal scroll
		   stays per-column on purpose — each side scrolls independently so
		   you can read long source lines without disturbing the appfeed view. */
		var cols = document.querySelectorAll("#caDiffContent .ca_diffCol");
		if (cols.length === 2) {
			var locked = false;
			function bind(src, dst) {
				src.addEventListener("scroll", function() {
					if (locked) return;
					locked = true;
					dst.scrollTop = src.scrollTop;
					requestAnimationFrame(function() { locked = false; });
				});
			}
			bind(cols[0], cols[1]);
			bind(cols[1], cols[0]);
		}
	});
}

/**
 * Tear down the dev-mode diff overlay. Leaves the sidebar and the page's
 * modal scrim (blurred templates underneath) in place — the diff is the only
 * thing that goes away.
 */
function caHideTemplateDiff() {
	$("#caDiffView").addClass("ca_hide");
	$("#caDiffContent").empty();
}

/**
 * Escape `&<>"'` for safe injection into HTML text/attribute context. Small,
 * inlined here so the diff renderer doesn't depend on jQuery .text() round-trips.
 */
function caEscapeHtml(s) {
	if (s === null || s === undefined) return "";
	return String(s)
		.replace(/&/g, "&amp;")
		.replace(/</g, "&lt;")
		.replace(/>/g, "&gt;")
		.replace(/"/g, "&quot;")
		.replace(/'/g, "&#039;");
}

/**
 * Line-level edit script aligning two arrays of lines via LCS DP. Returns
 * an array of [kind, leftLine, rightLine] tuples, kind ∈ {"eq","del","add"}.
 * "del" sets rightLine to null; "add" sets leftLine to null. Quadratic in
 * line count — fine for template-sized inputs (~50-500 lines each side).
 *
 * @param {string[]} a Left side lines (typically the upstream source XML)
 * @param {string[]} b Right side lines (typically the appfeed-derived XML)
 * @returns {Array<[string, ?string, ?string]>}
 */
function caComputeLineOps(a, b) {
	var n = a.length, m = b.length;
	if (n === 0 && m === 0) return [];
	var dp = new Array(n + 1);
	for (var i = 0; i <= n; i++) {
		dp[i] = new Array(m + 1).fill(0);
	}
	for (var ii = 1; ii <= n; ii++) {
		for (var jj = 1; jj <= m; jj++) {
			dp[ii][jj] = (a[ii - 1] === b[jj - 1])
				? dp[ii - 1][jj - 1] + 1
				: Math.max(dp[ii - 1][jj], dp[ii][jj - 1]);
		}
	}
	var ops = [];
	var ci = n, cj = m;
	while (ci > 0 || cj > 0) {
		if (ci > 0 && cj > 0 && a[ci - 1] === b[cj - 1]) {
			ops.push(["eq", a[ci - 1], b[cj - 1]]);
			ci--; cj--;
		} else if (cj > 0 && (ci === 0 || dp[ci][cj - 1] >= dp[ci - 1][cj])) {
			ops.push(["add", null, b[cj - 1]]);
			cj--;
		} else {
			ops.push(["del", a[ci - 1], null]);
			ci--;
		}
	}
	return ops.reverse();
}

/**
 * Build the side-by-side diff HTML from two XML strings. Headers + two
 * synchronized .ca_diffCol panes with per-row blank placeholders so the
 * matched lines stay vertically aligned. Mirrors the structure that
 * caShowTemplateDiff's scroll-sync expects.
 *
 * @param {string} aText Left side text (source XML)
 * @param {string} bText Right side text (appfeed XML)
 * @param {string} labelA Pre-escaped HTML for the left column header (eg. an <a>)
 * @param {string} labelB Pre-escaped HTML for the right column header
 * @param {string} [title] Plain text title rendered above the column headers
 * @returns {string} HTML fragment
 */
function caRenderDiff(aText, bText, labelA, labelB, title) {
	var linesA = aText === "" ? [] : aText.split(/\r\n|\n|\r/);
	var linesB = bText === "" ? [] : bText.split(/\r\n|\n|\r/);
	var ops = caComputeLineOps(linesA, linesB);

	/* Group consecutive del/add runs so a removed line and the corresponding
	   added line render on the same row when they appear next to each other —
	   matches the layout `diff -y` produces. Extra unpaired lines on either
	   side continue one-sided below. */
	var rows = [];
	var dels = [], adds = [];
	function flushRun() {
		var n = Math.max(dels.length, adds.length);
		for (var i = 0; i < n; i++) {
			rows.push([
				i < dels.length ? "del" : "blank",
				i < dels.length ? dels[i] : null,
				i < adds.length ? "add" : "blank",
				i < adds.length ? adds[i] : null
			]);
		}
		dels = [];
		adds = [];
	}
	for (var k = 0; k < ops.length; k++) {
		var op = ops[k];
		if (op[0] === "eq") {
			flushRun();
			rows.push(["eq", op[1], "eq", op[2]]);
		} else if (op[0] === "del") {
			dels.push(op[1]);
		} else {
			adds.push(op[2]);
		}
	}
	flushRun();

	/* Highlight just the text on changed lines (inline <span>) rather than
	   tinting the whole row. The wrapper div still owns layout/min-height so
	   row alignment between columns survives, but the colored background
	   hugs the actual content. For paired del/add rows (a removal next to its
	   replacement) we run a second character-level LCS so the *specific*
	   characters that differ get a darker tint, while the unchanged
	   characters stay in the lighter line shade. */
	var leftRows = "", rightRows = "";
	for (var r = 0; r < rows.length; r++) {
		var row = rows[r];
		var lKind = row[0], lText = row[1], rKind = row[2], rText = row[3];
		var lInner, rInner;
		if (lKind === "del" && rKind === "add" && lText !== null && rText !== null) {
			/* Char-level diff between the two paired lines. caComputeLineOps
			   is generic over arrays — feeding it character arrays gives a
			   per-character edit script. */
			var charOps = caComputeLineOps(lText.split(""), rText.split(""));
			var lHtml = "", rHtml = "";
			for (var ci = 0; ci < charOps.length; ci++) {
				var op = charOps[ci];
				if (op[0] === "eq") {
					lHtml += caEscapeHtml(op[1]);
					rHtml += caEscapeHtml(op[2]);
				} else if (op[0] === "del") {
					lHtml += "<span class='ca_diffCharDel'>" + caEscapeHtml(op[1]) + "</span>";
				} else {
					rHtml += "<span class='ca_diffCharAdd'>" + caEscapeHtml(op[2]) + "</span>";
				}
			}
			lInner = "<span class='ca_diffText ca_diffDel'>" + lHtml + "</span>";
			rInner = "<span class='ca_diffText ca_diffAdd'>" + rHtml + "</span>";
		} else {
			lInner = (lText === null || lText === "")
				? "&nbsp;"
				: "<span class='ca_diffText" + (lKind === "del" ? " ca_diffDel" : "") + "'>" + caEscapeHtml(lText) + "</span>";
			rInner = (rText === null || rText === "")
				? "&nbsp;"
				: "<span class='ca_diffText" + (rKind === "add" ? " ca_diffAdd" : "") + "'>" + caEscapeHtml(rText) + "</span>";
		}
		leftRows  += "<div class='ca_diffRow'>" + lInner  + "</div>";
		rightRows += "<div class='ca_diffRow'>" + rInner + "</div>";
	}

	/* labelA / labelB are already HTML — caller (caShowTemplateDiff) may pass
	   anchor markup for the URL header, plain text otherwise. */
	var lblA = labelA || "";
	var lblB = labelB || "";
	var titleHtml = title ? "<div class='ca_diffTitle'>" + caEscapeHtml(title) + "</div>" : "";
	/* Wrap rows in an inner .ca_diffColInner that's inline-block + min-width:100%.
	   Without this, each .ca_diffRow's <div> box is constrained to the column's
	   visible content width — so the red/green background only covers that
	   slice and shifts off-screen left as the user scrolls horizontally. The
	   inner block expands to the widest row, every row inherits that width,
	   and backgrounds travel with the content. */
	return "<div class='ca_diffWrap'>"
	     + titleHtml
	     + "<div class='ca_diffHeader'><div>" + lblA + "</div><div>" + lblB + "</div></div>"
	     + "<div class='ca_diffSplit'>"
	     + "<div class='ca_diffCol'><div class='ca_diffColInner'>" + leftRows + "</div></div>"
	     + "<div class='ca_diffCol'><div class='ca_diffColInner'>" + rightRows + "</div></div>"
	     + "</div></div>";
}
