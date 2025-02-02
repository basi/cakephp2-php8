<?php
/**
 * Redis storage engine for cache
 *
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @package       Cake.Cache.Engine
 * @since         CakePHP(tm) v 2.2
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

/**
 * Redis storage engine for cache.
 *
 * @package       Cake.Cache.Engine
 */
class RedisEngine extends CacheEngine {

/**
 * Redis wrapper.
 *
 * @var Redis
 */
	protected $_Redis = null;

/**
 * Settings
 *
 *  - server = string URL or ip to the Redis server host
 *  - database = integer database number to use for connection
 *  - port = integer port number to the Redis server (default: 6379)
 *  - timeout = float timeout in seconds (default: 0)
 *  - persistent = boolean Connects to the Redis server with a persistent connection (default: true)
 *  - unix_socket = path to the unix socket file (default: false)
 *
 * @var array
 */
	public $settings = array();

/**
 * Initialize the Cache Engine
 *
 * Called automatically by the cache frontend
 * To reinitialize the settings call Cache::engine('EngineName', [optional] settings = array());
 *
 * @param array $settings array of setting for the engine
 * @return bool True if the engine has been successfully initialized, false if not
 */
	public function init($settings = array()) {
		if (!class_exists('Redis')) {
			return false;
		}
		parent::init(array_merge(array(
			'engine' => 'Redis',
			'prefix' => Inflector::slug(APP_DIR) . '_',
			'server' => '127.0.0.1',
			'database' => 0,
			'port' => 6379,
			'password' => false,
			'timeout' => 0,
			'persistent' => true,
			'unix_socket' => false
			), $settings)
		);

		return $this->_connect();
	}

/**
 * Connects to a Redis server
 *
 * @return bool True if Redis server was connected
 */
	protected function _connect() {
		try {
			$this->_Redis = new Redis();
			if (!empty($this->settings['unix_socket'])) {
				$return = $this->_Redis->connect($this->settings['unix_socket']);
			} elseif (empty($this->settings['persistent'])) {
				$return = $this->_Redis->connect($this->settings['server'], $this->settings['port'], $this->settings['timeout']);
			} else {
				$persistentId = $this->settings['port'] . $this->settings['timeout'] . $this->settings['database'];
				$return = $this->_Redis->pconnect($this->settings['server'], $this->settings['port'], $this->settings['timeout'], $persistentId);
			}
		} catch (RedisException $e) {
			$return = false;
		}
		if (!$return) {
			return false;
		}
		if ($this->settings['password'] && !$this->_Redis->auth($this->settings['password'])) {
			return false;
		}
		return $this->_Redis->select($this->settings['database']);
	}

/**
 * Write data for key into cache.
 *
 * @param string $key Identifier for the data
 * @param mixed $value Data to be cached
 * @param int $duration How long to cache the data, in seconds
 * @return bool True if the data was successfully cached, false on failure
 */
	public function write($key, $value, $duration) {
		if (!is_int($value)) {
			$value = serialize($value);
		}

		if (!$this->_Redis->isConnected()) {
			$this->_connect();
		}

		if ($duration === 0) {
			return $this->_Redis->set($key, $value);
		}

		return $this->_Redis->setex($key, $duration, $value);
	}

/**
 * Read a key from the cache
 *
 * @param string $key Identifier for the data
 * @return mixed The cached data, or false if the data doesn't exist, has expired, or if there was an error fetching it
 */
	public function read($key) {
		$value = $this->_Redis->get($key);
		if (preg_match('/^[-]?\d+$/', $value)) {
			return (int)$value;
		}
		if ($value !== false && is_string($value)) {
			return unserialize($value);
		}
		return $value;
	}

/**
 * Increments the value of an integer cached key
 *
 * @param string $key Identifier for the data
 * @param int $offset How much to increment
 * @return New incremented value, false otherwise
 * @throws CacheException when you try to increment with compress = true
 */
	public function increment($key, $offset = 1) {
		return (int)$this->_Redis->incrBy($key, $offset);
	}

/**
 * Decrements the value of an integer cached key
 *
 * @param string $key Identifier for the data
 * @param int $offset How much to subtract
 * @return New decremented value, false otherwise
 * @throws CacheException when you try to decrement with compress = true
 */
	public function decrement($key, $offset = 1) {
		return (int)$this->_Redis->decrBy($key, $offset);
	}

/**
 * Delete a key from the cache
 *
 * @param string $key Identifier for the data
 * @return bool True if the value was successfully deleted, false if it didn't exist or couldn't be removed
 */
	public function delete($key) {
		return $this->_Redis->delete($key) > 0;
	}

/**
 * Delete all keys from the cache
 *
 * @param bool $check Whether or not expiration keys should be checked. If
 *   true, no keys will be removed as cache will rely on redis TTL's.
 * @return bool True if the cache was successfully cleared, false otherwise
 */
	public function clear($check) {
		if ($check) {
			return true;
		}
		$keys = $this->_Redis->keys($this->settings['prefix'] . '*');
		$this->_Redis->del($keys);

		return true;
	}

/**
 * Returns the `group value` for each of the configured groups
 * If the group initial value was not found, then it initializes
 * the group accordingly.
 *
 * @return array
 */
	public function groups() {
		$result = array();
		foreach ($this->settings['groups'] as $group) {
			$value = $this->_Redis->get($this->settings['prefix'] . $group);
			if (!$value) {
				$value = 1;
				$this->_Redis->set($this->settings['prefix'] . $group, $value);
			}
			$result[] = $group . $value;
		}
		return $result;
	}

/**
 * Increments the group value to simulate deletion of all keys under a group
 * old values will remain in storage until they expire.
 *
 * @param string $group The group name to clear.
 * @return bool success
 */
	public function clearGroup($group) {
		return (bool)$this->_Redis->incr($this->settings['prefix'] . $group);
	}

/**
 * Disconnects from the redis server
 */
	public function __destruct() {
		if (empty($this->settings['persistent']) && $this->_Redis !== null) {
			$this->_Redis->close();
		}
	}

/**
 * Write data for key into cache if it doesn't exist already.
 * If it already exists, it fails and returns false.
 *
 * @param string $key Identifier for the data.
 * @param mixed $value Data to be cached.
 * @param int $duration How long to cache the data, in seconds.
 * @return bool True if the data was successfully cached, false on failure.
 * @link https://github.com/phpredis/phpredis#setnx
 */
	public function add($key, $value, $duration) {
		if (!is_int($value)) {
			$value = serialize($value);
		}

		$result = $this->_Redis->setnx($key, $value);
		// setnx() doesn't have an expiry option, so overwrite the key with one
		if ($result) {
			return $this->_Redis->setex($key, $duration, $value);
		}
		return false;
	}

/**
 * Obtains multiple cache items by their unique keys.
 *
 * @param array $keys A list of keys that can obtained in a single operation
 * @param mixed $default Default value to return for keys that do not exist
 * @return array A list of key value pairs. Cache keys that do not exist or are stale will have $default as value
 * @link https://github.com/cakephp/cakephp/blob/4.x/src/Cache/Engine/RedisEngine.php#L138-L159
 */
	public function getMultiple(array $keys, $default = null) : iterable {
		$result = $this->_Redis->mGet($keys);
		if (null !== $default && empty($result)) {
			return $default;
		}
		return $result;
	}

/**
 * Persists a set of key => value pairs in the cache with an optional TTL.
 *
 * @param array $values A list of key => value pairs for a multiple-set operation
 * @param int|null $ttl Optional. The TTL value of this item
 * @return bool True on success and false on failure
 * @link https://github.com/cakephp/cakephp/blob/4.x/src/Cache/Engine/RedisEngine.php#L161-L177
 */
	public function setMultiple($values, $ttl = null) : bool {
		$set = $this->_Redis->mset($values);
		if (false === $set) {
			return false;
		}
		foreach ($values as $k) {
			$this->_Redis->expire($k, $ttl);
		}
		return $set;
	}
}
