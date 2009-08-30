<?php

Class Depender {
	const ConfigFilename  = 'config.json';
	const ScriptsFilename = 'scripts.json';

	const Post            = 'POST';
	const Get             = 'GET';

	const Yui             = 'yui';
	const JSMin           = 'jsmin';
	
	private static $config;
	private static $flat;
	
	public function getConfig() {
		if (isset(self::$config)) return self::$config;
		$file = self::ConfigFilename;
		$this->checkFile($file);
		self::$config = json_decode( file_get_contents( $file ), True );
		return self::$config;
	}
	
	private function checkFile($file) {
		if (!file_exists($file)) die('Could not load file: '.$file);
	}

	public function getLibraries() {
		$all      = Array();
		$config  = $this->getConfig();
		foreach($config['libs'] as $libraryName => $library) {
			$scripts           = $this->getScriptsFromLibraryName($libraryName);
			$all[$libraryName] = $scripts;
		}
		return $all;
	}

	private function getScriptsFromLibraryName($name) {
		$config  = $this->getConfig();
		$library = $config['libs'][$name];
		$file = $library['scripts'].'/'.self::ScriptsFilename;
		$this->checkFile($file);
		return json_decode(file_get_contents($file), True);
	}

	private function getScriptsNamesFromLibrary($library) {
		$all = Array();
		foreach($library as $categoryName => $scripts) {
			foreach($scripts as $scriptName => $script) {
				$all[] = $scriptName;
			}
		}
		return $all;
	}

	public function getCompressions() {
		$config = $this->getConfig();
		return $config['available_compressions'];
	}

	public function getDefaultCompression() {
		$config = $this->getConfig();
		return $config['compression'];
	}

	public function getVar($name, $default = False) {
		$var = null;
		switch ($_SERVER['REQUEST_METHOD']) {
			case self::Post:
				if (isset($_POST[$name])) $var = $_POST[$name];
				break;
			case self::Get:
				if (isset($_GET[$name])) $var = $_GET[$name];
				break;
		}

		if ( !$var ) {
			return $default;
		}
		return $var;
	}

	private function getFlatData() {
		if (isset(self::$flat)) return self::$flat;
		$config  = $this->getConfig();
		$flat    = Array();
		$all     = Array();
		$cacheId = 'flat';
		$cached  = $this->getCache($cacheId);
		if ($cached && $config['cache scripts.json']) {
			self::$flat = $cached;
			return $cached;
		}
		foreach($config['libs'] as $libraryName => $library) {
			$scripts = $this->getScriptsFromLibraryName($libraryName);

			foreach($scripts as $categoryName => $categotyScripts) {

				foreach($categotyScripts as $scriptName => $script) {
					$script['library']  = $libraryName;
					$script['category'] = $categoryName;
					$script['name']     = $scriptName;
					$script['path']     = $library['scripts'].'/'.$script['category'].'/'.$script['name'].'.js';
					$all[$scriptName]   = $script;
				}
			}
		}
		$this->setCache($cacheId, $all);
		self::$flat = $all;
		return $all;
	}

	private function getDependencies($scripts) {
		if (!is_array($scripts)) $scripts = array($scripts);
		$deps = array();
		$data = $this->getFlatData();
		foreach($scripts as $script) {
			if (!isset($data[$script])) {
				die($script." could not be found in the dependency map.");
			} else {
				foreach($data[$script]["deps"] as $dep) {
					if (!in_array($dep, $scripts)) $deps = array_merge($deps, $this->getDependencies($dep));
				}
				if (!in_array($script, $deps)) { array_push($deps, $script); }
			}
		}
		return $deps;
	}

	private function getScriptFile($scriptName, $compression=False) {
		$flat      = $this->getFlatData();
		$script    = $flat[$scriptName];
		if (!is_array($script)) {
			return '';
		}

		$atime     = fileatime($script['path']);
		$cacheId   = $script['name'].'_'.$atime.'_'.$compression;
		$cached    = $this->getCache($cacheId);
		if ($cached) {
			return $cached;
		}

		$contents  = file_get_contents($script['path']);

		if ($compression) {
			$contents = $this->compress($contents, $compression);
		}
		$this->setCache($cacheId, $contents);
		return $contents;
	}

	public function compress($string, $compression) {
		$file = 'compressors/'.$compression.'.php';
		$this->checkFile($file);
		include_once($file);
		$compressed = call_user_func_array($compression, array($string));
		return $compressed;
	}

	public function header() {
		header('Cache-Control: must-revalidate');
		if ($this->getVar('download')) {
			header('Content-Disposition: attachment; filename="built.js"');
		} else {
			header("Content-Type: application/x-javascript");
		}
	}

	private function getPageUrl() {
		$pageURL = 'http';
		if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"]) {$pageURL .= "s";}
		$pageURL .= "://";
		if ($_SERVER["SERVER_PORT"] != "80") {
			$pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
		} else {
			$pageURL .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
		}
		return str_replace('&download=true', '', $pageURL);
	}

	private function setCache($id, $value) {
		$file = fopen('cache/'.$id, 'w+') or die("can't open file: cache/".$id);
		$result = fwrite($file, serialize($value));
		fclose($file);
		return $result;
	}

	private function getCache($id) {
		$file = 'cache/'.$id;
		if (file_exists($file)) {
			return unserialize(file_get_contents($file));
		} else {
			return False;
		}
	}

	public function deleteCache($id) {
		$file = 'cache/'.$id;
		if (file_exists($file)) {
			return unlink($file);
		}
	}

	private function getLastModifiedDate($scripts) {
		$max  = 0;
		$flat = $this->getFlatData();
		foreach($scripts as $scriptName) {
			$script   = $flat[$scriptName];
			$modified = fileatime($script['path']);
			if ($modified > $max) {
				$max = $modified;
			}
		}
		return $max;
	}
	
	private function parseArray($str) {
		$ret = array();
		if (!is_array($str)) {
			if (strpos($str, ",") >=0) {
				$vals = explode(",", $str);
				foreach($vals as $val) {
					$ret[] = trim($val);
				}
			} else {
				$ret[] = $str;
			}
		} else if (is_array($str)){
			$ret = $str;
		}
		return $ret;
	}

	private function dependerJs($scripts) {
		$out = PHP_EOL.PHP_EOL;
		$out .= "Depender.loaded.combine(['".join($scripts, "','")."'])".PHP_EOL;
		$out .= "Depender.setOptions({".PHP_EOL;
		$url = split("\?", $this->getPageUrl());
		$out .= "	builder: '".$url[0]."'".PHP_EOL;
		$out .= "})";
		return $out;
	}

	public function build() {

		if ($this->getVar('reset')) $this->deleteCache('flat');

		$include     = $this->getVar('require') ? $this->parseArray($this->getVar('require')) : Array();
		$exclude     = $this->getVar('exclude') ? $this->parseArray($this->getVar('exclude')) : Array();
		
		if ($this->getVar('client')) $include[] = "Depender.Client";

		$includeLibs = $this->getVar('requireLibs') ? $this->parseArray($this->getVar('requireLibs')) : Array();
		$excludeLibs = $this->getVar('excludeLibs') ? $this->parseArray($this->getVar('excludeLibs')) : Array();

		$this->header();

		$libs        = $this->getLibraries();
		$includes    = Array();
		$excludes    = Array();

		foreach($includeLibs as $includeLib) {
			$library  = $libs[$includeLib];
			$includes = array_merge($includes, $this->getDependencies($this->getScriptsNamesFromLibrary($library)));
		}

		foreach($include as $script) {
			$includes   = array_merge($includes, $this->getDependencies($script));
			$includes[] = $script;
		}
		$includes = array_unique($includes); //No duplicate

		foreach($excludeLibs as $excludeLib) {
			$library  = $libs[$excludeLib];
			$excludes = array_merge($excludes, $this->getScriptsNamesFromLibrary($library));
		}

		foreach($exclude as $script) {
			$excludes[] = $script;
		}
		$excludes = array_unique($excludes); //No duplicate

		$includes = array_diff($includes, $excludes);

		$config      = $this->getConfig();
		$out         = join($config['copyright'], PHP_EOL).PHP_EOL.PHP_EOL;
		$out        .= '//Contents: '.join($includes, ', ').PHP_EOL.PHP_EOL;
		$out        .= '//This lib: '.$this->getPageUrl().PHP_EOL.PHP_EOL;



		if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $_SERVER['HTTP_IF_MODIFIED_SINCE']) {
			$browserCache = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
			if ($browserCache >= $this->getLastModifiedDate($includes)) {
				header('HTTP/1.1 304 Not Modified');
				exit;
			}
		}

		header('Last-modified: '.date('r', $this->getLastModifiedDate($includes)));

		foreach($includes as $include) {
			$out .= $this->getScriptFile($include, $this->getVar('compression'));
		}

		if (in_array('Depender.Client', $includes) || $this->getVar('client')) $out .= $this->dependerJs($includes);

		print $out;
	}
}
if (!file_exists('cache')) mkdir('cache');
$depender = New Depender;
if ($depender->getVar('require') || $depender->getVar('requireLibs') || $depender->getVar('client')) {
	$depender->build();
} else if ($depender->getVar('reset')) {
	$depender->deleteCache('flat');
}

?>