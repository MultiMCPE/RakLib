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

namespace raklib\protocol;

use pocketmine\utils\Binary;
use raklib\RakLib;
use raklib\utils\InternetAddress;
use function pack;
use function strlen;

class NewIncomingConnection extends Packet{
	public static $ID = MessageIdentifiers::ID_NEW_INCOMING_CONNECTION;

	/** @var InternetAddress */
	public $address;

	/** @var InternetAddress[] */
	public $systemAddresses = [];

	/** @var int */
	public $sendPingTime;
	/** @var int */
	public $sendPongTime;

	protected function encodePayload() : void{
		$this->putAddress($this->address);
		foreach($this->systemAddresses as $address){
			$this->putAddress($address);
		}
		($this->buffer .= (pack("NN", $this->sendPingTime >> 32, $this->sendPingTime & 0xFFFFFFFF)));
		($this->buffer .= (pack("NN", $this->sendPongTime >> 32, $this->sendPongTime & 0xFFFFFFFF)));
	}

	protected function decodePayload() : void{
		$this->address = $this->getAddress();

		//TODO: HACK!
		$stopOffset = strlen($this->buffer) - 16; //buffer length - sizeof(sendPingTime) - sizeof(sendPongTime)
		$dummy = new InternetAddress("0.0.0.0", 0, 4);
		for($i = 0; $i < RakLib::$SYSTEM_ADDRESS_COUNT; ++$i){
			if($this->offset >= $stopOffset){
				$this->systemAddresses[$i] = clone $dummy;
			}else{
				$this->systemAddresses[$i] = $this->getAddress();
			}
		}

		$this->sendPingTime = (Binary::readLong($this->get(8)));
		$this->sendPongTime = (Binary::readLong($this->get(8)));
	}
}
