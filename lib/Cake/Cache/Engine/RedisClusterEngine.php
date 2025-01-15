<?php
declare(strict_types=1);

class RedisClusterEngine extends CacheEngine {

/**
 * @var RedisCluster
 */
	protected RedisCluster $_Redis;

/**
 * @var array
 */
	public $settings = [];

/**
 * @param $settings
 *
 * @return bool
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
				'engine'       => 'RedisCluster',
				'prefix'       => Inflector::slug(APP_DIR) . '_',
				'server'       => ['tcp://127.0.0.1:6379'],
				'database'     => 0,
				'port'         => 6379,
				'password'     => false,
				'timeout'      => 0,
				'persistent'   => true,
				'unix_socket'  => false,
				'duration'     => 0,
				// add necessary parameters to RedisCluster __construct.
				'name'         => null,
				'failover'     => 'none',
				'read_timeout' => 0,
			], $settings)
		);
		return $this->_connect();
	}

/**
 * @return bool
 */
	protected function _connect(): bool {
		try {
			$this->_Redis = new RedisCluster(
				$this->settings['name'],
				(array)$this->settings['server'], // seeds
				(float)$this->settings['timeout'],
				(float)$this->settings['read_timeout'],
				(bool)$this->settings['persistent']
			);

			// https://github.com/phpredis/phpredis/blob/develop/cluster.md
			switch ($this->settings['failover']) {
				case 'error':
					$this->_Redis->setOption(
						RedisCluster::OPT_SLAVE_FAILOVER,
						RedisCluster::FAILOVER_ERROR
					);
					break;
				case 'distribute':
					$this->_Redis->setOption(
						RedisCluster::OPT_SLAVE_FAILOVER,
						RedisCluster::FAILOVER_DISTRIBUTE
					);
					break;
				case 'slaves':
					$this->_Redis->setOption(
						RedisCluster::OPT_SLAVE_FAILOVER,
						RedisCluster::FAILOVER_DISTRIBUTE_SLAVES
					);
					break;
				default:
					$this->_Redis->setOption(
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
 * This is needed instead of using Redis' in built serialization feature
 * as it creates problems incrementing/decrementing initially set integer value.
 *
 * @param mixed $value Value to serialize.
 *
 * @return string
 * @link https://github.com/phpredis/phpredis/issues/81
 * @link https://github.com/cakephp/cakephp/blob/4.x/src/Cache/Engine/RedisEngine.php#L363-L380
 */
	private function _serialize($value): string {
		if (is_int($value)) {
			return (string)$value;
		}

		return serialize($value);
	}

/**
 * Convert the various expressions of a TTL value into duration in seconds
 *
 * @param \DateInterval|int|null $ttl The TTL value of this item. If null is sent, the
 *   driver's default duration will be used.
 *
 * @return int
 * @link https://github.com/cakephp/cakephp/blob/4.x/src/Cache/CacheEngine.php#L371-L393
 */
	private function _duration($ttl): int {
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
 * @param string $value Value to unserialize.
 *
 * @return mixed
 * @link https://github.com/cakephp/cakephp/blob/4.x/src/Cache/Engine/RedisEngine.php#L382-L395
 */
	private function _unserialize(string $value) {
		// [-] -> -
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
 * @param \DateInterval|int|null $ttl Optional. The TTL value of this item. If no value is sent and
 *   the driver supports TTL then the library may set a default value
 *   for it or let the driver take care of that.
 *
 * @return bool True if the data was successfully cached, false on failure
 * @link https://github.com/cakephp/cakephp/blob/4.x/src/Cache/Engine/RedisEngine.php#L138-L159
 */
	private function _set(string $key, $value, $ttl = null): bool {
		// Since the current behavior has not been confirmed, $this->_key has not been ported.
		$value = $this->_serialize($value);

		$duration = $this->_duration($ttl);
		if ($duration === 0) {
			return $this->_Redis->set($key, $value);
		}
		// setEx -> setex
		return $this->_Redis->setex($key, $duration, $value);
	}

/**
 * @param $key
 * @param $value
 * @param $duration
 *
 * @return bool
 */
	public function write($key, $value, $duration): bool {
		return $this->_set($key, $value, $duration);
	}

/**
 * Read a key from the cache
 *
 * @param string $key Identifier for the data
 * @param mixed $default Default value to return if the key does not exist.
 *
 * @return mixed The cached data, or the default if the data doesn't exist, has
 *   expired, or if there was an error fetching it
 * @link https://github.com/cakephp/cakephp/blob/4.x/src/Cache/Engine/RedisEngine.php#L161-L177
 */
	private function _get(string $key, $default = null) {
		// Since the current behavior has not been confirmed, $this->_key has not been ported.
		$value = $this->_Redis->get($key);
		if ($value === false) {
			return $default;
		}

		return $this->_unserialize($value);
	}

/**
 * @param $key
 *
 * @return mixed
 */
	public function read($key) {
		return $this->_get($key);
	}

/**
 * Delete a key from the cache
 *
 * @param string $key Identifier for the data
 *
 * @return bool True if the value was successfully deleted, false if it didn't exist or couldn't be removed
 * @link https://github.com/cakephp/cakephp/blob/4.x/src/Cache/Engine/RedisEngine.php#L219-L230
 */
	private function _delete(string $key): bool {
		// Since the current behavior has not been confirmed, $this->_key has not been ported.
		return $this->_Redis->del($key) > 0;
	}

/**
 * @param $key
 *
 * @return bool
 */
	public function delete($key): bool {
		return $this->_delete($key);
	}

/**
 * @param $check
 *
 * @return bool
 * @link https://github.com/riesenia/cakephp-rediscluster/blob/master/src/Cache/Engine/RedisClusterEngine.php
 */
	public function clear($check): bool {
		if ($check) {
			return true;
		}

		$result = true;
		foreach ($this->_Redis->_masters() as $m) {
			$iterator = null;
			do {
				$keys = $this->_Redis->scan($iterator, $m, $this->settings['prefix'] . '*');
				if ($keys === false) {
					continue;
				}
				foreach ($keys as $key) {
					if ($this->_Redis->del($key) < 1) {
						$result = false;
					}
				}
			} while ($iterator > 0);
		}

		return $result;
	}

/**
 * @param $key
 * @param int $offset
 *
 * @return bool
 */
	public function increment($key, $offset = 1): bool {
		throw new NotImplementedException('increment is not supported');
	}

/**
 * @param $key
 * @param int $offset
 *
 * @return bool
 */
	public function decrement($key, $offset = 1): bool {
		throw new NotImplementedException('decrement is not supported');
	}

/**
 * @return bool
 */
	public function groups(): bool {
		throw new NotImplementedException('group method not supported');
	}

/**
 * @param $group
 *
 * @return bool
 */
	public function clearGroup($group): bool {
		throw new NotImplementedException('clearGroup method not supported');
	}

/**
 * @param $key
 * @param $value
 * @param $duration
 *
 * @return bool
 */
	public function add($key, $value, $duration): bool {
		throw new NotImplementedException('add method not supported');
	}
}
