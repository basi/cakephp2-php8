<?php
/**
 * Bootstrap for phpunit command
 */

if (!defined('DS')) {
	define('DS', DIRECTORY_SEPARATOR);
}
require_once __DIR__ . DS . 'bootstrap' . DS . 'cake_dot_php.php';

/*
 * loading of lib/Cake/TestSuite/CakeTestSuiteDispatcher.php
 * In bootstrap.php, it is sufficient if the const(s) are defined outside the class of CakeTestSuiteDispatcher.php.
 * However, when loading CakeTestSuiteDispatcher.php in the unit test, a double definition of const(s) error occurs,
 * so load it here.
 */
App::uses('CakeTestSuiteDispatcher', 'TestSuite');
App::load('CakeTestSuiteDispatcher');

/*
 * Classes that can be used without declaring App::uses()
 */
App::uses('ClassRegistry', 'Utility');
App::uses('CakeTestCase', 'TestSuite');
App::uses('CakeTestSuite', 'TestSuite');
App::uses('ControllerTestCase', 'TestSuite');
App::uses('CakeTestModel', 'TestSuite/Fixture');

/*
 * lib/Cake/bootstrap.php and the `Error.level` setting exclude E_DEPRECATED at runtime,
 * which would hide deprecations from the error handler below. Report everything in tests.
 */
error_reporting(E_ALL);

/*
 * PHP's own deprecations (E_DEPRECATED) are collected and summarized on STDERR at shutdown
 * instead of failing the test, until the existing ones are fixed.
 * Everything else, including E_USER_DEPRECATED, is converted to exceptions by PHPUnit.
 */
$phpunitErrorHandler = new \PHPUnit\Util\ErrorHandler(true, true, true, true);
$deprecations = new ArrayObject();
set_error_handler(function ($errorNumber, $errorString, $errorFile, $errorLine) use ($phpunitErrorHandler, $deprecations) {
	if ($errorNumber !== E_DEPRECATED) {
		return $phpunitErrorHandler($errorNumber, $errorString, $errorFile, $errorLine);
	}
	// error_reporting() alone can't be trusted here: code under test may lower it
	// (Configure::bootstrap() applies Error.level). Skip only silenced deprecations:
	// on PHP 8, @ leaves only the fatal error bits in error_reporting().
	if (!(error_reporting() & ~(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR))) {
		return false;
	}
	$key = $errorString . ' in ' . $errorFile . ':' . $errorLine;
	$deprecations[$key] = isset($deprecations[$key]) ? $deprecations[$key] + 1 : 1;
	return true;
});
register_shutdown_function(function () use ($deprecations) {
	if (!count($deprecations)) {
		return;
	}
	$list = $deprecations->getArrayCopy();
	ksort($list);
	$output = sprintf("\n%d PHP deprecations (%d unique):\n", array_sum($list), count($list));
	foreach ($list as $key => $count) {
		$output .= sprintf("%6d x %s\n", $count, $key);
	}
	fwrite(STDERR, $output);
});
unset($phpunitErrorHandler, $deprecations);
