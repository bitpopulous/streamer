<?php

namespace PopulousWSS\Api;


/**
 * Using Populous API to send orders/request/etc...
 */
class Populous
{

	private $endpoint;
	private $apiKey;
	private $secretKey;

	private $status = '';
	private $statusCode = '';
	private $message = [];

	function __construct()
	{
		$this->endpoint = getenv('POPULOUS_INTERNAL_ENDPOINT');
		$this->apiKey = getenv('POPULOUS_API_KEY');
		$this->secretKey = getenv('POPULOUS_SECRET_KEY');
	}

	private function _call(string $url, array $params = [])
	{

		//open connection
		$ch = curl_init();

		$_d = $params;
		// $_d['_data'] = $params;

		$postFields = http_build_query($_d);
		// $postFields = $_d;

		// $this->log->debug('Postfields : ' . $postFields);
		// log_message('debug', 'Streamer => Postfields : ' . $postFields);

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

		$headers = array();
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		$headers[] = 'api_key: ' . $this->apiKey;
		$headers[] = 'secret_key: ' . $this->secretKey;
		$headers[] = 'internal_call: populous';
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result = curl_exec($ch);
		log_message('debug', $result);
		if (curl_errno($ch)) {
			echo 'Error:' . curl_error($ch);
		}

		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		// Close CURL Connection
		curl_close($ch);

		if ($http_code == 200) {
			$this->status = true;
			$this->statusCode = 'API_SUCCESS';
			$this->message = json_decode($result);
			return true;
		}
		$this->status = false;
		$this->statusCode = 'API_FAILED';

		if ($result != null && json_decode($result) != null) {
			$this->message = json_decode($result);
		}
		return false;
	}

	public function getMessage()
	{
		return $this->message;
	}

	public function getStatusCode()
	{
		return $this->statusCode;
	}
	public function getStatus()
	{
		return $this->status;
	}

	public function order(array $formData = [])
	{

		return $this->_call($this->endpoint . '/userapi/order', $formData);
	}

	public function cancel(array $formData = [])
	{
		return $this->_call($this->endpoint . '/userapi/cancel', $formData);
	}


	public function __destruct()
	{
	}
}
