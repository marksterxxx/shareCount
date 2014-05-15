<?php
require_once("config.php");
class shareCount {
	private $config;
	private $data;
	private $url;
	private $format;
	private $callback;
	private $cache;
	private $cache_directory;
	private $cache_time;
	
	function __construct() {
		$this->config = new Config;
		$this->cache = $this->config->cache;
		$this->cache_directory = $this->config->cache_directory;
		$this->cache_time = $this->config->cache_time;
	}
	
	private function getVar($var, $strict = false) {
		if(array_key_exists($var, $_REQUEST) && $_REQUEST[$var] !== "") return $_REQUEST[$var];
		elseif($strict) return false;
		else return $this->config->$var;
	}
	
	public function get() {
		$this->url = filter_input(INPUT_GET, 'url', FILTER_SANITIZE_URL);
		
		// kill the script if no URL provided
		if(!$this->url) die("Error: No URL specified.");
		
		$format                    = $this->setFormat($this->getVar('format'));
		$this->callback            = $this->getVar('callback');
		$this->data                = new stdClass;
		$this->data->url           = $this->url;
		$this->data->shares        = new stdClass;
		$this->data->shares->total = 0;
		
		$data = $this->getData();
		return $data;
	}
	
	// set format of the output
	private function setFormat ($format) {
		switch($format) {
		case "xml":
			$this->format = 'xml';
			header ("Content-Type:text/xml"); 
			break;
		case "jsonp": 
			$this->format = 'jsonp';
			header ("Content-Type: application/javascript"); 
			break;
		case "json": // only here for reference
		default:
			if($this->getVar('callback', true)) {
				$this->format = 'jsonp';
				header ("Content-Type: application/javascript"); 
			}
			else {
				$this->format = 'json';
				header ("Content-Type:application/json");
			}
		}
		return $format;
	}
	
	// query API to get share counts
	private function getShares() {
		$shareLinks = array(
		"facebook"    => "https://api.facebook.com/method/links.getStats?format=json&urls=",
		"twitter"     => "http://urls.api.twitter.com/1/urls/count.json?url=",
		"google"      => "https://plusone.google.com/_/+1/fastbutton?url=",
		"reddit"      => "http://www.reddit.com/api/info.json?&url=",
		"linkedin"    => "http://www.linkedin.com/countserv/count/share?format=json&url=",
		/*"digg"      => "http://widgets.digg.com/buttons/count?url=",*/
		"delicious"   => "http://feeds.delicious.com/v2/json/urlinfo/data?url=",
		"stumbleupon" => "http://www.stumbleupon.com/services/1.01/badge.getinfo?url=",
		"pinterest"   => "http://widgets.pinterest.com/v1/urls/count.json?source=6&url="
		);
	
		foreach($shareLinks as $service=>$url) {
			@$this->getCount($service, $url);
		}
		
		if($this->format == 'xml') $data = $this->generateValidXmlFromObj($this->data, "data");
		else $data = json_encode($this->data);
		
		return $data;
	}
	
	// query API to get share counts
	private function getCount($service, $url){
		$count = 0;
		$data = @file_get_contents($url . $this->url);
		if ($data) {
			switch($service) {
			case "facebook":
				$data = json_decode($data);
				$count = (is_array($data) ? $data[0]->total_count : $data->total_count);
				break;
			case "google":
				preg_match( '/window\.__SSR = {c: ([\d]+)/', $data, $matches );
				if(isset($matches[0])) $count = str_replace( 'window.__SSR = {c: ', '', $matches[0] );
				break;
			case "pinterest":
				$data = substr( $data, 13, -1);
			case "linkedin":
			case "twitter":
				$data = json_decode($data);
				$count = $data->count;
				break;
			case "reddit":
				$data = json_decode($data);
				if(count($data->data->children))
				$count = $data->data->children[0]->data->score;
				break;
			case "delicious":
				$data = json_decode($data);
				$count = $data[0]->total_posts;
				break;
			case "stumbleupon":
				$data = json_decode($data);
				$count = $data->result->views;
				break;
			default:
				// kill the script if trying to fetch from a provider that doesn't exist
				die("Error: Service not found");
			}
			$count = (int)$count;
			$this->data->shares->total += $count;
			$this->data->shares->$service = $count;
		} 
		return;
	}
	
	// Get data and return it. If cache is active check for cached data and create it if unsuccessful.
	private function getData() {
		// memcache
		if($this->cache == 1 | $this->cache == 2) {
			$key = md5($this->url) . '.' . ($this->format == 'jsonp' ? 'json' : $this->format);
			$memcache = new Memcache;
			$data = $memcache->get($key);
			if ($data === false) {
				$data = ($this->cache == 1 ? $this->getCacheFile($key) : $this->getShares());
				$memcache->set($key, $data, $this->cache_time);
			}
		}
		// file cache
		elseif($this->cache == 3) $data = $this->getCacheFile($key);
		// no cache
		else $data = $this->getShares();
		// if the format is JSONP wrap in callback function
		if($this->format == 'jsonp') $data = $this->callback . '(' . $data . ')';
		
		return $data;
	}
	
	// get cache file - create if doesn't exist
	private function getCacheFile($key) {
		if (!file_exists($this->cache_directory)) {
			mkdir($this->cache_directory, 0777, true);
		}
		$file = $this->cache_directory . $key;
		$file_created = ((@file_exists($file))) ? @filemtime($file) : 0;
		@clearstatcache();
		if (time() - $this->cache_time < $file_created) {
			return file_get_contents($file);
		}
		$data = $this->getShares();
		$fp = @fopen($file, 'w'); 
		@fwrite($fp, $data);
		@fclose($fp);
		return $data;
	}
	
	// Delete expired file cache. Use "kill" parameter to also flush the memory and delete all cache files.
	public function cleanCache($kill = null) {
		// flush memcache
		if($kill) {
			$memcache = new Memcache;
			$memcache->flush();
		}
		// delete cache files
		if ($handle = @opendir($this->cache_directory)) {
			while (false !== ($file = @readdir($handle))) {
				if ($file != '.' and $file != '..') {
					$file_created = ((@file_exists($file))) ? @filemtime($file) : 0;
					if (time() - $this->cache_time < $file_created or $kill) {
						echo $file . ' deleted.<br>';
						@unlink($this->cache_directory . '/' . $file);
					}
				}
			}
			@closedir($handle);
		}
	}
	
	// output share counts as XML
	// functions adopted from http://www.sean-barton.co.uk/2009/03/turning-an-array-or-object-into-xml-using-php/
	public static function generateValidXmlFromObj(stdClass $obj, $node_block='nodes', $node_name='node') {
		$arr = get_object_vars($obj);
		return self::generateValidXmlFromArray($arr, $node_block, $node_name);
	}

	public static function generateValidXmlFromArray($array, $node_block='nodes', $node_name='node') {
		$xml = '<?xml version="1.0" encoding="UTF-8" ?>';
		$xml .= '<' . $node_block . '>';
		$xml .= self::generateXmlFromArray($array, $node_name);
		$xml .= '</' . $node_block . '>';
		return $xml;
	}

	private static function generateXmlFromArray($array, $node_name) {
		$xml = '';
		if (is_array($array) || is_object($array)) {
			foreach ($array as $key=>$value) {
				if (is_numeric($key)) {
					$key = $node_name;
				}
				$xml .= '<' . $key . '>' . self::generateXmlFromArray($value, $node_name) . '</' . $key . '>';
			}
		} else {
			$xml = htmlspecialchars($array, ENT_QUOTES);
		}
		return $xml;
	}}