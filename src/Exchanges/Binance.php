<?php

namespace PopulousWSS\Exchanges;

use PopulousWSS\Common\Exchange;
use \Binance\API;

class Binance extends Exchange
{
	public function __construct()
	{
		$apiKey = getenv('BINANCE_API_KEY');
		$apiSecret = getenv('BINANCE_SECRET_KEY');
		$exchangeName = 'BINANCE';
		parent::__construct($apiKey, $apiSecret, $exchangeName);

		$this->api = new API($this->_key, $this->_secret);
	}


	public function getOrderBook($symbol, $limit = 50)
	{
		$this->orderBookResponse = [];
		try {
			$this->orderBookResponse = $this->api->depth($symbol, $limit);
			if ($this->orderBookResponse == null) {
				$this->orderBookResponse = [];
			}
		} catch (\Exception $e) {
			$this->orderBookResponse = [];
		}
		return $this->orderBookResponse;
	}

	public function sendLimitOrder(string $symbol, string $side, string $price, string $qty, string $clientId = '')
	{
		$side = strtoupper($side);
		$flags = [];
		if ($clientId !== '') {
			$flags['newClientOrderId'] = $clientId;
		}

		$this->limitOrderResponse = [];
		try {

			if ($side == 'BUY') {
				$this->limitOrderResponse = $this->api->buy($symbol, $qty, $price, 'LIMIT', $flags);
			} else if ($side == 'SELL') {
				$this->limitOrderResponse = $this->api->sell($symbol, $qty, $price, 'LIMIT', $flags);
			}
		} catch (\Exception $e) {
			$this->limitOrderResponse = [];
		}

		return $this->limitOrderResponse;
	}

	public function sendMarketOrder(string $symbol, string $side, string $qty, string $clientId = '')
	{

		$side = strtoupper($side);
		$flags = [];
		if ($clientId !== '') {
			$flags['newClientOrderId'] = $clientId;
		}

		$this->marketOrderResponse = [];

		try {

			if ($side == 'BUY') {
				$this->marketOrderResponse = $this->api->marketBuy($symbol, $qty, $flags);
			} else if ($side == 'SELL') {
				$this->marketOrderResponse = $this->api->marketSell($symbol, $qty, $flags);
			}
		} catch (\Exception $e) {
			$this->marketOrderResponse = [];
		}

		return $this->marketOrderResponse;
	}

	public function getOrderStatus(string $symbol, string $orderId)
	{

		$this->orderStatusResponse = [];
		try {
			$this->orderStatusResponse = $this->api->orderStatus($symbol, $orderId);
		} catch (\Exception $e) {
			$this->orderStatusResponse = [];
		}

		return $this->orderStatusResponse;
	}

	public function cancelOrder(string $symbol, string $binanceOrderId)
	{

		$this->cancelOrderResponse = [];
		try {
			$this->cancelOrderResponse = $this->api->cancel($symbol, $binanceOrderId);
		} catch (\Exception $e) {
			$this->cancelOrderResponse = [];
		}

		return $this->cancelOrderResponse;
	}

	public function loadExchangeInfo()
	{

		$this->exchangeInfoResponse = [];
		try {
			$this->exchangeInfoResponse = $this->api->exchangeInfo();
			if ($this->exchangeInfoResponse != null && isset($this->exchangeInfoResponse['symbols'])) {
				$this->exchangeSymbols = $this->exchangeInfoResponse['symbols'];
			}
		} catch (\Exception $e) {
			$this->exchangeInfoResponse = [];
		}

		$r = json_encode($this->exchangeInfoResponse, JSON_PRETTY_PRINT);
		$folderPath = APPPATH . '/exchange_info/';
		$filePath = $folderPath . $this->getName() . '.json';

		if (!file_exists($folderPath)) {
			mkdir($folderPath);
		}
		file_put_contents($filePath, $r);
		return $this->exchangeInfoResponse;
	}


	public function getSymbolInfo(string $symbol)
	{
		$symbol = strtoupper($symbol);
		if (
			!empty($this->exchangeInfoResponse) &&
			isset($this->exchangeInfoResponse['symbols']) &&
			isset($this->exchangeInfoResponse['symbols'][$symbol])
		) {
			return $this->exchangeInfoResponse['symbols'][$symbol];
		}
		return null;
	}

	public function getBestBidAskPrice(string $symbol)
	{
		$symbol = strtoupper($symbol);

		$this->bestBidAskPriceResponse = [];
		try {
			$this->bestBidAskPriceResponse = $this->api->bookPrices();
		} catch (\Exception $e) {
			$this->bestBidAskPriceResponse = [];
		}

		if (isset($this->bestBidAskPriceResponse[$symbol])) {
			return $this->bestBidAskPriceResponse[$symbol];
		}

		$this->bestBidAskPriceResponse;
	}

	/**
	 * Check if provided symbol is supported for trading on binance
	 */
	public function isSymbolSupported($symbol)
	{
		$symbol = strtoupper($symbol);
		if ($this->exchangeSymbols &&  isset($this->exchangeSymbols[$symbol]) && $this->exchangeSymbols[$symbol]['status'] == 'TRADING') {
			return true;
		}

		return false;
	}
}
