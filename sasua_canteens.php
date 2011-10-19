<?php

/*****************************************************************************
 * SASUA Canteen menu parser
 *
 * SAS UA
 * Serviços de Ação Social da Universidade de Aveiro <http://www.sas.ua.pt>
 *
 * @package     SASUA_Canteens
 * @author      Jose' Pedro Saraiva <jose.pedro at ua.pt>
 * @version     1.2
 *
 * @description
 * Crawls SAS/UA pages and extracts all canteen menu info, displaying
 * it in more machine friendly formats
 *
 * http://www2.sas.ua.pt/site/temp/alim_ementas_V2.asp
 *
 *****************************************************************************/

/**
 * Main class
 *
 * @package SASUA_Canteens
 */
class SASUA_Canteens extends SASUA_Canteens_Object
{
	public $name = __CLASS__;
	public $title = 'SAS/UA canteen menu parser';
	public $version = '1.2';
	
	public $config;
	public $menus;
	
	public $zones;
	public $zone;
	public $zoneIndex;
	public $type;
	public $url;
	
	public $types = array( 
		'day', 
		'week' 
	);
	
	public $curlOptions = array(
		'user_agent' => '%NAME% >> %TITLE% v%VERSION%', 
		'connect_timeout' => 30, 
		'timeout' => 30, 
		'proxy' => '' 
	);
	
	private $__curl;
	private $__dom;
	private $__xpath;
	private $__dependencies = array( 
		'dom', 
		'tidy', 
		'simplexml' 
	);


	public function __construct( $cfgFilename = null, $cfgLoad = true )
	{
		foreach ( $this->__dependencies as $dep ) {
			if (! extension_loaded( $dep )) {
				throw new PHPMissingRequirementException( "Required PHP extension '$dep' is not loaded" );
			}
		}
		
		if (empty( $cfgFilename )) {
			$cfgFilename = realpath( dirname( __FILE__ ) ) . '/' . strtolower( pathinfo( __FILE__, PATHINFO_FILENAME ) ) . '.xml';
		}
		if ($cfgLoad) {
			$this->loadConfig( $cfgFilename );
		}
	}


	public function __destruct()
	{
		if ($this->__curl) {
			curl_close( $this->__curl );
		}
	}


	public function loadConfig( $filename )
	{
		if (! is_file( $filename ) || ! is_readable( $filename )) {
			throw new Exception( "Could not load config file '$filename', check permissions" );
		}
		
		if (($config = simplexml_load_file( $filename, 'SimpleXMLElement', LIBXML_NOCDATA )) === false) {
			throw new Exception( "Error parsing xml config file '$filename'" );
		}
		
		// setup zones array
		$zones = array();
		foreach ( $config->zones->zone as $z ) {
			$zones[] = (string) $z->attributes()->name;
		}
		if (empty( $zones )) {
			throw new Exception( 'Invalid config file, no zones found' );
		}
		
		// check the config and apply some changes
		$config->{'output-encoding'} = strtolower( $config->{'output-encoding'} );
		if (empty( $config->{'output-encoding'} )) {
			// just assume utf8
			$config->{'output-encoding'} = 'utf-8';
		}
		$config->{'input-encoding'} = strtolower( $config->{'input-encoding'} );
		
		for ($z = 0; $z < count( $config->zones->zone ); $z ++) {
			for ($u = 0; $u < count( $config->zones->zone[$z]->urls->url ); $u ++) {
				// append base url to all url's if needed
				if (! preg_match( '@^https?://@', $config->zones->zone[$z]->urls->url[$u] )) {
					$config->zones->zone[$z]->urls->url[$u] = rtrim( $config->url, '/' ) . '/' . $config->zones->zone[$z]->urls->url[$u];
				}
			}
		}
		
		$curlOpts = array();
		foreach ( $this->config->curl->param as $opt ) {
			$curlOpts[(string) $opt->attributes()->name] = (string) $opt;
		}
		
		$this->curlOptions = array_merge( $this->curlOptions, $curlOpts );
		unset( $curlOpts );
		
		$s = array( 
			'%TITLE%', 
			'%NAME%', 
			'%VERSION%'
		);
		$r = array( 
			$this->title,
			$this->name,
			$this->version
		);
		$this->curlOptions['user_agent'] = str_replace( $s, $r, $this->curlOptions['user_agent'] );	
		
		$cacheCfg = $config->xpath( '/config/cache/param' );
		if (! empty( $cacheCfg ) && is_array( $cacheCfg )) {
			SASUA_Canteens_Cache::config( $cacheCfg );
			SASUA_Canteens_Cache::gc();
		}
		
		if (! empty( $config->timezone )) {
			date_default_timezone_set( $config->timezone );
		}
		
		$this->config = $config;
		$this->zones = $zones;
	}


	public function get( $zone, $type, $format = 'xml', $cached = true )
	{
		$this->__init( $zone, $type );
		
		$ckey = SASUA_Canteens_Cache::key( array( 
			$this->zone, 
			$this->type 
		) );
		if ($cached && ($cacheData = $ckey->read()) !== false) {
			$this->menus = $cacheData;
		} else {
			$this->load();
			if ($cached) {
				$ckey->write( $this->menus );
			}
		}
		
		switch (strtolower( $format )) {
		case 'obj':
		case 'object':
			return $this->menus->asObj();
		case 'phps':
			return $this->menus->asPHPS();
		case 'json':
			return $this->menus->asJSON();
		case 'xml':
		default:
			return $this->menus->asXML( true, $this->getOutputEncoding() );
		}
	}


	public function load( $zone = null, $type = null )
	{
		if ($zone !== null && $type !== null) {
			$this->__init( $zone, $type );
		}
		
		$content = $this->__fetch( $this->url );
		
		// run tidy repair on returned html
		$tidy = new tidy();
		$content = $tidy->repairString( $content, array( 
			'wrap' => 0 
		) );
		
		$this->__dom = new DOMDocument();
		$this->__dom->preserveWhitespace = false;
		@$this->__dom->loadHTML( $content );
		
		$inputEncoding = $this->getInputEncoding();
		$inputEncoding = empty( $inputEncoding ) || $inputEncoding === 'auto' ? $this->__dom->encoding : $inputEncoding;
		
		$outputEncoding = $this->getOutputEncoding();

		if (! empty( $inputEncoding )) {
			$content = SASUA_Canteens_Utility::convertEncoding( $inputEncoding, $outputEncoding, $content );
		} else {
			trigger_error( 'Failed to detect website encoding, encoding conversion skipped', E_USER_NOTICE );
		}
		
		$this->__parse();
	}


	public function getUrl( $zone, $type )
	{
		$result = $this->config->xpath( "/config/zones/zone[@name='$zone']/urls/url[@type='$type']" );
		return ! empty( $result ) && is_array( $result ) ? (string) $result[0] : null;
	}


	public function getInputEncoding()
	{
		return (string) $this->config->{'input-encoding'};
	}


	public function getOutputEncoding()
	{
		return (string) $this->config->{'output-encoding'};
	}


	private function __init( $zone, $type )
	{
		$this->__reset();
		
		$zone = strtolower( $zone );
		$type = strtolower( $type );
		
		// this should be initialized before throwing any error
		$this->zone = $zone;
		$this->type = $type;
		$this->menus = new SASUA_Canteens_Menus( $this->zone, $this->type );
		$this->zoneIndex = array_search( $zone, $this->zones );
		$this->url = $this->getUrl( $this->zone, $this->type );
		if (empty( $this->url )) {
			throw new InvalidArgumentException( "Missing or no url matching params, zone: '{$this->zone}', type: '{$this->type}'" );
		}
		$this->url = $url;
	}


	private function __reset()
	{
		$this->zone = null;
		$this->type = null;
		$this->menus = null;
		$this->zoneIndex = null;
		$this->url = null;
	}


	private function __parse()
	{
		$this->__xpath = new DOMXPath( $this->__dom );
		
		switch ($this->type) {
		case 'day':
			$this->__parseDailyMenu();
			break;
		case 'week':
			$this->__parseWeeklyMenu();
			break;
		}
	}


	private function __parseDailyMenu()
	{
		$rows = $this->__xpath->query( $this->__getParserParam( 'rows', $this->type, $this->zone ) );
		
		if ($rows->length > 0) {
			// just assume today's date, we don't need to extract it from the website since we're using the same timezone
			$dates = array( 
				date( 'r' ) 
			);
			$items = array();
			$rowsOffset = (int) $this->__getParserParam( 'rows_offset', $this->type, $this->zone );
			$menuHeaderFilter = $this->__getParserParam( 'menu_header', $this->type, $this->zone, true );
			$skipRows = (int) $this->__getParserParam( 'menu_header', $this->type, $this->zone, false, false )->attributes()->skip_rows;
			
			// start at offset 2, so that we ignore date and links rows (see xml config)
			for ($i = $rowsOffset, $m = - 1, $filter = false; $i < $rows->length; $i ++) {
				$row = $rows->item( $i );
				
				// check if item is a meal header, and filter all empty entries after it
				$isMenuHeader = eval( $menuHeaderFilter );
				if ($isMenuHeader) {
					$m ++;
					if ($skipRows > 0) {
						$i += $skipRows;
					}
					$filter = true;
				} else {
					$item = $this->__normalize( $this->__sanitize( $row->nodeValue ) );
					if ($filter) {
						if ($item != '') {
							$filter = false;
						} else {
							continue;
						}
					}
					$items[$m][] = $item;
				}
			}
			$this->__build( $dates, $items );
		}
		
		return $this->menus;
	}


	private function __parseWeeklyMenu()
	{
		$rows = $this->__xpath->query( $this->__getParserParam( 'rows', $this->type, $this->zone ) );
		
		if ($rows->length > 0) {
			$dates = array();
			$items = array();
			$rowsOffset = (int) $this->__getParserParam( 'rows_offset', $this->type, $this->zone );
			$menuHeaderFilter = $this->__getParserParam( 'menu_header', $this->type, $this->zone, true );
			$skipRows = (int) $this->__getParserParam( 'menu_header', $this->type, $this->zone, false, false )->attributes()->skip_rows;
			
			for ($i = $rowsOffset, $m = - 1, $filter = false; $i < $rows->length; $i ++) {
				$row = $rows->item( $i );
				
				// check if item is a meal header, and filter all empty entries after it
				$isMenuHeader = eval( $menuHeaderFilter );
				if ($isMenuHeader) {
					$dateParser = $this->__getParserParam( 'date_regex', $this->type, $this->zone, false, false );
					$dates[] = $this->__parseDate( $this->__sanitize( $row->nodeValue ), (string) $dateParser[0], $dateParser->attributes()->format );
					
					$m ++;
					if ($skipRows > 0) {
						$i += $skipRows;
					}
					$filter = true;
				} else {
					$item = $this->__normalize( $this->__sanitize( $row->nodeValue ) );
					if ($filter) {
						if ($item != '') {
							$filter = false;
						} else {
							continue;
						}
					}
					$items[$m][] = $item;
				}
			}
			$dates = array_unique( $dates );
			$this->__build( $dates, $items );
		}
		
		return $this->menus;
	}


	private function __getParserParam( $name, $type, $zone = null, $eval = false, $flatten = true )
	{
		$query1 = "/config/parser/param[@name='$name' and @type='$type']";
		$query2 = "/config/parser/param[@name='$name' and @type='$type' and @zone='$zone']";
		
		$param = null;
		if (! empty( $zone )) {
			$param = $this->config->xpath( $query2 );
		}
		if (empty( $param ) || ! is_array( $param )) {
			$param = $this->config->xpath( $query1 );
		}
		
		if (empty( $param ) || ! is_array( $param )) {
			throw new Exception( "No parser param found matching passed parameters, name: '$name', type: '$type', zone: '$zone'" );
		}
		if ($eval) {
			return 'return ' . rtrim( (string) $param[0], ';' ) . ';';
		} else {
			return $flatten ? (string) $param[0] : $param[0];
		}
	}


	private function __build( $dates, $items )
	{
		$noMealsDefaultReason = (string) $this->config->meals->{'no-meals'}->attributes()->reason;
		$noMealsPattern = (string) $this->config->meals->{'no-meals'};

		$mealCount = 0;
		foreach ( $dates as $d ) {
			foreach ( $this->config->zones->zone[$this->zoneIndex]->canteens->canteen as $c ) {
				$ccm = 0; // current canteen meals
				foreach ( $this->config->meals->meal as $m ) {
					$item = 0;
					$currentMealItems = $items[$mealCount ++];
					
					$menu = $this->menus->add( (string) $c->attributes()->name, (string) $m->attributes()->name, $d );
					
					// handle "rest" zone special cases, so that we don't end up with item name == item contents
					if (is_array( $c->items->item ) || $c->items->item instanceof Traversable) {
						for ($i = 0; $i < count( $c->items->item ); $i ++) {
							$currentMealItems[$i * 2] = preg_replace( '/(buffet de \w+)/i', '\\1 variadas', $currentMealItems[$i * 2] );
							array_splice( $currentMealItems, ($i * 2), 0, (string) $c->items->item[$i]->attributes()->name );
						}
					}
					
					if ($item == 0 && $noMealsPattern && preg_match( $noMealsPattern, $currentMealItems[1] )) {
						$menu->disable( $currentMealItems[1] );
					} else {
						$allEmpty = true;
						for ($i = 0; $i < $c->attributes()->items; $i ++, $item += 2) {
							$menu->add( $currentMealItems[$item], $currentMealItems[$item + 1] );
							if (! empty( $currentMealItems[$item + 1] )) {
								$allEmpty = false;
							}
						}
						if ($allEmpty) {
							$menu->disable( $noMealsDefaultReason );
						}
					}
					
					if (++ $ccm === (int) $c->attributes()->meals) {
						break;
					}
				}
			}
		}
	}


	private function __parseDate( $date, $regex, $format )
	{
		$separator = '/';
		
		$format = str_split( $format );
		if (count( $format ) != 3) {
			throw new InvalidArgumentException( 'Invalid date format supplied' );
		}
		
		$matches = array();
		if (preg_match( $regex, $date, $matches )) {
			$date = array_combine( $format, array( 
				$matches[1], 
				$matches[2], 
				$matches[3] 
			) );
			if (is_numeric( $date['m'] )) {
				$date = implode( $separator, array( 
					$date['m'], 
					$date['d'], 
					$date['y'] 
				) );
			} else {
				$date = implode( ' ', array( 
					$date['d'], 
					SASUA_Canteens_Utility::translateMonthPT2EN( $date['m'] ), 
					$date['y'] 
				) );
			}
			return date( 'r', strtotime( $date ) );
		}
		return false;
	}


	private function __fetch( $url )
	{
		if (! $this->__curl) {
			$this->__curl = curl_init();
		}
		
		//curl_setopt( $this->__curl, CURLOPT_VERBOSE, true );
		curl_setopt( $this->__curl, CURLOPT_URL, $url );
		curl_setopt( $this->__curl, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $this->__curl, CURLOPT_USERAGENT, $this->curlOptions['user_agent'] );
		curl_setopt( $this->__curl, CURLOPT_CONNECTTIMEOUT, $this->curlOptions['connect_timeout'] );
		curl_setopt( $this->__curl, CURLOPT_TIMEOUT, $this->curlOptions['timeout'] );
		curl_setopt( $this->__curl, CURLOPT_PROXY, $this->curlOptions['proxy'] );
		$content = curl_exec( $this->__curl );
		
		if ($content === false || curl_getinfo( $this->__curl, CURLINFO_HTTP_CODE ) !== 200) {
			throw new HTTPFetchException( "Error fetching url '$url'" . (curl_errno( $this->__curl ) ? ', curl error: ' . curl_error( $this->__curl ) : '') );
		}
		return $content;
	}


	private function __sanitize( $str )
	{
		// replace all known unicode whitespaces with space
		$str = preg_replace( '/[\pZ\pC]+/mu', ' ', $str );
		$str = trim( $str );
		return $str;
	}


	private function __normalize( $str )
	{
		$str = ucfirst( strtolower( $str ) );
		$str = preg_replace( '/^-$/', '', $str );
		return $str;
	}
}

/**
 * Cache class for app's specific needs
 *
 * @package SASUA_Canteens
 * @subpackage SASUA_Canteens::Cache
 */
class SASUA_Canteens_Cache extends SASUA_Canteens_Object
{
	public static $config;
	public static $active = false;
	public static $defaults = array( 
		'active' => false, 
		'path' => '', 
		'prefix' => '', 
		'extension' => '', 
		'filemod' => 0666, 
		'separator' => '-', 
		'hash' => true 
	);
	
	private static $__gcLifetime = 86400;
	private static $__gcProbability = 10;
	private static $__gcDivisor = 100;


	public static function key( $params = array() )
	{
		return new SASUA_Canteens_Cache_Key( $params, self::$config );
	}


	public static function write( $key, $data, $writeIfEmpty = false )
	{
		if (! self::$active || (! $writeIfEmpty && empty( $data ))) {
			return false;
		}
		
		if (($data = @serialize( $data )) === false) {
			return false;
		}
		
		$cfile = self::$config['path'] . DIRECTORY_SEPARATOR . $key;
		$status = (@file_put_contents( $cfile, $data ) !== false);
		if (! empty( self::$config['filemod'] )) {
			@chmod( $cfile, self::$config['filemod'] );
		}
		return $status;
	}


	public static function read( $key )
	{
		if (! self::$active) {
			return false;
		}
		
		$cdata = false;
		$cfile = self::$config['path'] . DIRECTORY_SEPARATOR . $key;
		if (($content = @file_get_contents( $cfile )) !== false) {
			$cdata = @unserialize( $content );
		}
		return $cdata;
	}


	public static function config( $config )
	{
		if (! is_array( $config )) {
			$config = array( 
				$config 
			);
		}
		
		if (! empty( $config[0] ) && $config[0] instanceof SimpleXMLElement) {
			$tmp = array();
			foreach ( $config as $c ) {
				$tmp[(string) $c->attributes()->name] = (string) $c;
			}
			$config = $tmp;
			unset( $tmp );
		}
		
		$config = array_merge( self::$defaults, $config );
		$config['active'] = (strtolower( $config['active'] ) === 'true' || $config['active'] === '1' || $config['active'] === true);
		$config['filemod'] = is_string( $config['filemod'] ) ? octdec( $config['filemod'] ) : $config['filemod'];
		$config['hash'] = ($config['hash'] && strtolower( $config['hash'] ) !== 'false' && $config['hash'] !== '0');
		
		if (! empty( $config['path'] )) {
			if ($config['path'][0] !== DIRECTORY_SEPARATOR) {
				$config['path'] = realpath( dirname( __FILE__ ) ) . DIRECTORY_SEPARATOR . $config['path'];
			}
			$config['path'] = rtrim( $config['path'], DIRECTORY_SEPARATOR );
			
			if (! is_dir( $config['path'] )) {
				$mask = umask( 0 );
				if (false === @mkdir( $config['path'], 0777 )) {
					throw new Exception( "Could not create cache directory '{$config['path']}'" );
				}
				umask( $mask );
			}
			
			self::$config = $config;
			self::$active = true;
		}
	}


	public static function gc()
	{
		if (self::$active && mt_rand( 1, self::$__gcDivisor ) <= self::$__gcProbability) {
			foreach ( glob( self::$config['path'] . DIRECTORY_SEPARATOR . '*' ) as $file ) {
				$fmtime = filemtime( $file );
				if ($fmtime && ($fmtime + self::$__gcLifetime <= time())) {
					@unlink( $file );
				}
			}
		}
	}
}

/**
 * Hold cache key generation login
 * Default behaviour is to append today's date making the cache last until midnight of that day
 * 
 */
class SASUA_Canteens_Cache_Key
{
	public $key;
	public $config = array( 
		'prefix' => '', 
		'extension' => '', 
		'separator' => '-', 
		'append_date' => true, 
		'hash' => true 
	);


	public function __construct( $params = array(), $config = array() )
	{
		if (empty( $params )) {
			throw new InvalidArgumentException( 'Invalid or missing params for generating key' );
		}
		if (! is_array( $params )) {
			$params = array( 
				$params 
			);
		}
		$this->config = array_merge( $this->config, $config );
		if (empty( $this->config['separator'] )) {
			throw new InvalidArgumentException( 'Key separator cannot be empty' );
		}
		
		$key = $this->config['prefix'];
		$key .= $this->config['hash'] ? md5( implode( $this->config['separator'], $params ) ) : implode( $this->config['separator'], $params );
		$key .= $this->config['append_date'] ? date( 'dmY' ) : '';
		$key .= ! empty( $this->config['extension'] ) ? '.' . $this->config['extension'] : '';
		
		$this->key = $key;
		return $this;
	}


	public function __toString()
	{
		return $this->key;
	}


	public function write( $data, $writeIfEmpty = false )
	{
		return SASUA_Canteens_Cache::write( $this->key, $data, $writeIfEmpty );
	}


	public function read()
	{
		return SASUA_Canteens_Cache::read( $this->key );
	}
}

/**
 * Helper class for displaying SASUA_Canteens information for web applications
 *
 * @package SASUA_Canteens
 * @package SASUA_Canteens::Web
 */
class SASUA_Canteens_Web extends SASUA_Canteens_Object
{
	public $zone;
	public $type;
	public $format;
	
	public $params = array( 
		'zone' => 'GET:z', 
		'type' => 'GET:t', 
		'format' => 'GET:f' 
	);
	
	public $formats = array( 
		'phps' => 'application/octet-stream', 
		'json' => 'application/json', 
		'xml' => 'text/xml' 
	);
	
	public $app;
	public $appConfig;
	public $appConfigLoad = true;


	public function __construct( $params = null, $autoRender = true )
	{
		if ($params !== null) {
			$this->params = $params;
		}
		
		foreach ( (array) $this->params as $pname => $data ) {
			$tmp = explode( ':', $data );
			if (count( $tmp ) !== 2) {
				throw new Exception( 'Invalid params array' );
			}

			switch ($tmp[0]) {
			case 'POST':
				$this->{$pname} = isset( $_POST[$tmp[1]] ) ? $_POST[$tmp[1]] : null;
				break;
			case 'GET':
				$this->{$pname} = isset( $_GET[$tmp[1]] ) ? $_GET[$tmp[1]] : null;
				break;
			default:
				throw new Exception( "Invalid variable container '{$tmp[0]}'" );
			}
		}
		
		$this->app = new SASUA_Canteens( $this->appConfig, $this->appConfigLoad );
		
		if ($autoRender) {
			$this->render();
		}
	}


	public function render( $zone = null, $type = null, $format = null )
	{
		if ($zone !== null) {
			$this->zone = $zone;
		}
		if ($type !== null) {
			$this->type = $type;
		}
		if ($format !== null) {
			$this->format = $format;
		}
		
		switch ($this->type) {
		case 'd':
			$this->type = 'day';
			break;
		case 'w':
			$this->type = 'week';
			break;
		}
		
		try {
			if (! in_array( $this->type, $this->app->types, true ) || ! in_array( $this->zone, $this->app->zones, true )) {
				throw new InvalidRequestException();
			}
			
			$output = $this->app->get( $this->zone, $this->type, $this->format );
		} catch ( InvalidRequestException $e ) {
			$errorMsg = 'Missing or invalid parameters';
		} catch ( HTTPFetchException $e ) {
			$errorMsg = 'An error has occured contacting third party service';
		} catch ( Exception $e ) {
			$errorMsg = 'An error has occured';
		}
		
		if (empty( $this->format )) {
			$this->format = 'xml';
		}
		
		if (isset( $e ) && $e instanceof Exception) {
			$menus = is_object( $this->app->menus ) ? $this->app->menus : new SASUA_Canteens_Menus( $this->zone, $this->type );
			switch ($this->format) {
			default:
			case 'xml':
				$menus = SASUA_Canteens_Utility::XMLObj( $menus->asXML( false ) );
				$menus->addChild( 'error', $errorMsg );
				$output = $menus->document();
				break;
			case 'json':
				$menus = $this->app->menus->asObj();
				$menus->error = $errorMsg;
				$output = json_encode( $menus );
				break;
			case 'phps':
				$menus = $this->app->menus->asObj();
				$menus->error = $errorMsg;
				$output = serialize( $menus );
				break;
			}
		}
		
		$ctype = ! empty( $this->formats[$this->format] ) ? $this->formats[$this->format] : 'application/octet-stream';
		$charset = strtolower( $this->app->getOutputEncoding() );
		header( "Content-Type: $ctype;charset=$charset" );
		echo $output;
	}
}

/**
 * Base abstract class
 *
 * @package SASUA_Canteens
 */
abstract class SASUA_Canteens_Object
{
}

######################################################################################################
######################################################################################################


/**
 * Provides utility functions for SASUA_Canteens
 *
 * @package     SASUA_Canteens
 * @subpackage  SASUA_Canteens::Utility
 */
class SASUA_Canteens_Utility
{


	public static function translateMonthPT2EN( $month )
	{
		$search = array( 
			'janeiro', 
			'fevereiro', 
			'março', 
			'abril', 
			'maio', 
			'junho', 
			'julho', 
			'agosto', 
			'setembro', 
			'outubro', 
			'novembro', 
			'dezembro' 
		);
		
		$replace = array( 
			'january', 
			'february', 
			'march', 
			'april', 
			'may', 
			'june', 
			'july', 
			'august', 
			'september', 
			'october', 
			'november', 
			'december' 
		);
		
		return str_ireplace( $search, $replace, $month );
	}


	public static function DOMinnerHTML( $node )
	{
		if (! $node instanceof DOMNode) {
			throw new InvalidArgumentException( 'Supplied node is not a DOMNode, can\'t get inner HTML' );
		}
		$innerHTML = '';
		$children = $node->childNodes;
		foreach ( $children as $child ) {
			$innerHTML .= $child->ownerDocument->saveXML( $child );
		}
		
		return $innerHTML;
	}


	public static function convertEncoding( $fromEncoding, $toEncoding, $content )
	{
		if (empty( $fromEncoding ) || empty( $toEncoding )) {
			throw new InvalidArgumentException( 'Invalid or missing argument' );
		}
		if ($fromEncoding != $toEncoding) {
			$content = iconv( $fromEncoding, $toEncoding, $content );
		}
		return $content;
	}


	public static function hexDump( $data, $newline = "\n" )
	{
		static $from = '';
		static $to = '';
		static $width = 16; # number of bytes per line
		static $pad = '.'; # padding for non-visible characters
		

		if ($from === '') {
			for ($i = 0; $i <= 0xFF; $i ++) {
				$from .= chr( $i );
				$to .= ($i >= 0x20 && $i <= 0x7E) ? chr( $i ) : $pad;
			}
		}
		
		$hex = str_split( bin2hex( $data ), $width * 2 );
		$chars = str_split( strtr( $data, $from, $to ), $width );
		
		$offset = 0;
		foreach ( $hex as $i => $line ) {
			echo sprintf( '%6X', $offset ) . ' : ' . implode( ' ', str_split( $line, 2 ) ) . ' [' . $chars[$i] . ']' . $newline;
			$offset += $width;
		}
	} // hexDump }}}


	public static function XMLObj( $xml )
	{
		if (! is_string( $xml )) {
			throw new InvalidArgumentException( '$xml must be a string' );
		}

		$xml = ($xml[0] !== '<') ? "<$xml></$xml>" : $xml;
		return new SimpleXMLElementXT( $xml, LIBXML_NOXMLDECL );
	}
}

######################################################################################################
######################################################################################################


/**
 * Auxiliary class for building canteen menus
 *
 * @package     SASUA_Canteens
 * @subpackage  SASUA_Canteens::Menus
 */
class SASUA_Canteens_Menus extends SASUA_Canteens_Menu_Object
{
	public $zone;
	public $type;
	
	public $tag = 'menus';


	public function __construct( $zone, $type )
	{
		parent::__construct();
		$this->zone = $zone;
		$this->type = $type;
	}


	public function add( $canteen, $meal, $date )
	{
		$child = new SASUA_Canteens_Menu( $this, $canteen, $meal, $date );
		$this->children[] = $child;
		return $child;
	}


	public function asXML( $xmlDecl = false, $encoding = 'utf-8' )
	{
		$xml = '';
		foreach ( $this->children as $c ) {
			$xml .= $c->asXML();
		}
		$xml = "<{$this->tag}>$xml</{$this->tag}>";
		
		$xmlObj = SASUA_Canteens_Utility::XMLObj( $xml );
		
		$xmlObj->addAttribute( 'zone', $this->zone );
		$xmlObj->addAttribute( 'type', $this->type );
		
		return $xmlObj->document( null, array( 
			'xml_declaration' => $xmlDecl, 
			'encoding' => $encoding 
		) );
	}


	public function asObj()
	{
		$obj = parent::asObj();
		$obj->zone = $this->zone;
		$obj->type = $this->type;
		$obj->{$this->tag} = array();
		foreach ( $this->children as $c ) {
			$obj->{$this->tag}[] = $c->asObj();
		}
		
		return $obj;
	}
}

/**
 * Auxiliary class for building canteen menus
 *
 * @package     SASUA_Canteens
 * @subpackage  SASUA_Canteens::Menu
 */
class SASUA_Canteens_Menu extends SASUA_Canteens_Menu_Object
{
	public $canteen;
	public $meal;
	public $date;
	public $weekday;
	public $weekdayNr;
	public $disabled = false;
	
	public $tag = 'menu';
	public $tagItems = 'items';


	public function __construct( $parent = null, $canteen, $meal, $date )
	{
		parent::__construct( $parent );
		if (empty( $this->tagItems )) {
			throw new Exception( 'tagItems property cannot be empty' );
		}
		
		$this->canteen = $canteen;
		$this->meal = $meal;
		$this->date = $date;
		
		$tmp = strtotime( $date );
		$this->weekday = date( 'l', $tmp );
		$this->weekdayNr = date( 'w', $tmp );
	}


	public function add( $name, $content )
	{
		$child = new SASUA_Canteens_Menu_Item( $this, $name, $content );
		$this->children[] = $child;
		return $child;
	}


	public function disable( $reason = null )
	{
		if (empty( $reason )) {
			$reason = 1;
		}
		$this->disabled = $reason;
	}


	public function asXML( $xmlDecl = false, $encoding = 'utf-8' )
	{
		$xml = '';
		if (! $this->disabled) {
			foreach ( $this->children as $c ) {
				$xml .= $c->asXML();
			}
		}
		
		$xml = "<{$this->tag}><{$this->tagItems}>$xml</{$this->tagItems}></{$this->tag}>";	
		$xmlObj = SASUA_Canteens_Utility::XMLObj( $xml );
		
		$xmlObj->addAttribute( 'canteen', $this->canteen );
		$xmlObj->addAttribute( 'meal', $this->meal );
		$xmlObj->addAttribute( 'date', $this->date );
		$xmlObj->addAttribute( 'weekday', $this->weekday );
		$xmlObj->addAttribute( 'weekdayNr', $this->weekdayNr );
		$xmlObj->addAttribute( 'disabled', $this->disabled !== false ? $this->disabled : 0 );
		
		return $xmlObj->document( null, array( 
			'xml_declaration' => $xmlDecl, 
			'encoding' => $encoding 
		) );
	}


	public function asObj()
	{
		$obj = parent::asObj();
		$obj->canteen = $this->canteen;
		$obj->meal = $this->meal;
		$obj->date = $this->date;
		$obj->weekday = $this->weekday;
		$obj->weekdayNr = $this->weekdayNr;
		$obj->disabled = $this->disabled;
		$obj->{$this->tagItems} = array();
		if (! $this->disabled) {
			foreach ( $this->children as $c ) {
				$obj->{$this->tagItems}[] = $c->asObj();
			}
		}
		return $obj;
	}
}

/**
 * Auxiliary class for building canteen menus
 *
 * @package     SASUA_Canteens
 * @subpackage  SASUA_Canteens::Menu_Item
 */
class SASUA_Canteens_Menu_Item extends SASUA_Canteens_Menu_Object
{
	public $name;
	public $content;
	
	public $tag = 'item';


	public function __construct( $parent = null, $name, $content )
	{
		parent::__construct( $parent );
		$this->name = $name;
		$this->content = $content;
	}


	public function asXML( $xmlDecl = false, $encoding = 'utf-8' )
	{
		$xmlObj = SASUA_Canteens_Utility::XMLObj( $this->tag );
		$xmlObj->{0} = $this->content;
		$xmlObj->addAttribute( 'name', $this->name );
		
		return $xmlObj->document( null, array( 
			'xml_declaration' => $xmlDecl, 
			'encoding' => $encoding 
		) );
	}


	public function asObj()
	{
		$obj = parent::asObj();
		$obj->name = $this->name;
		$obj->content = $this->content;
		
		return $obj;
	}
}

/**
 * Base abstract class for all menu building classes
 *
 * @package     SASUA_Canteens
 * @subpackage  SASUA_Canteens::Menu_Object
 */
abstract class SASUA_Canteens_Menu_Object
{
	public $tag;
	public $parent;
	public $children = array();


	public function __construct( $parent = null )
	{
		if (empty( $this->tag )) {
			throw new Exception( 'tag property cannot be empty' );
		}
		$this->parent = $parent;
	}


	abstract public function asXML( $xmlDecl = false, $encoding = 'utf-8' );


	public function asObj()
	{
		return new stdClass();
	}


	public function asJSON()
	{
		return json_encode( $this->asObj() );
	}


	public function asPHPS()
	{
		return serialize( $this->asObj() );
	}
}

######################################################################################################
######################################################################################################


/**
 * Exception classes
 */
class HTTPFetchException extends Exception
{
}

class PHPMissingRequirementException extends Exception
{
}

class InvalidRequestException extends Exception
{
}

######################################################################################################
######################################################################################################


/**
 * Extension of SimpleXMLElement
 *
 * @package     SASUA_Canteens
 */
class SimpleXMLElementXT extends SimpleXMLElement
{


	public function document( $filename = null, $options = array() )
	{
		extract( $options, EXTR_SKIP );
		
		$dom = dom_import_simplexml( $this );
		$dom->ownerDocument->formatOutput = true;
		if (isset( $xml_declaration ) && $xml_declaration) {
			if (! empty( $encoding )) {
				$dom->ownerDocument->encoding = strtoupper( $encoding );
			}
			$doc = $dom->ownerDocument->saveXML();
		} else {
			$doc = $dom->ownerDocument->saveXML( $dom );
		}
		return $filename !== null ? @file_put_contents( $filename, $doc ) !== false : $doc;
	}
}

?>
