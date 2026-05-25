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
 * @param {string} templateUrl Template/Plugin URL — used server-side to look up
 *                             the appfeed entry directly in the raw snapshot,
 *                             so we don't load any templates cache.
 * @param {string} appName Display name for the dialog title
 * @param {string} mode    "feed" (default) or "internal"
 */
function caShowTemplateDiff(templateUrl, appName, mode) {
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

	postNoSpin({ action: "getTemplateDiff", templateUrl: templateUrl, mode: mode }, function(result) {
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

/* Bind the .caDiffClose click handler here rather than inline in skin.html.
   diff.js is only loaded in dev mode (Apps.page guard) and #caDiffView is
   only rendered in dev mode too, so the selector is guaranteed to match —
   no need for delegation through body. Wrapped in $(function(){...}) so the
   bind happens after skin.html is parsed even if diff.js loaded first. */
$(function() {
	$(".caDiffClose").on("click", caHideTemplateDiff);
});

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

/**
 * Dev-mode Plugin / Template button: fetch the URL server-side (getDevRawURL —
 * see exec.php; the GitHub release CDN forces Content-Disposition: attachment
 * on a direct <a href>, hence the proxy) and render the source into the
 * #caDiffView overlay instead of triggering a browser download.
 *
 * Template mode (asPlugin=false): single column showing the raw bytes.
 * Plugin mode (asPlugin=true): two columns — raw .plg on the left, the same
 * document with internal `<!ENTITY name "...">` references substituted inline
 * on the right, so the dev can see exactly what the runtime parser sees.
 *
 * Reuses the same #caDiffView markup, .caDiffLoadingTemplate, .ca_diffWrap /
 * .ca_diffSplit / .ca_diffCol structure, scroll-sync, and close handlers as
 * caShowTemplateDiff — only the row builder differs (no LCS alignment;
 * pre-formatted source rows instead of paired diff rows).
 *
 * @param {string}  url      Absolute https URL to fetch (the Template / Plugin URL)
 * @param {boolean} asPlugin When true, request + render the entity-decoded column
 */
function caShowDevSource(url, asPlugin) {
	asPlugin = !!asPlugin;
	var $content = $("#caDiffContent");
	if (!$content.length) return;
	$content.html($(".caDiffLoadingTemplate").html() || "");
	$("#caDiffView").removeClass("ca_hide");

	/* Monotonic sequence id — same staleness guard as caShowTemplateDiff: if
	   the user clicks another button (or closes the overlay) before the fetch
	   resolves, drop the late response on the floor rather than painting it
	   over whatever's now on screen. */
	caShowDevSource._seq = (caShowDevSource._seq || 0) + 1;
	var myReqId = caShowDevSource._seq;

	postNoSpin({ action: "getDevRawURL", url: url, decode: asPlugin ? "1" : "" }, function(result) {
		if (myReqId !== caShowDevSource._seq) return;
		if ($("#caDiffView").hasClass("ca_hide")) return;
		$content.empty();
		if (!result || !result.ok) {
			var msg = (result && result.message) ? result.message : tr("Could not fetch URL");
			$content.html("<div class='ca_diffEmpty'>" + caEscapeHtml(msg) + "</div>");
			return;
		}
		var leftRows = caBuildSourceRows(result.content);
		var html;
		if (asPlugin) {
			/* Server returns the parsed entity table (name=>value); we walk the
			   raw content here and wrap each substitution in .ca_entitySub so
			   the dev can see exactly which spans came from a DTD declaration.
			   An empty/missing table just yields a no-highlight render. */
			var entities = (result && result.entities) || {};
			var rightRows = caBuildDecodedRows(result.content, entities);
			html = caRenderDevSource(
				leftRows,
				rightRows,
				url,
				caEscapeHtml(tr("Raw")),
				caEscapeHtml(tr("Decoded"))
			);
		} else {
			html = caRenderDevSource(leftRows, null, url);
		}
		$content.html(html);
		/* Scroll-sync only matters in plugin mode (two columns). Same lock /
		   rAF pattern as caShowTemplateDiff so the columns can't bounce-loop
		   into each other while the user drags a scrollbar. */
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
 * Build the modal body for caShowDevSource. Reuses .ca_diffWrap / .ca_diffSplit
 * / .ca_diffCol so styling, scrollbar behavior, and the overlay scroll
 * indicators all match the diff view. When `rightRowsHtml` is null/undefined,
 * emits a single column (Template mode) — flex `1 1 50%` on .ca_diffCol means
 * one child grows to fill the row.
 *
 * Both row arguments are pre-built HTML so the caller controls per-side row
 * construction (plain caBuildSourceRows on the raw side, caBuildDecodedRows
 * with .ca_entitySub highlight spans on the decoded side).
 *
 * @param {string}  leftRowsHtml   HTML for the left (or only) column's rows
 * @param {?string} rightRowsHtml  HTML for the right column's rows; null for single column
 * @param {string}  titleText      Plain text title (URL) — escaped internally
 * @param {string} [leftLabel]     Pre-escaped HTML for the left header (two-col only)
 * @param {string} [rightLabel]    Pre-escaped HTML for the right header (two-col only)
 * @returns {string} HTML to inject into #caDiffContent
 */
function caRenderDevSource(leftRowsHtml, rightRowsHtml, titleText, leftLabel, rightLabel) {
	var titleHtml = titleText ? "<div class='ca_diffTitle'>" + caEscapeHtml(titleText) + "</div>" : "";
	if (rightRowsHtml === null || rightRowsHtml === undefined) {
		return "<div class='ca_diffWrap'>"
		     + titleHtml
		     + "<div class='ca_diffSplit'>"
		     + "<div class='ca_diffCol'><div class='ca_diffColInner'>" + leftRowsHtml + "</div></div>"
		     + "</div></div>";
	}
	var lblA = leftLabel  || "";
	var lblB = rightLabel || "";
	return "<div class='ca_diffWrap'>"
	     + titleHtml
	     + "<div class='ca_diffHeader'><div>" + lblA + "</div><div>" + lblB + "</div></div>"
	     + "<div class='ca_diffSplit'>"
	     + "<div class='ca_diffCol'><div class='ca_diffColInner'>" + leftRowsHtml + "</div></div>"
	     + "<div class='ca_diffCol'><div class='ca_diffColInner'>" + rightRowsHtml + "</div></div>"
	     + "</div></div>";
}

/**
 * Split text on any line ending and wrap each line in a .ca_diffRow div so the
 * existing diff CSS (white-space: pre, horizontal scroll on overflow) renders
 * the source as a code block. Blank lines become &nbsp; to preserve row height.
 */
function caBuildSourceRows(text) {
	if (text === null || text === undefined) return "";
	var lines = String(text).split(/\r\n|\r|\n/);
	var html = "";
	for (var i = 0; i < lines.length; i++) {
		html += "<div class='ca_diffRow'>"
		     + (lines[i] === "" ? "&nbsp;" : caEscapeHtml(lines[i]))
		     + "</div>";
	}
	return html;
}

/**
 * Render rawText with each `&name;` reference replaced by the corresponding
 * value from the server-supplied entity table, wrapping each substitution in a
 * .ca_entitySub span so the highlight shows up exactly where the DTD value
 * landed. References that aren't in the table (predefined &amp;/&lt;/etc. or
 * unknown names) are passed through verbatim and rendered un-highlighted.
 *
 * Walks the raw text once, building rows + inline spans in a single pass.
 * Substitution values that happen to contain newlines are split across rows
 * with each line wrapped in its own span — keeps the column alignment intact.
 */
function caBuildDecodedRows(rawText, entities) {
	if (rawText === null || rawText === undefined) return "";
	entities = entities || {};
	var html = "";
	var lineBuf = "";
	function flushRow() {
		html += "<div class='ca_diffRow'>" + (lineBuf === "" ? "&nbsp;" : lineBuf) + "</div>";
		lineBuf = "";
	}
	function appendLiteral(text) {
		if (text === "") return;
		var parts = text.split(/\r\n|\r|\n/);
		for (var i = 0; i < parts.length; i++) {
			if (i > 0) flushRow();
			lineBuf += caEscapeHtml(parts[i]);
		}
	}
	function appendSub(value) {
		var parts = String(value).split(/\r\n|\r|\n/);
		for (var i = 0; i < parts.length; i++) {
			if (i > 0) flushRow();
			lineBuf += "<span class='ca_entitySub'>" + caEscapeHtml(parts[i]) + "</span>";
		}
	}
	/* Same name shape we accept server-side: starts with a letter or underscore,
	   then letters/digits/_/./- — covers everything realistic .plg files use. */
	var pattern = /&([A-Za-z_][\w.-]*);/g;
	var lastIdx = 0;
	var m;
	while ((m = pattern.exec(rawText)) !== null) {
		if (m.index > lastIdx) appendLiteral(rawText.substring(lastIdx, m.index));
		if (Object.prototype.hasOwnProperty.call(entities, m[1])) {
			appendSub(entities[m[1]]);
		} else {
			appendLiteral(m[0]);
		}
		lastIdx = m.index + m[0].length;
	}
	if (lastIdx < rawText.length) appendLiteral(rawText.substring(lastIdx));
	flushRow();
	return html;
}
