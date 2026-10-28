<?php
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @package       Cake.TestSuite
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

/**
 * Error handler for the core test suite.
 *
 * PHP's own deprecations (E_DEPRECATED) are collected and can be summarized at shutdown
 * instead of failing the test, until the existing ones are fixed.
 * Everything else, including E_USER_DEPRECATED, is passed to the given handler
 * (PHPUnit's error handler, which converts them to exceptions).
 *
 * @package       Cake.TestSuite
 */
class CakeTestErrorHandler {

/**
 * The bits left in error_reporting() while a statement is silenced with @ (PHP 8).
 *
 * @var int
 */
	const SILENCED_LEVEL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

/**
 * Handler for everything except E_DEPRECATED.
 *
 * @var callable
 */
	protected $_handler;

/**
 * Collected deprecations, counted by message and location.
 *
 * @var array
 */
	protected $_deprecations = array();

/**
 * Constructor
 *
 * @param callable $handler Handler for everything except E_DEPRECATED.
 */
	public function __construct(callable $handler) {
		$this->_handler = $handler;
	}

/**
 * Handles an error. Use with set_error_handler().
 *
 * @param int $errorNumber Error level.
 * @param string $errorString Error message.
 * @param string $errorFile File the error was raised in.
 * @param int $errorLine Line the error was raised at.
 * @return bool
 */
	public function __invoke($errorNumber, $errorString, $errorFile, $errorLine) {
		if ($errorNumber !== E_DEPRECATED) {
			return call_user_func($this->_handler, $errorNumber, $errorString, $errorFile, $errorLine);
		}
		// error_reporting() alone can't be trusted here: code under test may lower it
		// (Configure::bootstrap() applies Error.level). Skip only silenced deprecations:
		// on PHP 8, @ leaves only the fatal error bits in error_reporting().
		if (!(error_reporting() & ~static::SILENCED_LEVEL)) {
			return false;
		}
		$key = $errorString . ' in ' . $errorFile . ':' . $errorLine;
		$this->_deprecations[$key] = isset($this->_deprecations[$key]) ? $this->_deprecations[$key] + 1 : 1;
		return true;
	}

/**
 * Returns the collected deprecations.
 *
 * @return array Counts keyed by message and location.
 */
	public function deprecations() {
		return $this->_deprecations;
	}

/**
 * Prints a summary of the collected deprecations on STDERR. Use with register_shutdown_function().
 *
 * @return void
 */
	public function printSummary() {
		if (!$this->_deprecations) {
			return;
		}
		$list = $this->_deprecations;
		ksort($list);
		$output = sprintf("\n%d PHP deprecations (%d unique):\n", array_sum($list), count($list));
		foreach ($list as $key => $count) {
			$output .= sprintf("%6d x %s\n", $count, $key);
		}
		fwrite(STDERR, $output);
	}

}
