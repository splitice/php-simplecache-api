<?php
namespace Splitice\SimpleCache;


class ApiClient
{
	private $ch;
	private $encode;

	function __construct($urlBase, $encode = true)
	{
	    if(substr($urlBase, -1) != '/') $urlBase .= '/';
		$this->urlBase = $urlBase;
		$this->encode = $encode;
		$this->ch = curl_init();
		curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
	}

	function close()
	{
		curl_close($this->ch);
		$this->ch = null;
	}

	function __destruct()
	{
		if ($this->ch != null) {
			$this->close();
		}
	}

	function url($table, $key = null)
	{
		$url = $this->urlBase . ($this->encode ? urlencode($table) : $table);
		if ($key !== null) {
			$url .= '/' . ($this->encode ? urlencode($key) : $key);
		}
		return $url;
	}

	private function http_parse_headers($raw_headers)
	{
		$headers = array();
		$key = ''; // [+]

		foreach (explode("\n", $raw_headers) as $i => $h) {
			$h = explode(':', $h, 2);

			if (isset($h[1])) {
				$key = strtolower($h[0]); // [+]
				
				if (!isset($headers[$key]))
					$headers[$key] = trim($h[1]);
				elseif (is_array($headers[$key])) {
					// $tmp = array_merge($headers[$h[0]], array(trim($h[1]))); // [-]
					// $headers[$h[0]] = $tmp; // [-]
					$headers[$key] = array_merge($headers[$key], array(trim($h[1]))); // [+]
				} else {
					// $tmp = array_merge(array($headers[$h[0]]), array(trim($h[1]))); // [-]
					// $headers[$h[0]] = $tmp; // [-]
					$headers[$key] = array_merge(array($headers[$key]), array(trim($h[1]))); // [+]
				}

			} else // [+]
			{ // [+]
				if (substr($h[0], 0, 1) == "\t") // [+]
					$headers[$key] .= "\r\n\t" . trim($h[0]); // [+]
				elseif (!$key) // [+]
					$headers[0] = trim($h[0]); // [+]
			} // [+]
		}

		$ret = array();
        foreach($headers as $name=>$value){
            $ret[strtolower($name)] = $value;
        }

		return $ret;
	}

	function table_listing($table, $start = null, $limit = null, &$entries = null, &$total = null)
	{
		curl_setopt($this->ch, CURLOPT_HTTPGET, true);
		$headers = array();
		if ($start != null) {
			$headers[] = 'X-Start: ' . $start;
		}
		if ($limit != null) {
			$headers[] = 'X-Limit: ' . $limit;
		}
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, null);
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($this->ch, CURLOPT_URL, $this->url($table));
		curl_setopt($this->ch, CURLOPT_HEADER, true);
		$data = curl_exec($this->ch);
		$code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		if($code > 499){
			throw new \Exception('Server error: '.$code);
		}
		if ($code == 404) {
			return array();
		}

		$header_size = curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
		$header = substr($data, 0, $header_size);
		$content = substr($data, $header_size);
		$header = $this->http_parse_headers($header);
		$total = $header['x-total'] ?? 0;
		$entries = $header['x-entries'] ?? 0;

		if($entries == 0){
			return array();
		}

		$data = explode("\n", rtrim($content, "\r\n"));
		foreach ($data as $k => $v) {
			if (empty($v)) {
				unset($data[$k]);
			} else {
				$data[$k] = rtrim($v, "\r");
			}
		}
		$data = array_unique($data);
		return $data;
	}

	function key_delete($table, $key)
	{
		curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
		curl_setopt($this->ch, CURLOPT_URL, $this->url($table, $key));
		$data = curl_exec($this->ch);

		return trim($data) == 'DELETED';
	}

	function key_get($table, $key, &$expires = null)
	{
		curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, null);
		curl_setopt($this->ch, CURLOPT_HTTPGET, true);
		curl_setopt($this->ch, CURLOPT_URL, $this->url($table, $key));
		curl_setopt($this->ch, CURLOPT_HEADER, true);
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, array());
		$data = curl_exec($this->ch);
		if (curl_getinfo($this->ch, CURLINFO_HTTP_CODE) == 404) {
			return null;
		}
		if (curl_getinfo($this->ch, CURLINFO_HTTP_CODE) == 400) {
			throw new \Exception('Error Executing Request');
		}

		$header_size = curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
		$header = substr($data, 0, $header_size);
		$content = substr($data, $header_size);
		$header = $this->http_parse_headers($header);
		$expires = $header['x-ttl'];

		return $content;
	}

	function key_put($table, $key, $content, $expires = null)
	{
		curl_setopt($this->ch, CURLOPT_HTTPGET, false);
		curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'PUT');
		curl_setopt($this->ch, CURLOPT_POSTFIELDS, $content);
		curl_setopt($this->ch, CURLOPT_URL, $this->url($table, $key));
		curl_setopt($this->ch, CURLOPT_HEADER, true);

		if($expires){
			curl_setopt($this->ch, CURLOPT_HTTPHEADER, array('X-TTL: ' . $expires));
		}else{
			curl_setopt($this->ch, CURLOPT_HTTPHEADER, array());
		}

		$data = curl_exec($this->ch);
		$statusCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
		if ($statusCode != 200) {
			throw new \Exception('Error Executing Request');
		}
		
		return $data;
	}

	function key_meta($table, $key)
	{
		curl_setopt($this->ch, CURLOPT_NOBODY, true);
		curl_setopt($this->ch, CURLOPT_URL, $this->url($table, $key));
		curl_setopt($this->ch, CURLOPT_HEADER, true);
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, array());
		$data = curl_exec($this->ch);

		if (curl_getinfo($this->ch, CURLINFO_HTTP_CODE) == 404) {
			return null;
		}
		if (curl_getinfo($this->ch, CURLINFO_HTTP_CODE) == 400) {
			throw new \Exception('Error Executing Request');
		}

		$header_size = curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
		$header = substr($data, 0, $header_size);
		$header = $this->http_parse_headers($header);
		$metadata = array('length' => curl_getinfo($this->ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD), 'ttl' => $header['x-ttl']);
		return $metadata;
	}

	function key_bulkdelete($table, array $keys)
	{
		curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'PURGE');
		$headers = array();
		foreach ($keys as $k) {
			$headers[] = 'X-Delete: ' . $k;
		}
		curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($this->ch, CURLOPT_URL, $this->url($table));
		$data = curl_exec($this->ch);

		return trim($data) == 'BULK OK';
	}

	function table_delete($table)
	{
		curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
		curl_setopt($this->ch, CURLOPT_URL, $this->url($table));
		$data = curl_exec($this->ch);

		return trim($data) == 'DELETED';
	}
} 