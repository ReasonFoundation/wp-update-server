<?php
//Shared helpers for the plain-PHP test scripts in this directory.

$failures = 0;

function check($name, $condition) {
	global $failures;
	echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
	if ( !$condition ) {
		$failures++;
	}
}

function finish_tests() {
	global $failures;
	echo "\n" . ($failures === 0 ? 'ALL PASSED' : "$failures FAILED") . "\n";
	exit($failures === 0 ? 0 : 1);
}
