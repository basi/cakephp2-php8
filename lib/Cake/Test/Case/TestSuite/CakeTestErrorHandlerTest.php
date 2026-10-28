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
 * @package       Cake.Test.Case.TestSuite
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

App::uses('CakeTestErrorHandler', 'TestSuite');

/**
 * CakeTestErrorHandlerTest
 *
 * @package       Cake.Test.Case.TestSuite
 */
class CakeTestErrorHandlerTest extends CakeTestCase {

/**
 * setUp method
 *
 * @return void
 */
	public function setUp() : void {
		parent::setUp();
		Configure::write('Cache.disable', true);
		$this->handler = new CakeTestErrorHandler(new \PHPUnit\Util\ErrorHandler(true, true, true, true));
	}

/**
 * Deprecations are still collected after Configure::bootstrap() has lowered error_reporting()
 * to Error.level, and the previous error handler has been restored (as ConfigureTest does).
 *
 * @return void
 */
	public function testCollectsDeprecationsAfterConfigureBootstrap() {
		$level = error_reporting();
		set_error_handler($this->handler);
		try {
			Configure::bootstrap(true);
			restore_error_handler();
			$excluded = !(error_reporting() & E_DEPRECATED);
			$this->_triggerDeprecation();
		} finally {
			restore_error_handler();
			error_reporting($level);
		}

		$this->assertTrue($excluded, 'Error.level should exclude E_DEPRECATED for this test to be meaningful.');
		$this->assertCount(1, $this->handler->deprecations());
		$this->assertStringContainsString('ReflectionParameter::getClass()', key($this->handler->deprecations()));
	}

/**
 * Deprecations silenced with @ are not collected.
 *
 * @return void
 */
	public function testIgnoresSilencedDeprecations() {
		set_error_handler($this->handler);
		try {
			@$this->_triggerDeprecation();
		} finally {
			restore_error_handler();
		}

		$this->assertSame(array(), $this->handler->deprecations());
	}

/**
 * E_USER_DEPRECATED is passed to PHPUnit's error handler, which converts it to an exception.
 *
 * @return void
 */
	public function testUserDeprecationRaisesException() {
		$exception = null;
		set_error_handler($this->handler);
		try {
			trigger_error('User deprecation', E_USER_DEPRECATED);
		} catch (\PHPUnit\Framework\Error\Deprecated $e) {
			$exception = $e;
		} finally {
			restore_error_handler();
		}

		$this->assertNotNull($exception);
		$this->assertSame('User deprecation', $exception->getMessage());
		$this->assertSame(array(), $this->handler->deprecations());
	}

/**
 * Raises an E_DEPRECATED (deprecated since PHP 8.0).
 *
 * @return void
 */
	protected function _triggerDeprecation() {
		$parameter = new ReflectionParameter(function (stdClass $value) {
		}, 0);
		$parameter->getClass();
	}

}
