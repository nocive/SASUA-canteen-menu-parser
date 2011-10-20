<?php

/*****************************************************************************
 * SASUA Canteen menu parser
 *
 * SAS UA
 * Serviços de Ação Social da Universidade de Aveiro <http://www.sas.ua.pt>
 *
 * @package     SASUA_Canteens
 * @author      Jose' Pedro Saraiva <jose.pedro at ua.pt>
 * @version     1.3
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
	/**
	 * @var		string
	 * @access	public
	 */
	public $name = __CLASS__;
	
	/**
	 * @var		string
	 * @access	public
	 */
	public $title = 'SAS/UA canteen menu parser';
	
	/**
	 * @var		string
	 * @access	public
	 */
	public $version = '1.3';
	
	/**
	 * @var		SimpleXMLElement
	 * @access	public
	 */
	public $config;
	
	/**
	 * @var		string
	 * @access	public
	 */
	public $configFile;
	
	/**
	 * @var		SASUA_Canteens_Menus
	 * @access	public
	 */
	public $menus;
	
	/**
	 * @var		array
	 * @access	public
	 */
	public $zones;
	
	/**
	 * @var		string
	 * @access	public
	 */
	public $zone;
	
	/**
	 * @var		int
	 * @access	public
	 */
	public $zoneIndex;
	
	/**
	 * @var		string
	 * @access	public
	 */
	public $type;
	
	/**
	 * @var		string
	 * @access	public
	 */
	public $url;
	
	/**
	 * @var		array
	 * @access	public
	 */
	public $types = array( 
		'day', 
		'week' 
	);
	
	/**
	 * @var		array
	 * @access	public
	 */
	public $curlOptions = array();
	
	/**
	 * @var		array
	 * @access	private
	 */
	private $__curlDefaults = array( 
		'user_agent' => '%NAME% >> %TITLE% v%VERSION%', 
		'connect_timeout' => 30, 
		'timeout' => 30, 
		'proxy' => '' 
	);
	
	/**
	 * @var		resource
	 * @access	private
	 */
	private $__curl;
	
	/**
	 * @var		DOMDocument
	 * @access	private
	 */
	private $__dom;
	
	/**
	 * @var		DOMXPath
	 * @access	private
	 */
	private $__xpath;
	
	/**
	 * @var		array
	 * @access	private
	 */
	private $__dependencies = array( 
		'dom', 
		'tidy', 
		'simplexml',
		'iconv'
	);


	/**
	 * Constructor, checks PHP requirements and loads config if requested
	 * 
	 * @param	string $cfgFilename			optional
	 * @param	bool $cfgLoad				optional
	 * @throws	PHPMissingRequirementException
	 * @return	void
	 */
	public function __construct( $cfgFilename = null, $cfgLoad = true )
	{
		self::instance( $this );
		
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
	} // __construct }}}


	/**
	 * Destructor
	 * 
	 * @return	void
	 */
	public function __destruct()
	{
		if ($this->__curl) {
			curl_close( $this->__curl );
		}
	} // __destruct }}}


	/**
	 * Gets or sets a singleton instance
	 * 
	 * @param	SASUA_Canteens $setInstance		optional
	 * @return	SASUA_Canteens
	 */
	public static function instance( $setInstance = null )
	{
		static $instance;
		
		if ($setInstance) {
			$instance = $setInstance;
		}
		
		if (! $instance) {
			$c = __CLASS__;
			$instance = new $c();
		}
		return $instance;
	} // instance }}}


	/**
	 * Loads and checks config file and it's dependencies
	 * 
	 * @param	string $filename
	 * @throws	Exception
	 * @return	SimpleXMLElement
	 */
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
		foreach ( $config->curl->param as $opt ) {
			$curlOpts[(string) $opt->attributes()->name] = (string) $opt;
		}
		$curlOpts = array_merge( $this->__curlDefaults, $curlOpts );
		
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
		$curlOpts['user_agent'] = str_replace( $s, $r, $curlOpts['user_agent'] );
		$this->curlOptions = $curlOpts;
		unset( $curlOpts );
		
		$cacheCfg = $config->xpath( '/config/cache/param' );
		if (! empty( $cacheCfg ) && is_array( $cacheCfg )) {
			SASUA_Canteens_Cache::config( $cacheCfg );
			SASUA_Canteens_Cache::gc();
		}
		
		if (! empty( $config->timezone )) {
			date_default_timezone_set( $config->timezone );
		}
		
		$this->config = $config;
		$this->configFile = $filename;
		$this->zones = $zones;
		
		return $this->config;
	} // loadConfig }}}


	/**
	 * Retrieve menus in specified format
	 *
	 * @param	string $zone
	 * @param	string $type
	 * @param	string $format	optional
	 * @param	bool $cached	optional
	 * @return	mixed			object for object format, string for all the rest
	 */
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
	} // get }}}


	/**
	 * Fetches, loads and parses menus
	 * 
	 * @param	string $zone	optional
	 * @param	string $type	optional
	 * @return	void
	 */
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
	} // load }}}


	/**
	 * Get url for specified zone and type
	 * 
	 * @param	string $zone
	 * @param	string $type
	 * @return	string|null
	 */
	public function getUrl( $zone, $type )
	{
		$result = $this->config->xpath( "/config/zones/zone[@name='$zone']/urls/url[@type='$type']" );
		return ! empty( $result ) && is_array( $result ) ? (string) $result[0] : null;
	} // getUrl }}}


	/**
	 * Get input encoding
	 * 
	 * @return	string
	 */
	public function getInputEncoding()
	{
		return (string) $this->config->{'input-encoding'};
	} // getInputEncoding }}}


	/**
	 * Get output encoding
	 * 
	 * @return	string
	 */
	public function getOutputEncoding()
	{
		return (string) $this->config->{'output-encoding'};
	} // getOutputEncoding }}}


	/**
	 * Internal init logic
	 * 
	 * @param	string $zone
	 * @param	string $type
	 * @throws	InvalidArgumentException
	 * @return	void
	 */
	private function __init( $zone, $type )
	{
		$this->__reset();
		
		$zone = strtolower( $zone );
		$type = strtolower( $type );
		
		$this->zone = $zone;
		$this->type = $type;
		$this->zoneIndex = array_search( $zone, $this->zones );
		$this->url = $this->getUrl( $this->zone, $this->type );
		if (empty( $this->url )) {
			throw new InvalidArgumentException( "Missing or no url matching params, zone: '{$this->zone}', type: '{$this->type}'" );
		}
		$this->menus = new SASUA_Canteens_Menus( $this->zone, $this->type );
	} // __init }}}


	/**
	 * Reset class properties
	 * 
	 * @return	void
	 */
	private function __reset()
	{
		$this->zone = null;
		$this->type = null;
		$this->menus = null;
		$this->zoneIndex = null;
		$this->url = null;
	} // __reset }}}


	/**
	 * Parse wrapper, calls specific parser by type
	 * 
	 * @return	void
	 */
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
	} // __parse }}}


	/**
	 * Parser for daily menus
	 * 
	 * @return	void
	 */
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
				
				$isMenuHeader = eval( $menuHeaderFilter );
				// check if item is a meal header, and filter all empty entries after it until we find a non empty entry
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
	} // __parseDailyMenu }}}


	/**
	 * Parser for weekly menus
	 * 
	 * @return	void
	 */
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
				
				$isMenuHeader = eval( $menuHeaderFilter );
				// check if item is a meal header, and filter all empty entries after it until we find a non empty entry
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
	} // __parseWeeklyMenu }}}


	/**
	 * Get parser parameter from config
	 * 
	 * @param	string $name
	 * @param	string $tyhpe
	 * @param	string $zone	optional
	 * @param	bool $eval		optional
	 * @param	bool $flatten	optional
	 * @throws 	Exception
	 * @return	mixed			string if either eval or flatten is true, SimpleXMLElement otherwise
	 */
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
	} // __getParserParam }}}


	/**
	 * Build menu from gathered arrays by parser
	 * 
	 * @param	array $dates
	 * @param	array $items
	 * @return	void
	 */
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
	} // __build }}}


	/**
	 * Parse dates in different formats
	 * 
	 * @param	string $date
	 * @param	string $regex
	 * @param	string $format
	 * @throws	InvalidArgumentException
	 * @return	string						string on success, false on failure
	 */
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
	} // __parseDate }}}


	/**
	 * Enter description here ...
	 * 
	 * @param	string $url
	 * @throws	HTTPFetchException
	 * @return	string
	 */
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
	} // __fetch }}}


	/**
	 * Enter description here ...
	 * 
	 * @param	string $str
	 * @return	string
	 */
	private function __sanitize( $str )
	{
		// replace all known unicode whitespaces with space
		$str = preg_replace( '/[\pZ\pC]+/mu', ' ', $str );
		$str = trim( $str );
		return $str;
	} // __sanitize }}}


	/**
	 * Enter description here ...
	 * 
	 * @param	string $str
	 * @return	string
	 */
	private function __normalize( $str )
	{
		$str = ucfirst( strtolower( $str ) );
		$str = preg_replace( '/^-$/', '', $str );
		return $str;
	} // __normalize }}}
} // SASUA_Canteens }}}


/**
 * Helper class for displaying SASUA_Canteens data for web applications
 *
 * @package SASUA_Canteens
 * @package SASUA_Canteens::Web
 */
class SASUA_Canteens_Web extends SASUA_Canteens_Object
{
	/**
	 * @var 	string
	 * @access	public
	 */
	public $zone;
	
	/**
	 * @var 	string
	 * @access	public
	 */
	public $type;
	
	/**
	 * @var 	string
	 * @access	public
	 */
	public $format;
	
	/**
	 * @var 	array
	 * @access	public
	 */
	public $params = array( 
		'zone' => 'GET:z', 
		'type' => 'GET:t', 
		'format' => 'GET:f' 
	);
	
	/**
	 * @var 	array
	 * @access	public
	 */
	public $formats = array( 
		'phps' => 'application/octet-stream', 
		'json' => 'application/json', 
		'xml' => 'text/xml' 
	);
	
	/**
	 * @var 	SASUA_Canteens
	 * @access	public
	 */
	public $app;


	/**
	 * Constructor
	 * 
	 * @param	array $params		optional
	 * @param	bool $autoRender	optional
	 */
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
				throw new Exception( "Invalid method for variable retrieval '{$tmp[0]}'" );
			}
		}
		
		$this->app = SASUA_Canteens::instance();
		
		if ($autoRender) {
			$this->render();
		}
	} // __construct }}}


	/**
	 * Renders data and sets headers for proper browser display
	 * 
	 * @param string $zone		optional
	 * @param string $type		optional
	 * @param string $format	optional
	 * @param bool $echo		optional
	 * @return void|string		returns string if echo is false
	 */
	public function render( $zone = null, $type = null, $format = null, $echo = true )
	{
		if ($zone !== null) {
			$this->zone = $zone;
		}
		if ($type !== null) {
			$this->type = $type;
		}
		if ($format !== null) {
			$this->format = strtolower( $format );
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
			$err = array( 
				'msg' => 'Missing or invalid request parameters (zone or type)', 
				'code' => 1 
			);
		} catch ( HTTPFetchException $e ) {
			$err = array( 
				'msg' => 'An error has occured contacting third party service', 
				'code' => 2 
			);
		} catch ( Exception $e ) {
			$err = array( 
				'msg' => 'An error has occured', 
				'code' => 3 
			);
		}
		
		if (empty( $this->format )) {
			$this->format = 'xml';
		}
		
		if (isset( $e ) && $e instanceof Exception) {
			$output = (string) $this->error( $err, $this->format );
		}
		
		if ($echo) {
			$ctype = ! empty( $this->formats[$this->format] ) ? $this->formats[$this->format] : 'application/octet-stream';
			$charset = strtolower( $this->app->getOutputEncoding() );
			header( "Content-Type: $ctype;charset=$charset" );
			echo $output;
		} else {
			return $output;
		}
	} // render }}}


	/**
	 * Builds an error for display
	 * 
	 * @param 	array $err
	 * @param 	string $format
	 * @return 	string
	 */
	public function error( $err, $format )
	{
		$exception = null;
		
		$default = array( 
			'msg' => null, 
			'code' => null 
		);
		
		if (is_string( $err )) {
			$err = array( 
				'msg' => $err, 
				'code' => null 
			);
		} elseif (is_array( $err )) {
			$err = array_merge( $default, $err );
		} else {
			throw new InvalidArgumentException( '$err must be a string or array' );
		}
		
		return new SASUA_Canteens_Web_Error( $err['msg'], $err['code'], $format );
	} // error }}}
} // SASUA_Canteens_Web }}}


/**
 * Helper class for building and displaying errors for Web class
 * 
 * @package SASUA_Canteens
 * @subpackage SASUA_Canteens::Web
 */
class SASUA_Canteens_Web_Error
{
	public $rootNode = 'menus';
	public $message;
	public $code;
	public $format;


	public function __construct( $message, $code = null, $format = null )
	{
		$this->message = $message;
		$this->code = $code;
		$this->setFormat( $format );
	} // __construct }}}


	public function __toString()
	{
		switch ($this->format) {
		case 'xml':
			$obj = SASUA_Canteens_Utility::XMLObj( $this->rootNode );
			$obj->addChild( 'error' );
			$obj->error->addAttribute( 'msg', $this->message );
			$obj->error->addAttribute( 'code', $this->code );
			$err = $obj->document();
			break;
		case 'json':
		case 'phps':
			$obj = new stdClass();
			$obj->error->msg = $this->message;
			$obj->error->code = $this->code;
			$err = $this->format === 'json' ? SASUA_Canteens_Utility::toJSON( $obj ) : SASUA_Canteens_Utility::toPHPS( $obj );
			break;
		}
		return $err;
	} // __toString }}}


	public function setFormat( $format )
	{
		$format = strtolower( $format );
		switch ($format) {
		case 'xml':
		case 'json':
			$this->format = $format;
			break;
		default:
			$this->format = 'xml';
		}
	} // setFormat }}}
} // SASUA_Canteens_Web_Error }}}


/**
 * Cache class
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
	} // key }}}


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
	} // write }}}


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
	} // read }}}


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
					throw new Exception( "Error creating cache directory '{$config['path']}'" );
				}
				umask( $mask );
			}
			
			self::$config = $config;
			self::$active = true;
		}
	} // config }}}


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
	} // gc }}}
} // SASUA_Canteens_Cache }}}


/**
 * Hold cache key generation logic
 * Default behaviour is to append today's date making the cache last while the day lasts
 * 
 * @package SASUA_Canteens
 * @subpackage SASUA_Canteens::Cache
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
	} // __construct }}}


	public function __toString()
	{
		return $this->key;
	} // __toString }}}


	public function write( $data, $writeIfEmpty = false )
	{
		return SASUA_Canteens_Cache::write( $this->key, $data, $writeIfEmpty );
	} // write }}}


	public function read()
	{
		return SASUA_Canteens_Cache::read( $this->key );
	} // read }}}
} // SASUA_Canteens_Cache_Key }}}


/**
 * Base abstract class
 *
 * @package SASUA_Canteens
 */
abstract class SASUA_Canteens_Object
{
} // SASUA_Canteens_Object }}}


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


	/**
	 * Translate month names from portuguese to english, so that date can be fed to strtotime
	 * 
	 * @param	string $month
	 * @return	string
	 */
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
	} // translateMonthPT2EN }}}


	/**
	 * Gets the inner html of a DOMNode
	 * 
	 * @param 	DOMNode $node
	 * @return 	string
	 */
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
	} // DOMinnerHTML }}}


	/**
	 * Convert charset encoding from one encoding to another
	 * 
	 * @param	string $fromEncoding
	 * @param	string $toEncoding
	 * @param	string $content
	 * @return	string
	 */
	public static function convertEncoding( $fromEncoding, $toEncoding, $content )
	{
		if (empty( $fromEncoding ) || empty( $toEncoding )) {
			throw new InvalidArgumentException( 'Invalid or missing argument' );
		}
		if ($fromEncoding != $toEncoding) {
			$content = iconv( $fromEncoding, $toEncoding, $content );
		}
		return $content;
	} // convertEncoding }}}


	/**
	 * Displays an hex dump of the passed string, used for debug
	 * 
	 * @param	string $data
	 * @param	string $newline
	 * @return	void
	 */
	public static function hexDump( $data, $newline = "\n" )
	{
		static $from = '';
		static $to = '';
		static $width = 16; // number of bytes per line
		static $pad = '.'; // padding for non-visible characters
		

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


	/**
	 * Instanciates a SimpleXMLElementXT from the supplied xml string
	 * 
	 * @param	string $xml
	 * @return	SimpleXMLElementXT
	 */
	public static function XMLObj( $xml )
	{
		if (! is_string( $xml )) {
			throw new InvalidArgumentException( '$xml must be a string' );
		}
		
		$xml = ($xml[0] !== '<') ? "<$xml></$xml>" : $xml;
		return new SimpleXMLElementXT( $xml, LIBXML_NOXMLDECL );
	} // XMLObj }}}


	/**
	 * Converts any given object to json
	 * 
	 * @param	mixed $obj
	 * @return	string
	 */
	public static function toJSON( $obj )
	{
		return json_encode( $obj );
	} // toJSON }}}


	/**
	 * Serializes any given object
	 * 
	 * @param	mixed $obj
	 * @return	string
	 */
	public static function toPHPS( $obj )
	{
		return serialize( $obj );
	} // toPHPS }}}
} // SASUA_Canteens_Utility }}}


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
	
	protected $_tag = 'menus';


	public function __construct( $zone, $type )
	{
		parent::__construct();
		$this->zone = $zone;
		$this->type = $type;
	} // __construct }}}


	public function add( $canteen, $meal, $date )
	{
		$child = new SASUA_Canteens_Menu( $this, $canteen, $meal, $date );
		$this->_children[] = $child;
		return $child;
	} // add }}}


	public function asXML( $xmlDecl = false, $encoding = 'utf-8' )
	{
		$xml = '';
		foreach ( $this->_children as $c ) {
			$xml .= $c->asXML();
		}
		$xml = "<{$this->_tag}>$xml</{$this->_tag}>";
		
		$xmlObj = SASUA_Canteens_Utility::XMLObj( $xml );
		
		$xmlObj->addAttribute( 'zone', $this->zone );
		$xmlObj->addAttribute( 'type', $this->type );
		
		return $xmlObj->document( null, array( 
			'xml_declaration' => $xmlDecl, 
			'encoding' => $encoding 
		) );
	} // asXML }}}


	public function asObj()
	{
		$obj = parent::asObj();
		$obj->zone = $this->zone;
		$obj->type = $this->type;
		$obj->{$this->_tag} = array();
		foreach ( $this->_children as $c ) {
			$obj->{$this->_tag}[] = $c->asObj();
		}
		
		return $obj;
	} // asObj }}}
} // SASUA_Canteens_Menus }}}


/**
 * Auxiliary class for building canteen menus
 *
 * @package     SASUA_Canteens
 * @subpackage  SASUA_Canteens::Menus
 */
class SASUA_Canteens_Menu extends SASUA_Canteens_Menu_Object
{
	public $canteen;
	public $meal;
	public $date;
	public $weekday;
	public $weekdayNr;
	public $disabled = false;
	
	public $_tag = 'menu';
	public $_tagItems = 'items';


	public function __construct( $parent = null, $canteen, $meal, $date )
	{
		parent::__construct( $parent );
		if (empty( $this->_tagItems )) {
			throw new Exception( 'tagItems property cannot be empty' );
		}
		
		$this->canteen = $canteen;
		$this->meal = $meal;
		$this->date = $date;
		
		$tmp = strtotime( $date );
		$this->weekday = date( 'l', $tmp );
		$this->weekdayNr = date( 'w', $tmp );
	} // __construct }}}


	public function add( $name, $content )
	{
		$child = new SASUA_Canteens_Menu_Item( $this, $name, $content );
		$this->_children[] = $child;
		return $child;
	} // add }}}


	public function disable( $reason = null )
	{
		if (empty( $reason ) && $reason !== 0) {
			$reason = 1;
		}
		$this->disabled = $reason;
	} // disable }}}


	public function asXML( $xmlDecl = false, $encoding = 'utf-8' )
	{
		$xml = '';
		if (! $this->disabled) {
			foreach ( $this->_children as $c ) {
				$xml .= $c->asXML();
			}
		}
		
		$xml = "<{$this->_tag}><{$this->_tagItems}>$xml</{$this->_tagItems}></{$this->_tag}>";
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
	} // asXML }}}


	public function asObj()
	{
		$obj = parent::asObj();
		$obj->canteen = $this->canteen;
		$obj->meal = $this->meal;
		$obj->date = $this->date;
		$obj->weekday = $this->weekday;
		$obj->weekdayNr = $this->weekdayNr;
		$obj->disabled = $this->disabled;
		$obj->{$this->_tagItems} = array();
		if (! $this->disabled) {
			foreach ( $this->_children as $c ) {
				$obj->{$this->_tagItems}[] = $c->asObj();
			}
		}
		return $obj;
	} // asObj }}}
}

/**
 * Auxiliary class for building canteen menus
 *
 * @package     SASUA_Canteens
 * @subpackage  SASUA_Canteens::Menus
 */
class SASUA_Canteens_Menu_Item extends SASUA_Canteens_Menu_Object
{
	public $name;
	public $content;
	
	protected $_tag = 'item';


	public function __construct( $parent = null, $name, $content )
	{
		parent::__construct( $parent );
		$this->name = $name;
		$this->content = $content;
	} // __construct }}}


	public function asXML( $xmlDecl = false, $encoding = 'utf-8' )
	{
		$xmlObj = SASUA_Canteens_Utility::XMLObj( $this->_tag );
		$xmlObj->{0} = $this->content;
		$xmlObj->addAttribute( 'name', $this->name );
		
		return $xmlObj->document( null, array( 
			'xml_declaration' => $xmlDecl, 
			'encoding' => $encoding 
		) );
	} // asXML }}}


	public function asObj()
	{
		$obj = parent::asObj();
		$obj->name = $this->name;
		$obj->content = $this->content;
		
		return $obj;
	} // asObj }}}
} // SASUA_Canteens_Menu_Item }}}


/**
 * Base abstract class for all menu building classes
 *
 * @package     SASUA_Canteens
 * @subpackage  SASUA_Canteens::Menus
 */
abstract class SASUA_Canteens_Menu_Object
{
	protected $_tag;
	protected $_parent;
	protected $_children = array();


	public function __construct( $parent = null )
	{
		if (empty( $this->_tag )) {
			throw new Exception( 'tag property cannot be empty' );
		}
		$this->_parent = $parent;
	} // __construct }}}


	abstract public function asXML( $xmlDecl = false, $encoding = 'utf-8' );


	public function asXMLObj()
	{
		return SASUA_Canteens_Utility::XMLObj( $this->_tag );
	} // asXMLObj }}}


	public function asObj()
	{
		return new stdClass();
	} // asObj }}}


	public function asJSON()
	{
		return SASUA_Canteens_Utility::toJSON( $this->asObj() );
	} // asJSON }}}


	public function asPHPS()
	{
		return SASUA_Canteens_Utility::toPHPS( $this->asObj() );
	} // asPHPS }}}


	public function asFormat( $format )
	{
		switch (strtolower( $format )) {
		default:
		case 'xml':
			return $this->asXML();
		case 'json':
			return $this->asJSON();
		case 'phps':
			return $this->asPHPS();
		}
	} // asFormat }}}
} // SASUA_Canteens_Menu_Object }}}


######################################################################################################
######################################################################################################


/**
 * Exception classes
 */
class HTTPFetchException extends Exception
{
} // HTTPFetchException }}}


class PHPMissingRequirementException extends Exception
{
} // PHPMissingRequirementException }}}


class InvalidRequestException extends Exception
{
} // InvalidRequestException }}}


######################################################################################################
######################################################################################################


/**
 * Extension of SimpleXMLElement
 *
 * @package     SASUA_Canteens
 */
class SimpleXMLElementXT extends SimpleXMLElement
{


	/**
	 * Enter description here ...
	 * 
	 * @param	string $filename	optional
	 * @param	array $options		optional
	 * @return	string|bool
	 */
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
} // SimpleXMLElementXT }}}


?>
