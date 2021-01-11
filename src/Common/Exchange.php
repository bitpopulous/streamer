<?php

namespace PopulousWSS\Common;

/**
 *
 * @author Populous World Ltd
 */
abstract class Exchange
{
	private $name = '';
	private $limitOrderResponse = [];
	private $marketOrderResponse = [];
	private $cancelOrderResponse = [];
	private $orderStatusResponse = [];
	private $orderBookResponse = [];

	public function __construct(string $apiKey, string $apiSecret, $name = '')
	{
		$this->setName($name);
		$this->setApiKey($apiKey);
		$this->setApiSecret($apiSecret);
	}

	abstract public  function getOrderBook(string $symbol, int $limit = 40);
	abstract public  function sendLimitOrder(string $symbol, string $price, string $qty);
	abstract public  function sendMarketOrder(string $symbol, string $qty);
	abstract public  function getOrderStatus(string $orderId);
	abstract public  function cancelOrder(string $orderId);

	private function setApiKey(string $_key)
	{
		$this->_key = $_key;
	}

	private function setApiSecret(string $_secret)
	{
		$this->_secret = $_secret;
	}

	public function setName(string $name)
	{
		$this->name = $name;
	}

	public function getName()
	{
		return $this->name;
	}

	public function getLimitOrderRes()
	{
		return $this->limitOrderResponse;
	}

	public function getMarketOrderRes()
	{
		return $this->marketOrderResponse;
	}

	public function getCancelOrderRes()
	{
		return $this->cancelOrderResponse;
	}

	public function getOrderStatusRes()
	{
		return $this->orderStatusResponse;
	}
	public function getOrderBookRes()
	{
		return $this->orderBookResponse;
	}
}
