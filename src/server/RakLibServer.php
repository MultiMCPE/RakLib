<?php

/*
 * RakLib network library
 *
 *
 * This project is not affiliated with Jenkins Software LLC nor RakNet.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 */

declare(strict_types=1);

namespace raklib\server;

use Exception;
use Phar;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\thread\NonThreadSafeValue;
use pocketmine\thread\Thread;
use pmmp\thread\ThreadSafeArray;
use pocketmine\thread\ThreadSafeClassLoader;
use raklib\RakLib;
use raklib\utils\InternetAddress;
use Throwable;
use GlobalLogger;
use function array_reverse;
use function count;
use function error_get_last;
use function error_reporting;
use function function_exists;
use function gc_enable;
use function get_class;
use function getcwd;
use function gettype;
use function ini_set;
use function is_object;
use function method_exists;
use function mt_rand;
use function preg_replace;
use function realpath;
use function register_shutdown_function;
use function set_error_handler;
use function str_replace;
use function strval;
use function substr;
use function trim;
use function xdebug_get_function_stack;
use const DIRECTORY_SEPARATOR;
use const E_ALL;
use const E_COMPILE_ERROR;
use const E_COMPILE_WARNING;
use const E_CORE_ERROR;
use const E_CORE_WARNING;
use const E_DEPRECATED;
use const E_ERROR;
use const E_NOTICE;
use const E_PARSE;
use const E_RECOVERABLE_ERROR;
use const E_STRICT;
use const E_USER_DEPRECATED;
use const E_USER_ERROR;
use const E_USER_NOTICE;
use const E_USER_WARNING;
use const E_WARNING;
use const PHP_INT_MAX;

class RakLibServer extends Thread{
	/** @phpstan-var NonThreadSafeValue<InternetAddress> */
	private $address;

	/** @var ThreadedLogger */
	protected $logger;

	/** @var ThreadSafeClassLoader */
	protected $classLoader;

	/** @var bool */
	protected $shutdown = false;

	/** @var ThreadSafeArray */
	protected $externalQueue;
	/** @var ThreadSafeArray */
	protected $internalQueue;

	/** @var string */
	protected $mainPath;

	/** @var int */
	protected $serverId = 0;
	/** @var int */
	protected $maxMtuSize;
	/** @phpstan-var NonThreadSafeValue<array> */
	private $protocolVersions;

	/** @var SleeperNotifier */
	protected $mainThreadNotifier;

	/**
	 * @param ThreadSafeLogger      $logger
	 * @param ThreadSafeClassLoader $classLoader
	 * @param InternetAddress       $address
	 * @param int                   $maxMtuSize
	 * @param int[]                 $protocolVersions
	 * @param SleeperNotifier|null  $sleeper
	 */
	public function __construct(ThreadSafeLogger $logger, ThreadSafeClassLoader $classLoader, InternetAddress $address, int $maxMtuSize = 1492, $protocolVersions = [], ?SleeperNotifier $sleeper = null){
		$this->address = new NonThreadSafeValue($address);

		$this->serverId = mt_rand(0, PHP_INT_MAX);
		$this->maxMtuSize = $maxMtuSize;

		$this->logger = $logger;
		$this->classLoader = $classLoader;

		$this->externalQueue = new ThreadSafeArray;
		$this->internalQueue = new ThreadSafeArray;

		if(Phar::running(true) !== ""){
			$this->mainPath = Phar::running(true);
		}else{
			$this->mainPath = realpath(getcwd()) . DIRECTORY_SEPARATOR;
		}

		$this->protocolVersions = new NonThreadSafeValue(count($protocolVersions) === 0 ? [RakLib::DEFAULT_PROTOCOL_VERSION] : $protocolVersions);

		$this->mainThreadNotifier = $sleeper;
	}

	public function isShutdown() : bool{
		return $this->shutdown === true;
	}

	public function shutdown() : void{
		$this->shutdown = true;
	}

	/**
	 * Returns the RakNet server ID
	 * @return int
	 */
	public function getServerId() : int{
		return $this->serverId;
	}

	public function getProtocolVersions() : array{
		return $this->protocolVersions->deserialize();
	}

	/**
	 * @return ThreadSafeLogger
	 */
	public function getLogger() : ThreadSafeLogger{
		return $this->logger;
	}

	/**
	 * @return ThreadSafeArray
	 */
	public function getExternalQueue() : ThreadSafeArray{
		return $this->externalQueue;
	}

	/**
	 * @return ThreadSafeArray
	 */
	public function getInternalQueue() : ThreadSafeArray{
		return $this->internalQueue;
	}

	public function pushMainToThreadPacket(string $str) : void{
		$this->internalQueue[] = $str;
	}

	public function readMainToThreadPacket() : ?string{
		return $this->internalQueue->shift();
	}

	public function pushThreadToMainPacket(string $str) : void{
		$this->externalQueue[] = $str;
		if($this->mainThreadNotifier !== null){
			$this->mainThreadNotifier->wakeupSleeper();
		}
	}

	public function readThreadToMainPacket() : ?string{
		return $this->externalQueue->shift();
	}

	public function shutdownHandler(){
		if($this->shutdown !== true){
			$error = error_get_last();
			if($error !== null){
				$this->logger->emergency("Fatal error: " . $error["message"] . " in " . $error["file"] . " on line " . $error["line"]);
			}else{
				$this->logger->emergency("RakLib shutdown unexpectedly");
			}
		}
	}

	public function errorHandler($errno, $errstr, $errfile, $errline){
		if(error_reporting() === 0){
			return false;
		}

		$errorConversion = [
			E_ERROR => "E_ERROR",
			E_WARNING => "E_WARNING",
			E_PARSE => "E_PARSE",
			E_NOTICE => "E_NOTICE",
			E_CORE_ERROR => "E_CORE_ERROR",
			E_CORE_WARNING => "E_CORE_WARNING",
			E_COMPILE_ERROR => "E_COMPILE_ERROR",
			E_COMPILE_WARNING => "E_COMPILE_WARNING",
			E_USER_ERROR => "E_USER_ERROR",
			E_USER_WARNING => "E_USER_WARNING",
			E_USER_NOTICE => "E_USER_NOTICE",
			E_STRICT => "E_STRICT",
			E_RECOVERABLE_ERROR => "E_RECOVERABLE_ERROR",
			E_DEPRECATED => "E_DEPRECATED",
			E_USER_DEPRECATED => "E_USER_DEPRECATED"
		];

		$errno = $errorConversion[$errno] ?? $errno;

		$errstr = preg_replace('/\s+/', ' ', trim($errstr));
		$errfile = $this->cleanPath($errfile);

		$this->getLogger()->debug("An $errno error happened: \"$errstr\" in \"$errfile\" at line $errline");

		foreach($this->getTrace(2) as $i => $line){
			$this->getLogger()->debug($line);
		}

		return true;
	}

	public function getTrace($start = 0, $trace = null){
		if($trace === null){
			if(function_exists("xdebug_get_function_stack")){
				$trace = array_reverse(xdebug_get_function_stack());
			}else{
				$e = new Exception();
				$trace = $e->getTrace();
			}
		}

		$messages = [];
		$j = 0;
		for($i = (int) $start; isset($trace[$i]); ++$i, ++$j){
			$params = "";
			if(isset($trace[$i]["args"]) or isset($trace[$i]["params"])){
				if(isset($trace[$i]["args"])){
					$args = $trace[$i]["args"];
				}else{
					$args = $trace[$i]["params"];
				}
				foreach($args as $name => $value){
					$params .= (is_object($value) ? get_class($value) . " " . (method_exists($value, "__toString") ? $value->__toString() : "object") : gettype($value) . " " . @strval($value)) . ", ";
				}
			}
			$messages[] = "#$j " . (isset($trace[$i]["file"]) ? $this->cleanPath($trace[$i]["file"]) : "") . "(" . (isset($trace[$i]["line"]) ? $trace[$i]["line"] : "") . "): " . (isset($trace[$i]["class"]) ? $trace[$i]["class"] . (($trace[$i]["type"] === "dynamic" or $trace[$i]["type"] === "->") ? "->" : "::") : "") . $trace[$i]["function"] . "(" . substr($params, 0, -2) . ")";
		}

		return $messages;
	}

	public function cleanPath($path){
		return str_replace(["\\", ".php", "phar://", str_replace(["\\", "phar://"], ["/", ""], $this->mainPath)], ["/", "", "", ""], $path);
	}

	public function onRun() : void{
		//try{
		    $this->setClassLoader($this->classLoader);

			gc_enable();
			error_reporting(-1);
			ini_set("display_errors", '1');
			ini_set("display_startup_errors", '1');
			GlobalLogger::set($this->logger);

			set_error_handler([$this, "errorHandler"], E_ALL);
			register_shutdown_function([$this, "shutdownHandler"]);


			$socket = new UDPServerSocket($this->address->deserialize());
			new SessionManager($this, $socket, $this->maxMtuSize);
		//}catch(Throwable $e){
			//$this->logger->logException($e);
		//}
	}

}
