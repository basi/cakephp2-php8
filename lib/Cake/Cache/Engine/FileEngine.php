<?php
/**
 * File Storage engine for cache. Filestorage is the slowest cache storage
 * to read and write. However, it is good for servers that don't have other storage
 * engine available, or have content which is not performance sensitive.
 *
 * You can configure a FileEngine cache, using Cache::config()
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
 * @since         CakePHP(tm) v 1.2.0.4933
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

/**
 * File Storage engine for cache. Filestorage is the slowest cache storage
 * to read and write. However, it is good for servers that don't have other storage
 * engine available, or have content which is not performance sensitive.
 *
 * You can configure a FileEngine cache, using Cache::config()
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
 * - prefix = string prefix for filename, default => cake_
 * - lock = enable file locking on write, default => true
 * - serialize = serialize the data, default => true
 *
 * @var array
 * @see CacheEngine::__defaults
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
		$settings += array(
			'engine' => 'File',
			'path' => CACHE,
			'prefix' => 'cake_',
			'lock' => true,
			'serialize' => true,
			'isWindows' => false,
			'mask' => 0664
		);
		parent::init($settings);

		if (DS === '\\') {
			$this->settings['isWindows'] = true;
		}
		if (substr($this->settings['path'], -1) !== DS) {
			$this->settings['path'] .= DS;
		}
		if (!empty($this->_groupPrefix)) {
			$this->_groupPrefix = str_replace('_', DS, $this->_groupPrefix);
		}
		return $this->_active();
	}

/**
 * Garbage collection. Permanently remove all expired and deleted data
 *
 * @param int $expires [optional] An expires timestamp, invalidating all data before.
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
 * @param int $duration How long to cache the data, in seconds
 * @return bool True if the data was successfully cached, false on failure
 */
	public function write($key, $data, $duration) {
		if (!$this->_init) {
			return false;
		}

		if ($this->_setKey($key, true) === false) {
			return false;
		}

		$lineBreak = "\n";

		if ($this->settings['isWindows']) {
			$lineBreak = "\r\n";
		}

		if (!empty($this->settings['serialize'])) {
			if ($this->settings['isWindows']) {
				$data = str_replace('\\', '\\\\\\\\', serialize($data));
			} else {
				$data = serialize($data);
			}
		}

		$expires = time() + $duration;
		$contents = implode(array($expires, $lineBreak, $data, $lineBreak));

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
		if (!$this->_init || $this->_setKey($key) === false) {
			return false;
		}

		if ($this->settings['lock']) {
			$this->_File->flock(LOCK_SH);
		}

		$this->_File->rewind();
		$time = time();
		$cachetime = (int)$this->_File->current();

		if ($cachetime !== false && ($cachetime < $time || ($time + $this->settings['duration']) < $cachetime)) {
			if ($this->settings['lock']) {
				$this->_File->flock(LOCK_UN);
			}
			return false;
		}

		$data = '';
		$this->_File->next();
		while ($this->_File->valid()) {
			$data .= $this->_File->current();
			$this->_File->next();
		}

		if ($this->settings['lock']) {
			$this->_File->flock(LOCK_UN);
		}

		$data = trim($data);

		if ($data !== '' && !empty($this->settings['serialize'])) {
			if ($this->settings['isWindows']) {
				$data = str_replace('\\\\\\\\', '\\', $data);
			}
			$data = unserialize((string)$data);
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
		if ($this->_setKey($key) === false || !$this->_init) {
			return false;
		}
		$path = $this->_File->getRealPath();
		$this->_File = null;

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
		$this->_File = null;

		$threshold = $now = false;
		if ($check) {
			$now = time();
			$threshold = $now - $this->settings['duration'];
		}

		$this->_clearDirectory($this->settings['path'], $now, $threshold);

		$directory = new RecursiveDirectoryIterator($this->settings['path']);
		$contents = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::SELF_FIRST);
		$cleared = array();
		foreach ($contents as $path) {
			if ($path->isFile()) {
				continue;
			}

			$path = $path->getRealPath() . DS;
			if (!in_array($path, $cleared)) {
				$this->_clearDirectory($path, $now, $threshold);
				$cleared[] = $path;
			}
		}
		return true;
	}

/**
 * Used to clear a directory of matching files.
 *
 * @param string $path The path to search.
 * @param int $now The current timestamp
 * @param int $threshold Any file not modified after this value will be deleted.
 * @return void
 */
	protected function _clearDirectory($path, $now, $threshold) {
		$prefixLength = strlen($this->settings['prefix']);

		if (!is_dir($path)) {
			return;
		}

		$dir = dir($path);
		if ($dir === false) {
			return;
		}

		while (($entry = $dir->read()) !== false) {
			if (substr($entry, 0, $prefixLength) !== $this->settings['prefix']) {
				continue;
			}

			try {
				$file = new SplFileObject($path . $entry, 'r');
			} catch (Exception $e) {
				continue;
			}

			if ($threshold) {
				$mtime = $file->getMTime();

				if ($mtime > $threshold) {
					continue;
				}
				$expires = (int)$file->current();

				if ($expires > $now) {
					continue;
				}
			}
			if ($file->isFile()) {
				$filePath = $file->getRealPath();
				$file = null;

				//@codingStandardsIgnoreStart
				@unlink($filePath);
				//@codingStandardsIgnoreEnd
			}
		}
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
 * @param string $key The key to decrement
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
 * @param bool $createKey Whether the key should be created if it doesn't exists, or not
 * @return bool true if the cache key could be set, false otherwise
 */
	protected function _setKey($key, $createKey = false) {
		$groups = null;
		if (!empty($this->_groupPrefix)) {
			$groups = vsprintf($this->_groupPrefix, $this->groups());
		}
		$dir = $this->settings['path'] . $groups;

		if (!is_dir($dir)) {
			mkdir($dir, 0775, true);
		}
		$path = new SplFileInfo($dir . $key);

		if (!$createKey && !$path->isFile()) {
			return false;
		}
		if (
			empty($this->_File) ||
			$this->_File->getBaseName() !== $key ||
			$this->_File->valid() === false
		) {
			$exists = file_exists($path->getPathname());
			try {
				$this->_File = $path->openFile('c+');
			} catch (Exception $e) {
				trigger_error($e->getMessage(), E_USER_WARNING);
				return false;
			}
			unset($path);

			if (!$exists && !chmod($this->_File->getPathname(), (int)$this->settings['mask'])) {
				trigger_error(__d(
					'cake_dev', 'Could not apply permission mask "%s" on cache file "%s"',
					array($this->_File->getPathname(), $this->settings['mask'])), E_USER_WARNING);
			}
		}
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
		if ($this->_init && !($dir->isDir() && $dir->isWritable())) {
			$this->_init = false;
			trigger_error(__d('cake_dev', '%s is not writable', $this->settings['path']), E_USER_WARNING);
			return false;
		}
		return true;
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

		$key = Inflector::underscore(str_replace(array(DS, '/', '.', '<', '>', '?', ':', '|', '*', '"'), '_', strval($key)));
		return $key;
	}

/**
 * Recursively deletes all files under any directory named as $group
 *
 * @param string $group The group to clear.
 * @return bool success
 */
	public function clearGroup($group) {
		$this->_File = null;
		$directoryIterator = new RecursiveDirectoryIterator($this->settings['path']);
		$contents = new RecursiveIteratorIterator($directoryIterator, RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($contents as $object) {
			$containsGroup = strpos($object->getPathName(), DS . $group . DS) !== false;
			$hasPrefix = true;
			if (strlen($this->settings['prefix']) !== 0) {
				$hasPrefix = strpos($object->getBaseName(), $this->settings['prefix']) === 0;
			}
			if ($object->isFile() && $containsGroup && $hasPrefix) {
				$path = $object->getPathName();
				$object = null;
				//@codingStandardsIgnoreStart
				@unlink($path);
				//@codingStandardsIgnoreEnd
			}
		}
		return true;
	}

/**
 * Write data for key into cache if it doesn't exist already.
 * If it already exists, it fails and returns false.
 *
 * @param string $key Identifier for the data.
 * @param mixed $value Data to be cached.
 * @param int $duration How long to cache the data, in seconds.
 * @return bool True if the data was successfully cached, false on failure.
 */
	public function add($key, $value, $duration) {
		$cachedValue = $this->read($key);
		if ($cachedValue === false) {
			return $this->write($key, $value, $duration);
		}
		return false;
	}

/**
 * Obtains multiple cache items by their unique keys.
 *
 * @param array $keys A list of keys that can obtained in a single operation
 * @param mixed $default Default value to return for keys that do not exist
 * @return array A list of key value pairs. Cache keys that do not exist or are stale will have $default as value
 * @link https://github.com/cakephp/cakephp/blob/4.x/src/Cache/Engine/FileEngine.php#L138-L159
 */
	public function getMultiple(array $keys, $default = null) : iterable {
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
 * @param array $values A list of key => value pairs for a multiple-set operation
 * @param int|null $ttl Optional. The TTL value of this item
 * @return bool True on success and false on failure
 * @link https://github.com/cakephp/cakephp/blob/4.x/src/Cache/Engine/FileEngine.php#L161-L177
 */
	public function setMultiple($values, $ttl = null) : bool {
		foreach ($values as $key => $value) {
			if (false === $this->write($key, $value, $ttl)) {
				return false;
			}
		}
		return true;
	}
}
