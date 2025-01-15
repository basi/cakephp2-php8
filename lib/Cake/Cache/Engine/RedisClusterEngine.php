<?php
declare(strict_types = 1);

class RedisClusterEngine extends CacheEngine {

/**
 * @var RedisCluster
 */
	protected RedisCluster $redis;

/**
 * @var array
 */
	public $settings = [];

/**
 * Initialize the Cache Engine
 *
 * @param array $settings Configuration settings for the engine
 * @return bool True if the engine has been successfully initialized, false if not
 * @throws UnexpectedValueException When redis extension is not loaded
 */
	public function init($settings = []): bool {
		if (!extension_loaded('redis')) {
			throw new UnexpectedValueException('The `redis` extension must be enabled to use RedisEngine.');
		}
		if (!class_exists('RedisCluster')) {
			return false;
		}
		parent::init(
			array_merge([
				'engine' => 'RedisCluster',
				'prefix' => Inflector::slug(APP_DIR) . '_',
				'server' => ['tcp://127.0.0.1:6379'],
				'database' => 0,
				'port' => 6379,
				'password' => false,
				'timeout' => 0,
				'persistent' => true,
				'unix_socket' => false,
				'duration' => 0,
				'name' => null,
				'failover' => 'none',
				'read_timeout' => 0,
			], $settings)
		);
		return $this->_connect();
	}

/**
 * Connect to Redis Cluster
 *
 * @return bool True if connected successfully
 * @throws RedisClusterException When connection fails
 */
	protected function _connect(): bool {
		try {
			$this->redis = new RedisCluster(
				$this->settings['name'],
				(array)$this->settings['server'],
				(float)$this->settings['timeout'],
				(float)$this->settings['read_timeout'],
				(bool)$this->settings['persistent']
			);

			switch ($this->settings['failover']) {
				case 'error':
					$this->redis->setOption(
						RedisCluster::OPT_SLAVE_FAILOVER,
						RedisCluster::FAILOVER_ERROR
					);
					break;
				case 'distribute':
					$this->redis->setOption(
						RedisCluster::OPT_SLAVE_FAILOVER,
						RedisCluster::FAILOVER_DISTRIBUTE
					);
					break;
				case 'slaves':
					$this->redis->setOption(
						RedisCluster::OPT_SLAVE_FAILOVER,
						RedisCluster::FAILOVER_DISTRIBUTE_SLAVES
					);
					break;
				default:
					$this->redis->setOption(
						RedisCluster::OPT_SLAVE_FAILOVER,
						RedisCluster::FAILOVER_NONE
					);
			}
		} catch (RedisClusterException $e) {
			return false;
		}

		return true;
	}

/**
 * Serialize value for saving to Redis.
 *
 * @param mixed $value Value to serialize
 * @return string Serialized value
 */
	protected function _serializeValue($value): string {
		if (is_int($value)) {
			return (string)$value;
		}

		return serialize($value);
	}

/**
 * Convert the various expressions of a TTL value into duration in seconds
 *
 * @param \DateInterval|int|null $ttl TTL value
 * @return int Duration in seconds
 * @throws InvalidArgumentException When TTL value is invalid
 */
	protected function _getDuration($ttl): int {
		if ($ttl === null) {
			return $this->settings['duration'];
		}
		if (is_int($ttl)) {
			return $ttl;
		}
		if ($ttl instanceof DateInterval) {
			return (int)DateTime::createFromFormat('U', '0')
				->add($ttl)
				->format('U');
		}

		throw new InvalidArgumentException('TTL values must be one of null, int, \DateInterval');
	}

/**
 * Unserialize string value fetched from Redis.
 *
 * @param string $value Value to unserialize
 * @return mixed Unserialized value
 */
	protected function _unserializeValue(string $value) {
		if (preg_match('/^-?\d+$/', $value)) {
			return (int)$value;
		}

		return unserialize($value);
	}

/**
 * Write data for key into cache.
 *
 * @param string $key Identifier for the data
 * @param mixed $value Data to be cached
 * @param \DateInterval|int|null $ttl TTL value
 * @return bool True if the data was successfully cached
 */
	protected function _setValue(string $key, $value, $ttl = null): bool {
		$value = $this->_serializeValue($value);

		$duration = $this->_getDuration($ttl);
		if ($duration === 0) {
			return $this->redis->set($key, $value);
		}
		return $this->redis->setex($key, $duration, $value);
	}

/**
 * Write data for key into cache
 *
 * @param string $key Identifier for the data
 * @param mixed $value Data to be cached
 * @param int $duration How long to cache the data
 * @return bool True if the data was successfully cached
 */
	public function write($key, $value, $duration): bool {
		return $this->_setValue($key, $value, $duration);
	}

/**
 * Read a key from the cache
 *
 * @param string $key Identifier for the data
 * @param mixed $default Default value if key doesn't exist
 * @return mixed The cached data
 */
	protected function _getValue(string $key, $default = null) {
		$value = $this->redis->get($key);
		if ($value === false) {
			return $default;
		}

		return $this->_unserializeValue($value);
	}

/**
 * Read a key from the cache
 *
 * @param string $key Identifier for the data
 * @return mixed The cached data
 */
	public function read($key) {
		return $this->_getValue($key);
	}

/**
 * Delete a key from the cache
 *
 * @param string $key Identifier for the data
 * @return bool True if deleted successfully
 */
	protected function _deleteValue(string $key): bool {
		return $this->redis->del($key) > 0;
	}

/**
 * Delete a key from the cache
 *
 * @param string $key Identifier for the data
 * @return bool True if deleted successfully
 */
	public function delete($key): bool {
		return $this->_deleteValue($key);
	}

/**
 * Delete all keys from the cache
 *
 * @param bool $check Only delete expired cache items
 * @return bool True if cache was cleared
 */
	public function clear($check): bool {
		if ($check) {
			return true;
		}

		$result = true;
		foreach ($this->redis->_masters() as $m) {
			$iterator = null;
			do {
				$keys = $this->redis->scan($iterator, $m, $this->settings['prefix'] . '*');
				if ($keys === false) {
					continue;
				}
				foreach ($keys as $key) {
					if ($this->redis->del($key) < 1) {
						$result = false;
					}
				}
			} while ($iterator > 0);
		}

		return $result;
	}

/**
 * Increment a number under the key
 *
 * @param string $key Identifier for the data
 * @param int $offset How much to increment
 * @return bool Always false
 * @throws NotImplementedException Method not supported
 */
	public function increment($key, $offset = 1): bool {
		throw new NotImplementedException('increment is not supported');
	}

/**
 * Decrement a number under the key
 *
 * @param string $key Identifier for the data
 * @param int $offset How much to decrement
 * @return bool Always false
 * @throws NotImplementedException Method not supported
 */
	public function decrement($key, $offset = 1): bool {
		throw new NotImplementedException('decrement is not supported');
	}

/**
 * Get group value
 *
 * @return bool Always false
 * @throws NotImplementedException Method not supported
 */
	public function groups(): bool {
		throw new NotImplementedException('group method not supported');
	}

/**
 * Clear group
 *
 * @param string $group Group name
 * @return bool Always false
 * @throws NotImplementedException Method not supported
 */
	public function clearGroup($group): bool {
		throw new NotImplementedException('clearGroup method not supported');
	}

/**
 * Add data to the cache
 *
 * @param string $key Identifier for the data
 * @param mixed $value Data to be cached
 * @param int $duration How long to cache the data
 * @return bool Always false
 * @throws NotImplementedException Method not supported
 */
	public function add($key, $value, $duration): bool {
		throw new NotImplementedException('add method not supported');
	}
}
