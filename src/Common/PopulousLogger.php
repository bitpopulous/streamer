<?php

namespace PopulousWSS\Common;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class PopulousLogger extends Logger
{
	private $isProduction = false;
	function __construct($name, array $handlers = array(), array $processors = array())
	{
		parent::__construct($name, $handlers, $processors);
		// $this->log = new Logger('ServerSocket');
		// $this->log->pushHandler(new StreamHandler(APPPATH . 'socket_log/socket.log'));
	}

	function setProduction()
	{
		$this->isProduction = true;
	}


	/**
	 * Adds a log record at the DEBUG level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $message The log message
	 * @param  array  $context The log context
	 * @return bool   Whether the record has been processed
	 */
	public function debug($message, array $context = array())
	{
		if (!$this->isProduction) {
			return $this->addRecord(static::DEBUG, $message, $context);
		}
	}

	/**
	 * Adds a log record at the INFO level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $message The log message
	 * @param  array  $context The log context
	 * @return bool   Whether the record has been processed
	 */
	public function info($message, array $context = array())
	{
		if (!$this->isProduction) return $this->addRecord(static::INFO, $message, $context);
	}


	/**
	 * Adds a log record at the NOTICE level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $message The log message
	 * @param  array  $context The log context
	 * @return bool   Whether the record has been processed
	 */
	public function notice($message, array $context = array())
	{
		if (!$this->isProduction)  return $this->addRecord(static::NOTICE, $message, $context);
	}

	/**
	 * Adds a log record at the WARNING level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $message The log message
	 * @param  array  $context The log context
	 * @return bool   Whether the record has been processed
	 */
	public function warn($message, array $context = array())
	{
		if (!$this->isProduction) return $this->addRecord(static::WARNING, $message, $context);
	}

	/**
	 * Adds a log record at the WARNING level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $message The log message
	 * @param  array  $context The log context
	 * @return bool   Whether the record has been processed
	 */
	public function warning($message, array $context = array())
	{
		if (!$this->isProduction) return $this->addRecord(static::WARNING, $message, $context);
	}

	/**
	 * Adds a log record at the ERROR level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $message The log message
	 * @param  array  $context The log context
	 * @return bool   Whether the record has been processed
	 */
	public function err($message, array $context = array())
	{
		if (!$this->isProduction) return $this->addRecord(static::ERROR, $message, $context);
	}

	/**
	 * Adds a log record at the ERROR level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $message The log message
	 * @param  array  $context The log context
	 * @return bool   Whether the record has been processed
	 */
	public function error($message, array $context = array())
	{
		if (!$this->isProduction) return $this->addRecord(static::ERROR, $message, $context);
	}

	/**
	 * Adds a log record at the CRITICAL level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $message The log message
	 * @param  array  $context The log context
	 * @return bool   Whether the record has been processed
	 */
	public function crit($message, array $context = array())
	{
		if (!$this->isProduction) return $this->addRecord(static::CRITICAL, $message, $context);
	}

	/**
	 * Adds a log record at the CRITICAL level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $message The log message
	 * @param  array  $context The log context
	 * @return bool   Whether the record has been processed
	 */
	public function critical($message, array $context = array())
	{
		if (!$this->isProduction) return $this->addRecord(static::CRITICAL, $message, $context);
	}

	/**
	 * Adds a log record at the ALERT level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $message The log message
	 * @param  array  $context The log context
	 * @return bool   Whether the record has been processed
	 */
	public function alert($message, array $context = array())
	{
		if (!$this->isProduction) return $this->addRecord(static::ALERT, $message, $context);
	}

	/**
	 * Adds a log record at the EMERGENCY level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $message The log message
	 * @param  array  $context The log context
	 * @return bool   Whether the record has been processed
	 */
	public function emerg($message, array $context = array())
	{
		if (!$this->isProduction) return $this->addRecord(static::EMERGENCY, $message, $context);
	}

	/**
	 * Adds a log record at the EMERGENCY level.
	 *
	 * This method allows for compatibility with common interfaces.
	 *
	 * @param  string $message The log message
	 * @param  array  $context The log context
	 * @return bool   Whether the record has been processed
	 */
	public function emergency($message, array $context = array())
	{
		if (!$this->isProduction) return $this->addRecord(static::EMERGENCY, $message, $context);
	}
}
