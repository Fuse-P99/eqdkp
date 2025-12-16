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

if( !defined( 'EQDKP_INC' ) ) {
	die( 'Do not access this file directly.' );
}

if( !class_exists( "plus_exchange" ) ) {
	class plus_exchange extends gen_class {

		//module lists
		public $modules					= array();
		public $feeds					= array();
		private $modulepath				= 'core/exchange/';
		private  $isCoreAPIToken		= false;
		private $isReadOnlyToken		= false;

		//Constructor
		public function __construct( ) {
			$this->scan_modules();
		}

		public function register_module($module_name, $module_dir, $class_params=array()){
			//create object
			$module = 'exchange_'.$module_name;
			if (!is_file($this->root_path.$module_dir.'.php')) return false;
			include($this->root_path.$module_dir.'.php');
			$class = register($module, $class_params);
			$this->modules[$module_name] = array(
				'path'			=> $module_dir,
				'class_params'	=> $class_params,
			);
			return true;
		}

		public function register_feed($feed_name, $feed_url, $plugin_code = 'eqdkp'){
			$this->feeds[$feed_name] =  array('url'	=> $feed_url, 'plugin' => $plugin_code);
		}


		private function scan_modules(){
			$m_path = $this->root_path.$this->modulepath;

			//Scan "local" modules
			if($dh = opendir($m_path)){
				while(false !== ($file = readdir($dh))){
					if($file[0] !== '.' && !is_dir($m_path.$file)){
						$filename = pathinfo($file, PATHINFO_FILENAME);
						$this->register_module($filename, $this->modulepath.$filename);
					}
				}
				closedir($dh);
			}

			//Plugins
			$plugs = $this->pm->get_plugins(PLUGIN_INSTALLED);
			if(is_array($plugs)){
				foreach($plugs as $plugin_code){
					$plugin = $this->pm->get_plugin($plugin_code);
					foreach($plugin->get_exchange_modules() as $module_name){
						$this->register_module($module_name, 'plugins/'.$plugin_code.'/exchange/'.$module_name);
					}
					foreach($plugin->get_exchange_modules(true) as $module){
						$this->register_feed($module['name'], $module['url'], $plugin_code);
					}
				}
			}
			
			//Portal modules
			$layouts = $this->pdh->get('portal_layouts', 'id_list');
			$module_ids = array();
			foreach($layouts as $layout_id){
				$modules = $this->pdh->get('portal_layouts', 'modules', array($layout_id));
				foreach($modules as $position => $module){
					$module_ids += array_flip(array_flip($module)); // Remove duplicates efficiently
				}
			}

			foreach($module_ids as $module_id){
				$path = $this->pdh->get('portal', 'path', array($module_id));
				$obj = $path.'_portal';
				if(class_exists($obj) && $this->portal->check_visibility($module_id)){
					$arrExchangeModules = $obj::get_data('exchangeMod');
					$plugin = $this->pdh->get('portal', 'plugin', array($module_id));
					$base_path = ($plugin != '') ? 'plugins/'.$plugin : 'portal/'.$path;
					foreach($arrExchangeModules as $module_name){
						$this->register_module($module_name, $base_path.'/exchange/'.$module_name, array($module_id));
					}
				}
			}
		}

		public function execute(){
			//Get all Arguments
			$request_method = $_SERVER['REQUEST_METHOD'];
			$request_body = file_get_contents("php://input");

			$request_args['get'] = $_GET;
			$request_args['post'] = $_POST;
			$arrBody = $this->parseRequestBody($request_body);

			// Parse body-based arguments once
			if($request_body){
				parse_str($request_body, $request_args['put']);
				parse_str($request_body, $request_args['delete']);
			}

			$this->authenticateUser();

			$function = $request_args['get']['function'];
			$out = $this->error('function not found');

			if(isset($this->modules[$function])){
				include ($this->root_path.$this->modules[$function]['path'].'.php');
				$module = 'exchange_'.$function;
				$class = register($module, $this->modules[$function]['class_params']);
				$method = strtolower($request_method).'_'.$function;

				if (method_exists($class, $method)){
					$out = $class->$method($request_args, $arrBody);
				}
			}

			$format = $request_args['get']['format'] ?? 'xml';
			return $this->formatResponse($out, $format, $request_args);
		}

		public function error($strErrorMessage, $arrInfo=array()){
			$out = array(
				'status'	=> 0,
				'error'		=> $strErrorMessage,
			);
			if(count($arrInfo)){
				$out['info'] = $arrInfo;
			}

			return $out;
		}

		private function parseRequestBody($request_body){
			$arrBody = array();

			if(!$request_body){
				return $arrBody;
			}

			// Try JSON first (faster, no double conversion)
			$arrBody = json_decode($request_body, true);
			if($arrBody !== null){
				return $arrBody;
			}

			// Fall back to XML
			$xml = simplexml_load_string($request_body, "SimpleXMLElement", LIBXML_NOCDATA);
			if($xml){
				$arrBody = json_decode(json_encode($xml), TRUE);
			}

			return $arrBody;
		}

		private function setResponseStatus(&$arrData){
			if (!isset($arrData['status']) || $arrData['status'] != 0){
				$arrData['status'] = 1;
			}
		}

		private function formatResponse($arrData, $format, $arrRequestArgs){
			$this->setResponseStatus($arrData);

			switch($format){
				case 'json':
					return json_encode($arrData);
				case 'lua':
					return $this->returnLua($arrData, $arrRequestArgs);
				default:
					return $this->returnXML($arrData);
			}
		}

		private function returnXML($arrData){
			if (!is_array($arrData)){
				$arrData = $this->error('unknown error');
			}

			$xml_array = $this->xmltools->array2simplexml($arrData, 'response');
			$dom = dom_import_simplexml($xml_array)->ownerDocument;
			$dom->encoding='utf-8';
			$dom->formatOutput = true;
			return trim($dom->saveXML());
		}

		private function returnLua($arrData, $arrRequestArgs){
			static $luaParser = null;
			
			if($luaParser === null){
				include_once($this->root_path."libraries/lua/parser.php");
				$one_table = !(isset($arrRequestArgs['get']['one_table']) && $arrRequestArgs['get']['one_table'] == "false");
				$luaParser = new LuaParser($one_table);
			}
			return $luaParser->array2lua($arrData);
		}

		private function authenticateUser(){
			$strToken = $this->getTokenFromRequest();

			if($strToken){
				// Check admin tokens
				if($strToken === $this->config->get('api_key')){
					$this->isCoreAPIToken = true;
					return $this->setSuperadminSession();
				} elseif($strToken === $this->config->get('api_key_ro')){
					$this->isCoreAPIToken = true;
					$this->isReadOnlyToken = true;
					return $this->setSuperadminSession();
				}

				// It's a user token
				$intUserID = $this->user->getUserIDfromDerivedExchangekey($strToken, 'pex_api');
				$this->user->changeSessionUser($intUserID);
				return $intUserID;
			}

			//User not authenticated, check hooks
			if($this->hooks->isRegistered('pex_authenticate_user')){
				return $this->hooks->process('pex_authenticate_user', $this->user->id, true);
			}

			return $this->user->id;
		}

		private function getTokenFromRequest(){
			if($this->in->exists('atoken') && strlen($this->in->get('atoken'))){
				return $this->in->get('atoken');
			}

			$headers = $this->getAuthorizationHeader();
			if(!$headers){
				return '';
			}

			if(strpos($headers, 'token') !== false){
				parse_str($headers, $arrToken);
				return isset($arrToken['token']) ? $arrToken['token'] : "";
			}

			return trim($headers);
		}

		private function setSuperadminSession(){
			$arrSuperAdmins = $this->pdh->get('user_groups_users', 'user_list', array(2));
			if(empty($arrSuperAdmins)){
				return null;
			}

			$intSuperadminID = reset($arrSuperAdmins);
			if($intSuperadminID){
				$this->user->changeSessionUser($intSuperadminID);
				return $intSuperadminID;
			}
			return null;
		}

		private function getAuthorizationHeader(){
			$headers = null;
			if (isset($_SERVER['Authorization'])) {
				$headers = trim($_SERVER["Authorization"]);
			}elseif (isset($_SERVER['HTTP_X_CUSTOM_AUTHORIZATION'])){
				$headers = trim($_SERVER['HTTP_X_CUSTOM_AUTHORIZATION']);
			}
			else if (isset($_SERVER['HTTP_AUTHORIZATION'])) { //Nginx or fast CGI
				$headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
			} elseif (function_exists('apache_request_headers')) {
				$requestHeaders = apache_request_headers();
				// Server-side fix for bug in old Android versions (a nice side-effect of this fix means we don't care about capitalization for Authorization)
				$requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
				//print_r($requestHeaders);
				if (isset($requestHeaders['Authorization'])) {
					$headers = trim($requestHeaders['Authorization']);
				} elseif(isset($requestHeaders['HTTP_X_CUSTOM_AUTHORIZATION'])) {
					$headers = trim($requestHeaders['HTTP_X_CUSTOM_AUTHORIZATION']);
				}
			}
			return $headers;
		}

		public function getIsApiTokenRequest(){
			return $this->isCoreAPIToken;
		}
		
		public function isApiWriteTokenRequest(){
			return ($this->isCoreAPIToken && !$this->isReadOnlyToken);
		}
		
		public function isApiReadonlyTokenRequest(){
			return ($this->isCoreAPIToken && $this->isReadOnlyToken);
		}
		
	}//end class
} //end if
