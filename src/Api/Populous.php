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
		$postFields = http_build_query($_d);
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

		$headers = array();
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		$headers[] = HEADER_KEY_API_KEY . ': ' . $this->apiKey;
		$headers[] = HEADER_KEY_SECRET_KEY . ': ' . $this->secretKey;
		$headers[] = HEADER_KEY_INTERNAL_CALL . ': ' . HEADER_VAL_INTERNAL_CALL;
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result = curl_exec($ch);
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

		return $this->_call($this->endpoint . '/UserApi/order', $formData);
	}

	public function cancel(array $formData = [])
	{
		return $this->_call($this->endpoint . '/UserApi/cancel', $formData);
	}


	public function __destruct()
	{
	}
}
