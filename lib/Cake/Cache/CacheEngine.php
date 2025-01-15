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
 * @package       Cake.Cache
 * @since         CakePHP(tm) v 1.2.0.4933
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

/**
 * Storage engine for CakePHP caching
 *
 * @package       Cake.Cache
 */
abstract class CacheEngine {

	/**
	 * Settings of current engine instance
	 *
	 * @var array
	 */
	public $settings = array();

	/**
	 * Initialize the cache engine
	 *
	 * Called automatically by the cache frontend
	 *
	 * @param array $settings Associative array of parameters for the engine
	 * @return bool True if the engine has been successfully initialized, false if not
	 */
	public function init($settings = array()) {
		$this->settings = array_merge(array(
			'prefix' => 'cake_',
			'duration' => 3600,
			'probability' => 100,
			'groups' => array()
		), $settings);
		if (!empty($this->settings['groups']) && !is_array($this->settings['groups'])) {
			$this->settings['groups'] = array($this->settings['groups']);
		}
		if (!is_numeric($this->settings['duration'])) {
			$this->settings['duration'] = strtotime($this->settings['duration']) - time();
		}
		return true;
	}

	/**
	 * Garbage collection
	 *
	 * Permanently remove all expired and deleted data
	 *
	 * @param int|null $expires [optional] An expires timestamp, invalidating all data before.
	 * @return void
	 */
	public function gc($expires = null) {
	}

	/**
	 * Write value for a key into cache
	 *
	 * @param string $key Identifier for the data
	 * @param mixed $value Data to be cached
	 * @param int|string|null $duration Optional - string configuration for the duration
	 * @return bool True if the data was successfully cached, false on failure
	 */
	abstract public function write($key, $value, $duration = null);

	/**
	 * Read a key from the cache
	 *
	 * @param string $key Identifier for the data
	 * @return mixed The cached data, or false if the data doesn't exist, has expired, or if there was an error fetching it
	 */
	abstract public function read($key);

	/**
	 * Increment a number under the key and return incremented value
	 *
	 * @param string $key Identifier for the data
	 * @param int $offset How much to add
	 * @return mixed New incremented value, false otherwise
	 */
	abstract public function increment($key, $offset = 1);

	/**
	 * Decrement a number under the key and return decremented value
	 *
	 * @param string $key Identifier for the data
	 * @param int $offset How much to subtract
	 * @return mixed New incremented value, false otherwise
	 */
	abstract public function decrement($key, $offset = 1);

	/**
	 * Delete a key from the cache
	 *
	 * @param string $key Identifier for the data
	 * @return bool True if the value was successfully deleted, false if it didn't exist or couldn't be removed
	 */
	abstract public function delete($key);

	/**
	 * Delete all keys from the cache
	 *
	 * @param bool $check Optional - only delete expired cache items
	 * @return bool True if the cache was successfully cleared, false otherwise
	 */
	abstract public function clear($check = false);

	/**
	 * Add a key to the cache if it does not already exist.
	 *
	 * Defaults to a non-atomic implementation. Subclasses should
	 * prefer atomic implementations.
	 *
	 * @param string $key Identifier for the data.
	 * @param mixed $value Data to be cached.
	 * @param int|string|null $duration Optional - string configuration for the duration
	 * @return bool True if the data was successfully cached, false on failure.
	 */
	public function add($key, $value, $duration = null) {
		if ($this->read($key) === false) {
			return $this->write($key, $value, $duration);
		}
		return false;
	}

	/**
	 * Generates a safe key for use with cache engine storage engines.
	 *
	 * @param string $key the key passed over
	 * @return mixed string $key or false
	 */
	public function key($key) {
		if (empty($key)) {
			return false;
		}
		$prefix = '';
		if (!empty($this->settings['prefix'])) {
			$prefix = $this->settings['prefix'];
		}
		$key = preg_replace('/[\s]+/', '_', strtolower(trim(str_replace(array(DS, '/', '.'), '_', strval($key)))));
		return $prefix . $key;
	}

	/**
	 * Obtains multiple cache items by their unique keys.
	 *
	 * @param array $keys A list of keys that can obtained in a single operation.
	 * @param mixed $default Default value to return for keys that do not exist.
	 * @return iterable A list of key value pairs. Cache keys that do not exist or are stale will have $default as value.
	 * @throws NotImplementedException When the method is not implemented in the engine.
	 */
	public function getMultiple(array $keys, $default = null): iterable {
		throw new NotImplementedException(__CLASS__);
	}

	/**
	 * Persists a set of key => value pairs in the cache with an optional TTL.
	 *
	 * @param array $values A list of key => value pairs for a multiple-set operation.
	 * @param int|null $ttl Optional. The TTL value of this item. If no value is sent and
	 *   the driver supports TTL then the library may set a default value
	 *   for it or let the driver take care of that.
	 * @return bool True on success and false on failure.
	 * @throws NotImplementedException When the method is not implemented in the engine.
	 */
	public function setMultiple($values, $ttl = null): bool {
		throw new NotImplementedException(__CLASS__);
	}
}
