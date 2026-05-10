<?php
/* Tests for getYoutubeThumbnail() — used in caBuildRepoMediaSection / popup
   video tile rendering. Pure URL→URL transform. */

require_once __DIR__ . "/lib.php";
/* Prefer the repo-relative path so this test runs in CI / dev sandboxes; fall
   back to the appliance install path when running on a live Unraid box. */
$helpersPath = dirname(__DIR__) . "/source/community.applications/usr/local/emhttp/plugins/community.applications/include/helpers.php";
if (!is_file($helpersPath)) {
	$helpersPath = "/usr/local/emhttp/plugins/community.applications/include/helpers.php";
}
require_once $helpersPath;

echo "=== getYoutubeThumbnail ===\n";

// youtu.be short URL → thumbnail
eq("youtu.be id 11 chars",
   "https://img.youtube.com/vi/dQw4w9WgXcQ/default.jpg",
   getYoutubeThumbnail("https://youtu.be/dQw4w9WgXcQ"));

eq("youtu.be id with hyphens / underscores",
   "https://img.youtube.com/vi/abc-DEF_123/default.jpg",
   getYoutubeThumbnail("https://youtu.be/abc-DEF_123"));

// youtube.com/watch?v=
eq("youtube.com/watch with no www",
   "https://img.youtube.com/vi/dQw4w9WgXcQ/default.jpg",
   getYoutubeThumbnail("https://youtube.com/watch?v=dQw4w9WgXcQ"));

eq("www.youtube.com/watch",
   "https://img.youtube.com/vi/dQw4w9WgXcQ/default.jpg",
   getYoutubeThumbnail("https://www.youtube.com/watch?v=dQw4w9WgXcQ"));

eq("youtube.com/watch?v= with extra params (must still match)",
   "https://img.youtube.com/vi/dQw4w9WgXcQ/default.jpg",
   getYoutubeThumbnail("https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s"));

// Non-YouTube URLs returned untouched
eq("vimeo URL passes through",
   "https://vimeo.com/123456",
   getYoutubeThumbnail("https://vimeo.com/123456"));

eq("arbitrary URL passes through",
   "https://example.com/video.mp4",
   getYoutubeThumbnail("https://example.com/video.mp4"));

// http (not https) passes through unmodified — implementation requires https
eq("http youtu.be passes through (impl is https-only)",
   "http://youtu.be/dQw4w9WgXcQ",
   getYoutubeThumbnail("http://youtu.be/dQw4w9WgXcQ"));

// Empty / weird input
eq("empty string passes through",   "",                                   getYoutubeThumbnail(""));
eq("plain text passes through",     "not a url at all",                   getYoutubeThumbnail("not a url at all"));

suite_done();
