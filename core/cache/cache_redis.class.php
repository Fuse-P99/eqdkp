<?php
/*	Project:	EQdkp-Plus
 *	Package:	EQdkp-plus
 *	Link:		http://eqdkp-plus.eu
 *
 *	Copyright (C) 2006-2016 EQdkp-Plus Developer Team
 *
 *	This program is free software: you can redistribute it and/or modify
 *	it under the terms of the GNU Affero General Public License as published
 *	by the Free Software Foundation, either version 3 of the License, or
 *	(at your option) any later version.
 *
 *	This program is distributed in the hope that it will be useful,
 *	but WITHOUT ANY WARRANTY; without even the implied warranty of
 *	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *	GNU Affero General Public License for more details.
 *
 *	You should have received a copy of the GNU Affero General Public License
 *	along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

if ( !defined('EQDKP_INC') ) {
	die('Do not access this file directly.');
}

if ( !interface_exists( "plus_datacache" ) ) {
	require_once($eqdkp_root_path . 'core/cache/cache.iface.php');
}

if ( !class_exists( "cache_redis" ) ) {
	class cache_redis extends gen_class implements plus_datacache{

		public $server = 'localhost';
		public $redis;

		public function __construct(){
			if(!class_exists('Redis')){
				throw new Exception('No Redis available');
			}

			$this->redis = new Redis();

			$intPort = ($this->config->get('port', 'pdc') === false) ? 6379 : $this->config->get('port', 'pdc');

			$blnConnectionResult = $this->redis->connect($this->config->get('server', 'pdc'), $intPort);
			if(!$blnConnectionResult){
				throw new Exception('No connection to redis server');
			}

			$strPrefix = substr(md5(registry::get_const('dbname')), 0, 8);

			$this->redis->setOption(\Redis::OPT_PREFIX, $strPrefix.':');
		}
		/* JCH - AI - 
		public function put( $key, $data, $ttl, $global_prefix, $compress = false ) {
			$key = $global_prefix.$key;


			return $this->redis->setex($key, $ttl, serialize($data));
		}

		public function get( $key, $global_prefix, $uncompress = false ) {
			$key = $global_prefix.$key;


			$retval = $this->redis->get($key);
			return ($retval === false) ? null : @unserialize_noclasses($retval);
		}
		*/
				public function put( $key, $data, $ttl, $global_prefix, $compress = false ) {
					$key = $global_prefix.$key;
					$serialized = serialize($data);
					// Performance: Always compress objects >10KB, log compression ratio
					if ($compress || strlen($serialized) > 10240) {
						$gzipped = gzcompress($serialized, 6);
						$data_to_store = 'GZIP:' . $gzipped;
						// Log compression ratio for monitoring
						if (function_exists('error_log')) {
							$ratio = (strlen($gzipped) / strlen($serialized));
							$savings = 100 - round($ratio * 100);
							error_log("Redis cache: key=$key, original=".strlen($serialized).", compressed=".strlen($gzipped).", ratio=$ratio, savings=$savings%");
						}
						// If compression fails or is larger, fallback to original
						if (strlen($gzipped) >= strlen($serialized)) {
							$data_to_store = $serialized;
						}
					} else {
						$data_to_store = $serialized;
					}
					// Store in Redis
					try {
						return $this->redis->setex($key, $ttl, $data_to_store);
					} catch (Exception $e) {
						error_log('Redis cache set error: ' . $e->getMessage());
						return false;
					}
				}

                public function get( $key, $global_prefix, $uncompress = false ) {
                    $key = $global_prefix.$key;
    
                    try {
                        $retval = $this->redis->get($key);
                        if ($retval === false) return null;
        
                        if (strpos($retval, 'GZIP:') === 0) {
                            $retval = gzuncompress(substr($retval, 5));
                            if ($retval === false) {
                                error_log('Redis decompression failed for key: ' . $key);
                                return null;
                            }
                        }
                        return unserialize_noclasses($retval);
                    } catch (Exception $e) {
                        error_log('Redis cache get error: ' . $e->getMessage());
                        return null;
                    }
		}
		public function del( $key, $global_prefix ) {
			$key = $global_prefix.$key;
			$this->redis->del($key);
			return true;
		}

		public function get_cachesize($key, $global_prefix){
			$key = $global_prefix.$key;
			$size = $this->redis->strlen($key);
			return ($size !== false) ? $size : 0;
			//return 0;
		}

		public function debug_dump_keys($pattern = '*') {
		    try {
		        return $this->redis->keys($pattern);
		    } catch (Exception $e) {
		        return ['Error: ' . $e->getMessage()];
		    }
		}


	}//end class
}//end if
