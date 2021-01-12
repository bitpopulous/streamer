<?php

namespace PopulousWSS\Exchanges;

use PopulousWSS\Common\Exchange;
use PopulousWSS\ServerHandler;
use PopulousWSS\Trade\Trade;

use \Binance\API;

class Binance extends Exchange
{
	public function __construct()
	{
		$apiKey = getenv('BINANCE_API_KEY');
		$apiSecret = getenv('BINANCE_SECRET_KEY');
		$exchangeName = 'BINANCE';
		parent::__construct($apiKey, $apiSecret, $exchangeName);

		$this->ex = new API($this->_key, $this->_secret);
	}


	public function getOrderBook($symbol, $limit = 50)
	{
		try {
			$this->orderBookResponse = $this->ex->depth($symbol, $limit);
		} catch (\Exception $e) {
		}
		return $this->orderBookResponse;
	}

	public function sendLimitOrder(string $symbol, string $price, string $qty)
	{
	}

	public function sendMarketOrder(string $symbol, string $qty)
	{
	}

	public function getOrderStatus(string $orderId)
	{
	}

	public function cancelOrder(string $orderId)
	{
	}
}
