<?php
/**
 * File Storage Engine for cache
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
 * @since         CakePHP(tm) v 1.2.0.4933
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

App::uses('CacheEngine', 'Cache');

/**
 * File Storage engine for cache
 *
 * @package       Cake.Cache.Engine
 */
class FileEngine extends CacheEngine {

	/**
	 * Instance of SplFileObject class
	 *
	 * @var File
	 */
	protected $_File = null;

	/**
	 * Settings
	 *
	 * - path = absolute path to cache directory, default => CACHE
	 * - prefix = string prefix for cache file names, default => cake_
	 * - lock = enable file locking on write, default => false
	 * - serialize = serialize the data, default => true
	 *
	 * @var array
	 */
	public $settings = array();

	/**
	 * True unless FileEngine::__active(); fails
	 *
	 * @var bool
	 */
	protected $_init = true;

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
		parent::init(array_merge(array(
			'path' => CACHE,
			'prefix' => 'cake_',
			'lock' => false,
			'serialize' => true,
			'isWindows' => false,
			'mask' => 0664
		), $settings));

		if (DS === '\\') {
			$this->settings['isWindows'] = true;
		}
		if (substr($this->settings['path'], -1) !== DS) {
			$this->settings['path'] .= DS;
		}
		if (!empty($this->settings['mask'])) {
			$this->settings['mask'] = octdec($this->settings['mask']);
		}
		return $this->_active();
	}

	/**
	 * Garbage collection. Permanently remove all expired and deleted data
	 *
	 * @param int|null $expires [optional] An expires timestamp, invalidating all data before.
	 * @return bool True if garbage collection was successful, false on failure
	 */
	public function gc($expires = null) {
		return $this->clear(true);
	}

	/**
	 * Write data for key into cache
	 *
	 * @param string $key Identifier for the data
	 * @param mixed $data Data to be cached
	 * @param int|string|null $duration How long to cache the data, in seconds
	 * @return bool True if the data was successfully cached, false on failure
	 */
	public function write($key, $data, $duration = null) {
		if ($data === '' || !$this->_init) {
			return false;
		}

		if ($duration === null) {
			$duration = $this->settings['duration'];
		}
		$expires = time() + $duration;

		$contents = array(
			$expires,
			$data
		);

		if ($this->settings['serialize']) {
			$contents = serialize($contents);
		}

		if ($this->settings['lock']) {
			$this->_File->flock(LOCK_EX);
		}

		$this->_File->rewind();
		$success = $this->_File->ftruncate(0) && $this->_File->fwrite($contents) && $this->_File->fflush();

		if ($this->settings['lock']) {
			$this->_File->flock(LOCK_UN);
		}

		return $success;
	}

	/**
	 * Read a key from the cache
	 *
	 * @param string $key Identifier for the data
	 * @return mixed The cached data, or false if the data doesn't exist, has expired, or if there was an error fetching it
	 */
	public function read($key) {
		if (!$this->_init || !$this->_File->isFile()) {
			return false;
		}

		if ($this->settings['lock']) {
			$this->_File->flock(LOCK_SH);
		}

		$this->_File->rewind();
		$contents = $this->_File->fread($this->_File->getSize());

		if ($this->settings['lock']) {
			$this->_File->flock(LOCK_UN);
		}

		if ($this->settings['serialize']) {
			$contents = @unserialize($contents);
		}

		if (!is_array($contents)) {
			return false;
		}

		list($expires, $data) = $contents;
		if (time() > $expires) {
			return false;
		}
		return $data;
	}

	/**
	 * Delete a key from the cache
	 *
	 * @param string $key Identifier for the data
	 * @return bool True if the value was successfully deleted, false if it didn't exist or couldn't be removed
	 */
	public function delete($key) {
		if (!$this->_init) {
			return false;
		}
		$path = $this->_File->getRealPath();
		if ($path === false) {
			return false;
		}
		//@codingStandardsIgnoreStart
		return @unlink($path);
		//@codingStandardsIgnoreEnd
	}

	/**
	 * Delete all values from the cache
	 *
	 * @param bool $check Optional - only delete expired cache items
	 * @return bool True if the cache was successfully cleared, false otherwise
	 */
	public function clear($check) {
		if (!$this->_init) {
			return false;
		}
		$dir = new DirectoryIterator($this->settings['path']);
		$results = array();

		//@codingStandardsIgnoreStart
		$modifiers = array(
			'Folder' => DIRECTORY_SEPARATOR,
			'File' => ''
		);
		//@codingStandardsIgnoreEnd

		foreach ($dir as $item) {
			if ($item->isDot()) {
				continue;
			}
			$path = $item->getRealPath();
			if ($path === false) {
				continue;
			}
			$isHidden = $item->isHidden();
			$isVcs = $item->isDot() || in_array($item->getFilename(), array('.', '..', '.svn', '.git'));

			if (!$isHidden && !$isVcs) {
				$key = str_replace($this->settings['path'], '', $path);
				$key = str_replace($modifiers['File'], '', $key);
				$key = str_replace($modifiers['Folder'], '', $key);
				$results[] = $key;
			}
		}
		array_walk($results, array($this, 'delete'));

		return true;
	}

	/**
	 * Not implemented
	 *
	 * @param string $key The key to decrement
	 * @param int $offset The number to offset
	 * @return void
	 * @throws CacheException
	 */
	public function decrement($key, $offset = 1) {
		throw new CacheException(__d('cake_dev', 'Files cannot be atomically decremented.'));
	}

	/**
	 * Not implemented
	 *
	 * @param string $key The key to increment
	 * @param int $offset The number to offset
	 * @return void
	 * @throws CacheException
	 */
	public function increment($key, $offset = 1) {
		throw new CacheException(__d('cake_dev', 'Files cannot be atomically incremented.'));
	}

	/**
	 * Sets the current cache key this class is managing, and creates a writable SplFileObject
	 * for the cache file the key is referring to.
	 *
	 * @param string $key The key
	 * @param bool $createKey Whether the key should be created if it doesn't exist, or not
	 * @return bool true if the cache key could be set, false otherwise
	 */
	protected function _setKey($key, $createKey = false) {
		$groups = null;
		if (!empty($this->settings['groups'])) {
			$groups = implode('_', $this->settings['groups']);
		}
		$dir = $this->settings['path'] . $groups;

		if (!is_dir($dir)) {
			mkdir($dir, 0775, true);
		}
		$path = new SplFileInfo($dir . DS . $key);

		if (!$createKey && !$path->isFile()) {
			return false;
		}
		//@codingStandardsIgnoreStart
		if (empty($this->_File) || $this->_File->getBasename() !== $key) {
			$exists = file_exists($path->getPathname());
			try {
				$this->_File = $path->openFile('c+');
			} catch (Exception $e) {
				trigger_error($e->getMessage(), E_USER_WARNING);
				return false;
			}
			if (!$exists && !$createKey) {
				return false;
			}
		}
		//@codingStandardsIgnoreEnd

		return true;
	}

	/**
	 * Determine is cache directory is writable
	 *
	 * @return bool
	 */
	protected function _active() {
		$dir = new SplFileInfo($this->settings['path']);
		if (Configure::read('debug')) {
			$path = $dir->getPathname();
			if (!is_dir($path)) {
				mkdir($path, 0775, true);
			}
		}
		if ($this->settings['lock'] && !$this->_init && !function_exists('sem_get')) {
			$this->_init = false;
			trigger_error(__d('cake_dev', '%s is not working well with the File Cache engine', 'sem_get()'), E_USER_WARNING);
			return false;
		}
		return $this->_init = ($dir->isDir() && $dir->isWritable());
	}

	/**
	 * Obtains multiple cache items by their unique keys.
	 *
	 * @param array $keys A list of keys that can obtained in a single operation.
	 * @param mixed $default Default value to return for keys that do not exist.
	 * @return array A list of key value pairs. Cache keys that do not exist or are stale will have $default as value.
	 */
	public function getMultiple(array $keys, $default = null): iterable {
		$result = array();
		foreach ($keys as $key) {
			$result[$key] = $this->read($key);
			if (false === $result[$key] && null !== $default) {
				$result[$key] = $default;
			}
		}
		return $result;
	}

	/**
	 * Persists a set of key => value pairs in the cache with an optional TTL.
	 *
	 * @param array $values A list of key => value pairs for a multiple-set operation.
	 * @param int|null $ttl Optional. The TTL value of this item. If no value is sent and
	 *   the driver supports TTL then the library may set a default value
	 *   for it or let the driver take care of that.
	 * @return bool True on success and false on failure.
	 */
	public function setMultiple($values, $ttl = null): bool {
		foreach ($values as $key => $value) {
			if (false === $this->write($key, $value, $ttl)) {
				return false;
			}
		}
		return true;
	}
}
