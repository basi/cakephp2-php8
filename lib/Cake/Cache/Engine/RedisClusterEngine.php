<?php
/**
 * Redis Cluster storage engine for cache
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
 * Redis Cluster storage engine for cache
 *
 * @package       Cake.Cache.Engine
 */
class RedisClusterEngine extends CacheEngine {

/**
 * Redis Cluster wrapper.
 *
 * @var RedisCluster
 */
	protected $_RedisCluster = null;

/**
 * Settings
 *
 * - seeds = array of host:port pairs for Redis Cluster nodes
 * - timeout = float timeout in seconds (default: 0)
 * - readTimeout = float timeout for read operations (default: 0)
 * - persistent = bool Connects to the Redis server with a persistent connection (default: true)
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
 * @throws RedisException When the Redis extension is not installed or cannot connect to the server
 */
	public function init($settings = array()) {
		if (!class_exists('RedisCluster')) {
			return false;
		}

		parent::init(array_merge(array(
			'engine' => 'RedisCluster',
			'prefix' => Inflector::slug(APP_DIR) . '_',
			'seeds' => array('127.0.0.1:7000'),
			'timeout' => 0,
			'readTimeout' => 0,
			'persistent' => true,
			'password' => false,
			'database' => 0,
			'unix_socket' => false
		), $settings));

		return $this->_connect();
	}

/**
 * Connects to a Redis Cluster
 *
 * @return bool True if Redis Cluster was connected
 * @throws RedisException When cannot connect to the server
 */
	protected function _connect() {
		try {
			$persistentId = null;
			if ($this->settings['persistent']) {
				$persistentId = implode('_', array(
					implode('_', $this->settings['seeds']),
					$this->settings['timeout'],
					$this->settings['readTimeout']
				));
			}

			$this->_RedisCluster = new RedisCluster(
				$persistentId,
				$this->settings['seeds'],
				$this->settings['timeout'],
				$this->settings['readTimeout'],
				$this->settings['persistent']
			);

			if ($this->settings['password']) {
				$this->_RedisCluster->auth($this->settings['password']);
			}

			if ($this->settings['database'] !== 0) {
				$this->_RedisCluster->select($this->settings['database']);
			}

			return true;
		} catch (RedisException $e) {
			return false;
		}
	}

/**
 * Serializes value for storage
 *
 * @param mixed $value Value to serialize
 * @return string Serialized value
 */
	protected function _serializeValue($value) {
		if (is_int($value)) {
			return (string)$value;
		}
		return serialize($value);
	}

/**
 * Calculates the duration value in seconds
 *
 * @param int|string $duration Duration value
 * @return int Duration in seconds
 */
	protected function _getDuration($duration) {
		if (!is_numeric($duration)) {
			$duration = strtotime($duration) - time();
		}
		return $duration;
	}

/**
 * Unserializes stored value
 *
 * @param string $value Serialized value
 * @return mixed Unserialized value
 */
	protected function _unserializeValue($value) {
		if (is_numeric($value)) {
			return (int)$value;
		}
		return unserialize($value);
	}

/**
 * Sets a value in the cache
 *
 * @param string $key Identifier for the data
 * @param mixed $value Data to be cached
 * @param int $duration How long to cache the data, in seconds
 * @return bool True if the data was successfully cached, false on failure
 */
	protected function _setValue($key, $value, $duration) {
		$value = $this->_serializeValue($value);
		$duration = $this->_getDuration($duration);

		if ($duration < 1) {
			return false;
		}

		return $this->_RedisCluster->setex($key, $duration, $value);
	}

/**
 * Gets a value from the cache
 *
 * @param string $key Identifier for the data
 * @return mixed The cached data, or false if the data doesn't exist, has expired, or if there was an error fetching it
 */
	protected function _getValue($key) {
		$value = $this->_RedisCluster->get($key);

		if ($value === false) {
			return false;
		}

		return $this->_unserializeValue($value);
	}

/**
 * Deletes a value from the cache
 *
 * @param string $key Identifier for the data
 * @return bool True if the value was successfully deleted, false if it didn't exist or couldn't be removed
 */
	protected function _deleteValue($key) {
		return $this->_RedisCluster->del($key) > 0;
	}

/**
 * Write data for key into cache
 *
 * @param string $key Identifier for the data
 * @param mixed $value Data to be cached
 * @param int|string|null $duration How long to cache the data, in seconds
 * @return bool True if the data was successfully cached, false on failure
 * @throws RedisException When the Redis extension is not installed or cannot connect to the server
 */
	public function write($key, $value, $duration = null) {
		if (!$this->_RedisCluster) {
			return false;
		}

		if ($duration === null) {
			$duration = $this->settings['duration'];
		}

		$key = $this->_key($key);
		return $this->_setValue($key, $value, $duration);
	}

/**
 * Read a key from the cache
 *
 * @param string $key Identifier for the data
 * @return mixed The cached data, or false if the data doesn't exist, has expired, or if there was an error fetching it
 * @throws RedisException When the Redis extension is not installed or cannot connect to the server
 */
	public function read($key) {
		if (!$this->_RedisCluster) {
			return false;
		}

		$key = $this->_key($key);
		return $this->_getValue($key);
	}

/**
 * Increments the value of an integer cached key
 *
 * @param string $key Identifier for the data
 * @param int $offset How much to increment
 * @return mixed New incremented value, false otherwise
 * @throws RedisException When the Redis extension is not installed or cannot connect to the server
 */
	public function increment($key, $offset = 1) {
		if (!$this->_RedisCluster) {
			return false;
		}

		$key = $this->_key($key);
		return (int)$this->_RedisCluster->incrBy($key, $offset);
	}

/**
 * Decrements the value of an integer cached key
 *
 * @param string $key Identifier for the data
 * @param int $offset How much to subtract
 * @return mixed New decremented value, false otherwise
 * @throws RedisException When the Redis extension is not installed or cannot connect to the server
 */
	public function decrement($key, $offset = 1) {
		if (!$this->_RedisCluster) {
			return false;
		}

		$key = $this->_key($key);
		return (int)$this->_RedisCluster->decrBy($key, $offset);
	}

/**
 * Delete a key from the cache
 *
 * @param string $key Identifier for the data
 * @return bool True if the value was successfully deleted, false if it didn't exist or couldn't be removed
 * @throws RedisException When the Redis extension is not installed or cannot connect to the server
 */
	public function delete($key) {
		if (!$this->_RedisCluster) {
			return false;
		}

		$key = $this->_key($key);
		return $this->_deleteValue($key);
	}

/**
 * Delete all keys from the cache
 *
 * @param bool $check Optional - only delete expired cache items
 * @return bool True if the cache was successfully cleared, false otherwise
 * @throws RedisException When the Redis extension is not installed or cannot connect to the server
 */
	public function clear($check) {
		if (!$this->_RedisCluster) {
			return false;
		}

		if ($check) {
			return true;
		}

		$keys = $this->_RedisCluster->keys($this->settings['prefix'] . '*');
		if (!empty($keys)) {
			$this->_RedisCluster->del($keys);
		}

		return true;
	}

/**
 * Returns the `group value` for each of the configured groups
 *
 * @param string $key The key to retrieve
 * @return string
 */
	public function groups($key) {
		$value = $this->_RedisCluster->get($key);
		if (!$value) {
			$value = 1;
			$this->_RedisCluster->set($key, $value);
		}
		return $value;
	}

/**
 * Increments the group value to simulate deletion of all keys under a group
 * old values will remain in storage until they expire.
 *
 * @param string $group name of the group to be cleared
 * @return bool success
 */
	public function clearGroup($group) {
		return (bool)$this->_RedisCluster->incr($this->settings['prefix'] . $group);
	}

/**
 * Disconnects from the redis server
 *
 * @return void
 */
	public function __destruct() {
		if (!$this->settings['persistent']) {
			$this->_RedisCluster->close();
		}
	}
}
