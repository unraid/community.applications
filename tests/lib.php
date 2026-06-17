<?php
/* Minimal test helper library for the community.applications test suite.
   Provides eq() for assertion checking and suite_done() for final reporting. */

$GLOBALS['_test_pass'] = 0;
$GLOBALS['_test_fail'] = 0;

/**
 * Assert that $actual equals $expected (strict equality).
 *
 * @param string $label    Human-readable description of the assertion.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value under test.
 */
function eq(string $label, $expected, $actual): void {
	if ($expected === $actual) {
		echo "  PASS: {$label}\n";
		$GLOBALS['_test_pass']++;
	} else {
		$exp = var_export($expected, true);
		$got = var_export($actual, true);
		echo "  FAIL: {$label}\n";
		echo "        expected: {$exp}\n";
		echo "        actual  : {$got}\n";
		$GLOBALS['_test_fail']++;
	}
}

/**
 * Assert that $actual is true.
 *
 * @param string $label  Human-readable description of the assertion.
 * @param mixed  $actual Value that must be truthy.
 */
function ok(string $label, $actual): void {
	eq($label, true, (bool) $actual);
}

/**
 * Assert that $actual is false.
 *
 * @param string $label  Human-readable description of the assertion.
 * @param mixed  $actual Value that must be falsy.
 */
function not_ok(string $label, $actual): void {
	eq($label, false, (bool) $actual);
}

/**
 * Print final pass/fail summary and exit with a non-zero code on failure.
 */
function suite_done(): void {
	$pass = $GLOBALS['_test_pass'];
	$fail = $GLOBALS['_test_fail'];
	$total = $pass + $fail;
	echo "\n--- {$pass}/{$total} tests passed";
	if ($fail > 0) {
		echo ", {$fail} FAILED";
	}
	echo " ---\n";
	exit($fail > 0 ? 1 : 0);
}