<?php
//define('HEAVY_DEBUG',1);
require_once('db.php');
require_once(constant('APP_DIR').'/qry5.php');

if (!defined('DATE_MYSQL'))  {
define('DATE_MYSQL', "Y-m-d H:i:s");
} 
if (!defined('DATE_HTML5_SHORT'))  {
define('DATE_HTML5_SHORT', "Y-m-d");
}

if (!class_exists('SINGLETON')) {
	class SINGLETON {
		/* Singleton Pattern */
		private static $me;
		private function __construct() { }
		public static function getInstance() {
			if (!isset(self::$me)) {
				$c = __CLASS__;
				self::$me = new $c;
			}
			return self::$me;
		}
	}
}


class ORM extends SINGLETON {

	private static $tables = array();

	private static $cache;
	private static $db;

	private static $loader_cache = array();

	private static $mapping_cache = array();
	private static $static_cache = array();
	private static $object_cache = array();

	public static function loadModels($directory = 'models', $regexp = '.*', $pattern = '*.php') {
		$ret = array();
		$listing = glob(rtrim($directory,'/').'/' . $pattern);
		foreach ($listing as $fullname) {
			$filename = substr($fullname, strlen($directory)+1);
			if (preg_match('#'.$regexp.'#', $filename)) {
				include_once ($fullname);
				$ret[] = preg_replace('/.php$/', '', basename($fullname));
			}
		}
		return $ret;
	}

	public static function getDB() {
		if (!self::$db) self::$db = db_init();
		return self::$db;
	}

	public static function useDatabase($db) {
		self::$db = $db;
	}

	public static function getTable($model) {
		if (!isset($tables[$model])) {
			$ref = ORM::get_reflection($model);
			$tables[$model] = self::getDB()->_prefix(). $ref['table'];
		}
		return $tables[$model];
	}

	public static function uniqueId() {
		static $counter = 0;
		$counter++;
		return $counter;
	}

	public static function Loader($id) {
		if (isset(self::$loader_cache[$id]))
			return self::$loader_cache[$id];
		$ctx = new ORM_Loader();
		self::$loader_cache[$ctx->unique_id] = $ctx;
		return $ctx;
	}

	public static function Filter($model = null) {
		$q = new QRY();
		return $q;
	}

	public static function Collection($model, $filter = null, $load = -1) {
		$db = self::getDB();
		$loader_ctx = null;
		if (is_object($load)) { $loader_ctx = $load; $load = 0; }
		else $loader_ctx = new ORM_Loader();
		$col = new ORM_Collection($model, $filter, $loader_ctx);
		if ($load) $col->load( null, null, (int)$load );
		return $col;
	}

	public static function objectCacheAsLoader() {
		static $loader = null;
		if ($loader == null) {
			$loader = new ORM_Loader();
		}
		$loader->hackFill(self::$object_cache);
		return $loader;
	}

	public static function Cache(&$obj) {
		if (!is_object($obj)) throw new Exception("Must be an object");
		$name = get_class($obj);
		self::$object_cache[$name][$obj->id()] =& $obj;
	}

	public static function Sync($classes = null, $dry = false) {
		$db = self::getDB();//Ensure db is on
		_debug_log("Sync all tables...");
		if (is_string($classes)) $classes = array($classes);
		if (!$classes) $classes = get_declared_classes();
		$ret = array();
		foreach ($classes as $c) {
			if (!is_subclass_of($c, 'ORM_Model')) continue;
			/*if (is_callable(array($c, 'SYNC'))) {
				$f = new $c;
				call_user_func(array($f, 'SYNC'), $db);
				continue;
			}*/
			$class_table = self::getTable($c);
			$ref = self::get_reflection($c);

			$ret[$c] = $db->sync_table($class_table, $ref['fields'], $dry);
		}
		return $ret;
	}

	private static function rrmdir($dir) {
		if (!is_dir($dir)) return;
		$objects = scandir($dir);
		foreach ($objects as $object) {
			if ($object == "." || $object == "..") continue;
			if (filetype($dir."/".$object) == "dir") self::rrmdir($dir."/".$object);
			else unlink($dir."/".$object);
		}
		reset($objects); rmdir($dir);
	}


	public static function ExportTGZ($classes = null, $filename = 'archive-$date') {

		$filename = str_replace('$date', date('DdFY-Hi'), $filename);

		$tmp_root = sys_get_temp_dir();
		if (file_exists('tmp') && is_dir('tmp')) $tmp_root = 'tmp';

		$tmp_dir =  $tmp_root.'/'. $filename.rand(0,10000).time();
		$tar_file = $tmp_root.'/'. $filename.'.tar';
		$gz_file = $tmp_root.'/'. $filename.'.tar.gz';
		$db_dir = $tmp_dir . '/db';
		$payload_dir = $tmp_dir . '/payload';

		mkdir($tmp_dir);
		mkdir($db_dir);
		mkdir($payload_dir);
		
		if (is_string($classes)) $classes = array($classes);
		if (!$classes) $classes = get_declared_classes();
		foreach ($classes as $c) {
			if (!is_subclass_of($c, 'ORM_Model')) continue;

			$ref = ORM::get_reflection($c);
			$file = $ref['table'].'.xml';
			$payload = array();
			file_put_contents($db_dir.'/'.$file, ORM::Collection($c, null, 1)->toXML(null, $payload));
			foreach ($payload as $file) {
				$fileinfo = dirname($file['dst']);
				if (!file_exists($payload_dir.'/'.$fileinfo)) {
					mkdir($payload_dir.'/'.$fileinfo, 0777, true);
				}
				copy($file['src'], $payload_dir.'/'.$file['dst']);
				//er("Copy", 				$file['src'], $payload_dir.'/'.$file['dst']);
			}			
		}		

		if (file_exists($tar_file)) unlink($tar_file);
		$phar = new PharData($tar_file);
		$phar->buildFromDirectory($tmp_dir);

		if (file_exists($gz_file)) unlink($gz_file);
		$phar->compress(Phar::GZ);

		self::rrmdir($tmp_dir);
		unlink($tar_file);

		return $gz_file;
	}

	public static function ImportDIR($dir) {

		$db_dir = $dir . '/db';
		$payload_dir = $dir . '/payload';

		$db_files = glob($db_dir . '/*.xml');
    
		foreach ($db_files as $db_file) {

			$xmlstr = file_get_contents($db_file);
			$entries = new SimpleXMLElement($xmlstr);
			$class = $entries->children()->getName();

			if ($entries->count() == 0) continue;

			ORM::FixClear($class);
			$objects = ORM::Collection($class, null, false);
			$objects->loadFrom($entries->{$class});

			foreach ($objects as $object) {
				if (isset($object->payload)) {
					$unfiltered_payload = $object->payload->file;
					unset($object->payload);
					$payload = $object->onImport($unfiltered_payload);
					foreach($payload as $file) {
						$fileinfo = dirname($file['dst']);
						if (!file_exists($fileinfo))
							mkdir($fileinfo, 0777, true);
						if (file_exists($file['dst']))
							unlink($file['dst']);
						if (!rename($payload_dir.'/'.$file['src'], $file['dst'])) {
							//failed;
						}
						//er("Copy", 	$fileinfo, $payload_dir.'/'.$file['src'],			$file['dst']);					
					}
				}
			}
			$objects->save();
		}
	}

	public static function ImportTGZ($filename) {

		$tmp_root = sys_get_temp_dir(); 
		if (file_exists('tmp') && is_dir('tmp')) $tmp_root = 'tmp';

		$tmp_dir = $tmp_root.'/'. str_replace('.','',basename($filename)).rand(0,10000).time();

		mkdir($tmp_dir);

		$phar = new PharData($filename);
		$phar->extractTo($tmp_dir); // extract all files

		ORM::ImportDIR($tmp_dir);

		// Cleanup
		self::rrmdir($tmp_dir);
	}

	public static function Destroy($class) {
		$db = self::getDB();
		$table = self::getTable($class);
		_debug_log("ORM Drop: ".$class);
		$db->set("DROP TABLE ".$table, array());
	}
	public static function FixClear($class) {
		$db = self::getDB();
		$table = self::getTable($class);
		_debug_log("ORM Truncate: ".$class);
		$db->set("TRUNCATE ".$table, array());
	}
	public static function FixInsert($array) {
		$db = self::getDB();//Ensure db is on
		foreach ($array as $class => $entries) {
			foreach ($entries as $entry) {
				$obj = self::Model($class);
				foreach ($entry as $key=>$val) {
					$obj->$key = $val;
				}
				//$obj->assemble2();
				$obj->save();
				//print_r( $obj->save() );
				//$db->run( $obj->save() );
			}
		}
	}

	public static function Model($name, $id = null, $depth = -1, $loader_ctx = null) {
		//_debug_log("Loading model... $name");
		if (!$loader_ctx) $loader_ctx = ORM::objectCacheAsLoader();
		$obj = null;

		if (isset($id)) {
			$ref = ORM::get_reflection($name);
			$obj = $loader_ctx->queryCache($name, $ref['primary'], $id); 		

			if (!$obj) {

				$loader_ctx->load($name, array($ref['primary'] => $id), $depth);
				$obj = $loader_ctx->queryCache($name, $ref['primary'], $id);

				if ($obj) {
					$loader_ctx->assembleAll($name, $depth);
					//$loader_ctx->assembleOne($obj, $depth);//$obj, $depth);
				}
			}
		} else {

			$obj = new $name(false);

		}
		return $obj;
	}

	public static function ListPeers($object) {
		$class = get_class($object);
		$props = array();
		_debug_log("Listing peers for ".$class);
		if (isset($class::$has_one)) {
			foreach($class::$has_one as $field => $config) {
				if ($config === true) $config = array($name);
				if (isset($config[0])) $subclass = $config[0];
				else list($subclass) = each($config);

				$name_prop = 'name';
				//if (!isset($one->{$name_prop})) $name_prop = 'title';

				$col = ORM::Collection($subclass);
				$arr = array();
				foreach ($col as $one) {
					$arr[$one->id()] = $one->$name_prop;
				}
				//$peersprop = $field . '_peers';
				//$object->$peersprop = $arr;
				$props[$field] = $arr;
			}
		}
		if (isset($class::$has_many_via)) {
			foreach ($class::$has_many_via as $field => $config) {
				$has_class = key($config);

				$name_prop = 'name';

				$col = ORM::Collection($has_class);
				$arr = array();
				foreach ($col as $one) {
					$arr[$one->id()] = $one->$name_prop;
				}

				$props[$field] = $arr;
			}
		}
		_debug_log("Peers listed");
		return sizeof($props) == 0 ? FALSE : $props;
	}

	public static function GetOld($object, $type = 'slowload') {
		$class = get_class($object);
		$old = array();

		$map = ORM::Map($class);

		if (!isset($map[$type])) throw new Exception("Undefined ORM-link type `$type`");
		foreach ($map[$type] as $link) {
			$name = $link['property'];
			er($link, $object);
			$old[$name] = (property_exists($object, $name) ? $object->{$name} : null);
		}
		return $old;
	}

	public static function array_diff_obj($arr1, $arr2) {
		if (!function_exists('compare_objects')) {
		function compare_objects($obj_a, $obj_b) {
		  return $obj_a->id() - $obj_b->id();
		} }
		return array_udiff($arr1, $arr2, 'compare_objects');
	}

	public static function BreakDown($object, $old) {
		$class = get_class($object);
		$ref = ORM::get_reflection($class, $tmp, $object);

		$map = ORM::Map($class);

		if ($map['slowload']) {
			foreach ($map['slowload'] as $config) {

				$name = $config['property'];
				$has_class = $config['class'];
				$via_class = $config['via_class'];
				$local_field = $config['left'];
				$remote_field = $config['middle'];
				$remote_hasmany = $config['right'];
				$remote_property = $config['via_property'];

				$remote_obj = null;
				$vmap = ORM::Map($via_class);
			er("Has Many Via", $config);
			er("VMAP:", "for ".$via_class, $vmap);
				if ($config['single']) {
					foreach ($vmap['autojoin'] as $vconfig) {
						if ($vconfig['left'] == $config['middle']) {
							$remote_obj = $vconfig['property'];
							break;
						}
					}
				} else {
					foreach ($vmap['fastload'] as $vconfig) {
					er("Compare", $vconfig['right'], "with", $config['right']);
						if ($vconfig['right'] == $config['right']) {
							$remote_obj = $vconfig['property'];
							break;
						}
					}
				}
				if (!$remote_obj) throw new Exception("no autojoin property `$remote_field` in Map($via_class)");
				if (!isset($object->$name)) continue;
			//er("OLD", $old[$name]);
			//er("NEW", $object->$name);

				$to_rem = self::array_diff_obj($old[$name], $object->$name);
				$to_add = self::array_diff_obj($object->$name, $old[$name]);
			er("TO ADD:", $to_add);
			er("TO REM:", $to_rem);

				$ref2 = self::get_reflection($via_class);
				$ref3 = self::get_reflection($has_class);

				foreach ($to_rem as $obj) {
					//er("MUST DELETE", $oldobj);

					//er("USING MANY VIA", $class, $name, $has_class, $via_class, $local_field, $remote_field, $remote_hasmany);


					$power_keys = $ref2['foreign'][$has_class];

					$power_key = key($power_keys);
					$power_rkey = current($power_keys);

					//er($power_keys);

					//er("MUST DELETE $via_class WHERE $remote_field = ".$object->$local_field." AND $power_key = this.$local_field ".$oldobj->$power_rkey);

					$col = ORM::Collection($via_class,
						array($remote_field => $object->$local_field,
							$power_key => $obj->$power_rkey,
						));
					$delete_obj = $col->one();
					$delete_obj->delete();

				}

				foreach ($to_add as $obj) {

					//er("OBJ:", $obj);

					//er("REF", $ref, "REF2", $ref2, "REF3", $ref3);
					//er("HAS MANY VIA", $class, $name, $has_class, $via_class, $local_field, $remote_field, $remote_hasmany);

					$power_keys = $ref2['foreign'][$has_class];

					$power_key = key($power_keys);
					$power_rkey = current($power_keys);

					$col = ORM::Collection($via_class, 
						array($power_key => $obj->$power_rkey,
								$remote_field => $object->$local_field) , false);
					//$col->using($object);
					$col->load();
					$doc = $col->one();

					if (!$doc)
						$doc = new $via_class();

					$doc->$remote_field = $object->$local_field;
					$doc->$remote_obj = $object;
					//$doc->$remote_hasmany = $obj;
					$doc->$remote_property = $obj;

					$doc->save();
				}
			}
		}
	}

	public static function Wrap($object) {
		$object->tear();
	}

	public static function Map($class = null) {
		static $class_list = null;
		static $load_all = true;
		if ($class_list === null) {
			$class_list = array();
			$all = get_declared_classes();
			foreach ($all as $pclass)
				if (is_subclass_of($pclass, 'ORM_Model'))
					$class_list[] = $pclass;
		}
		if ($class === null) {
			if ($load_all) {
				foreach ($class_list as $class)
					ORM::Map($class);
				$load_all = false;
			}
			return self::$mapping_cache;
		}
		if (!class_exists($class)) {
			throw new Exception('No such class '.$class);
		}
		if (!isset(self::$mapping_cache[$class])) {

			$obj = array(
			    'auto' => array(),
				'autojoin' => array(),
				'fastload' => array(),
				'slowload' => array(),
				'hasonevia' => array(),
			);

			/* AUTO-FIELDS */
			$auto = array();
			if (isset($class::$_auto)) {
				$auto = $class::$_auto;
			}
			$parent_class = $class;
			while (($parent_class = get_parent_class($parent_class)) != 'ORM_Model') {
				if (isset($parent_class::$_auto))
					$auto = array_merge($parent_class::$_auto, $auto);
			}
			$obj['auto'] = $auto;

			/* BELONGS TO */
			if (isset($class::$belongs_to)) {
				foreach($class::$belongs_to as $field => $config) {
					if (is_numeric($field)) {
						$field = $config;
						$config = true;
					}
					if (is_string($config)) $config = array($config, null, null);
					if ($config === true) $config = array($field, null, null);
					$subclass = $config[0];
					$left_key = isset($config[1]) ? $config[1] : null;
					$right_key = isset($config[2]) ? $config[2] : null;

					$ref = self::get_reflection($class);
					$ref2 = self::get_reflection($subclass);

					$subtable = $ref2['table'];

					if (!$left_key || !$right_key) {
						if (isset($ref['foreign'][$subtable])) {
							list($auto_left_key, $auto_right_key) = each($ref['foreign'][$subtable]);
						} else {
							$auto_left_key = strtolower($class).'_id';
							$auto_right_key = $ref2['primary'];
						}
						$left_key = $left_key ? $left_key : $auto_left_key;
						$right_key = $right_key ? $right_key : $auto_right_key;
					}

					$obj['autojoin'][] = array(
						'property' => $field,
						'class' => $subclass,
						'table' => $subtable,
						'left' => $left_key,
						'right' => $right_key,
						'relation' => 'slave',
					);
				}
			}

			/* HAS ONE */
			if (isset($class::$has_one)) {
				foreach($class::$has_one as $field => $config) {
					if ($config === true) $config = array($name);
					if (isset($config[0])) $subclass = $config[0];
					else list($subclass) = each($config);

					$ref = self::get_reflection($class);
					$ref2 = self::get_reflection($subclass);
					$rel = 'slave';

					if (!$ref['primary']) $rel = 'master';

					if (isset($ref['foreign'][$ref2['table']])) {
						list($left_key, $right_key) = each($ref['foreign'][$ref2['table']]);
					} else if (isset($ref2['foreign'][$ref['table']])) {
						list($right_key, $left_key) = each($ref2['foreign'][$ref['table']]);
						$rel = 'master';
					} else {
						$left_key = strtolower($class).'_id';
						$right_key = $ref2['primary'];
					}

					$obj['autojoin'][] = array(
						'property' => $field,
						'class' => $subclass,
						'table' => $ref2['table'],
						'left' => $left_key,
						'right' => $right_key,
						'relation' => $rel,
					);
				}
			}

			/* HAS MANY */
			if (isset($class::$has_many)) {
				foreach ($class::$has_many as $field => $config) {
					if (isset($config[0])) $subclass = $config[0];
					else list($subclass) = each($config);  
					//er("Config is", $config);
					$ref = self::get_reflection($class);
					$ref2 = ORM::get_reflection($subclass);
					$table = $ref['table'];

					$subtable = $ref2['table'];
//er("Looking for: ", $class, "VS", $subclass, "or ", $table, "VS", $subtable);
					$left_key = null;
					$right_key = null;
                    $relation = 'slave';

                    $filters = isset($config[3]) ? $config[3] : null;

                    if (isset($ref2['foreign'][$table])) {
						list ($right_key, $left_key) = each ( $ref2['foreign'][$table] );
						$relation = 'master';
					}
					else if (isset($ref['foreign'][$subtable])) {
						list ($left_key, $right_key) = each ( $ref['foreign'][$subtable] );
					} else {
						er("NO ref ie either", $ref, "nor", $ref2);
						throw new Exception("Can't map $class::$left_key -- to $subclass object");
					}

					$obj['fastload'][] = array(
						'property' => $field,
						'class' => $subclass,
						'table' => $ref2['table'],
						'left' => $left_key,
						'right' => $right_key,
						'relation' => $relation,
						'filters' => $filters,
					);
				}
			}

			/* HAS ONE VIA */
			if (isset($class::$has_one_via)) {
				foreach ($class::$has_one_via as $field => $config) {
					//echo "Has many $name via: -- {$config}<br>";
					$has_class = key($config);
					$via_class = $config[$has_class][0];
					//echo " $has_class, via class $via_class "; 
					$local_field = $config[$has_class][1]; 
					$remote_field = $config[$has_class][2];
					$remote_hasmany = $config[$has_class][3];

					$ref2 = self::get_reflection($via_class);
					$ref3 = self::get_reflection($has_class);

					$left_key = null;
					$right_key = null;

					$single = null;

					if (isset($via_class::$has_one) &&
						isset($via_class::$has_one[$remote_hasmany])) {

						list($tmp, $right_key) = each($via_class::$has_one[$remote_hasmany]);
						$single = 1;
					}
					else if (isset($via_class::$has_many) &&
						isset($via_class::$has_many[$remote_hasmany])) {

						list($tmp, $right_key) = each($via_class::$has_many[$remote_hasmany]);
						$single = 0;
					}
					else throw new Exception("Can't map $class::$local_field -- $via_class object must have ::\$has_many or ::\$has_one for key `$remote_hasmany`");
					$obj['hasonevia'][] = array(
						'property' => $field,
						'class' => $has_class,
						'table' => $ref3['table'],
						'left' => $local_field,
						'middle' => $remote_field,
						'right' => $right_key,//$remote_hasmany,
						'via_class' => $via_class,
						'via_table' => $ref2['table'],
						'via_property' => $remote_hasmany,
						'single' => $single,
					);
				}
			}
			/* HAS MANY VIA */
			if (isset($class::$has_many_via)) {
				foreach ($class::$has_many_via as $field => $config) {
					//echo "Has many $name via: -- {$config}<br>";
					$has_class = key($config);
					$via_class = $config[$has_class][0];
					//echo " $has_class, via class $via_class "; 
					$local_field = isset($config[$has_class][1]) ? $config[$has_class][1] : $field; 
					$remote_field = isset($config[$has_class][2]) ? $config[$has_class][2] : $field;
					$remote_hasmany = isset($config[$has_class][3]) ? $config[$has_class][3] : $field;

					$ref2 = self::get_reflection($via_class);
					$ref3 = self::get_reflection($has_class);

					$left_key = null;
					$right_key = null;

					$single = null;

					if (isset($via_class::$has_one) &&
						isset($via_class::$has_one[$remote_hasmany])) {

						list($tmp, $right_key) = each($via_class::$has_one[$remote_hasmany]);
						$single = 1;
					}
					else if (isset($via_class::$has_many) &&
						isset($via_class::$has_many[$remote_hasmany])) {

						list($tmp, $right_key) = each($via_class::$has_many[$remote_hasmany]);
						$single = 0;
					}
					else throw new Exception("Can't map $class::$field -- $via_class object must have ::\$has_many or ::\$has_one for key `$remote_hasmany`");
					$obj['slowload'][] = array(
						'property' => $field,
						'class' => $has_class,
						'table' => $ref3['table'],
						'left' => $local_field,
						'middle' => $remote_field,
						'right' => $right_key,//$remote_hasmany,
						'via_class' => $via_class,
						'via_table' => $ref2['table'],
						'via_property' => $remote_hasmany,
						'single' => $single,
					);
				}
			}
			//er("MAP FOR ", $class, $obj);
			self::$mapping_cache[$class] = $obj;
		}
		return self::$mapping_cache[$class];
	}

	public static function xmlentities($str) {
		$ent = array(
			'&' => '&amp;',
			"'" => '&apos;',
			'"' => '&quot;',
			'<' => '&lt;',
			'>' => '&gt;',
		);
		foreach ($ent as $e => $o) {
			$str = str_replace($e, $o, $str);
		}
		return $str;
	}
	public static function ConvertToXML($object, $fields, &$payload) {
		$ret = '';
		$class = get_class($object);

		$ref = self::get_reflection($class, $tmp, $object);

		ORM::Wrap($object);

		$ret .= "\t".'<'.$class.'>' . "\n";

		$vals = array();
		$keys = array();
		foreach ($ref['fields'] as $name => $sql) {
//			if ($name == $ref['primary']) continue;
			if ($fields && !in_array($name, $fields)) continue;
			if (!property_exists($object, $name)) throw new Exception("$class::$name is undefined");
			$vals[] = $object->$name;
			$keys[] = $name;
			$ret .= "\t\t".'<'.$name.'>' . self::xmlentities($object->$name) . '</'.$name.'>'."\n";
		}
		$pl = $object->onExport();
		if ($pl) {		
			$ret .= "\t\t".'<payload>'."\n";
			foreach ($pl as $obj) {
				$ret .= "\t\t\t".'<file>';
				$ret .= '<src>'.$obj['src'].'</src>';
				$ret .= '<dst>'.$obj['dst'].'</dst>';
				$ret .= '</file>'."\n";
				if (is_array($payload)) $payload[] = $obj;
			}
			$ret .= "\t\t".'</payload>'."\n";

		}
		/*
		foreach ($ref['foreign'] as $table => $foreign) {
			foreach ($foreign as $local_name => $remote_name) {
				$keys[] = $local_name;
				$val = 0;
				$cls = class_by_table($table);
				if ($object->
				$vals[] = $val;
			}
		}*/
		$ret .= "\t".'</'.$class.'>' . "\n";
		return $ret;
	}

	public static function Insert($object, $fields = array()) {
		$class = get_class($object);
		_debug_log("Inserting object $class");
		$ref = self::get_reflection($class, $tmp, $object);
		//print_r($ref);	exit;

		ORM::Wrap($object);

		$vals = array();
		$keys = array();
		foreach ($ref['fields'] as $name => $sql) {
			if ($name == $ref['primary']) continue;
			if ($fields && !in_array($name, $fields)) continue;
			if (!property_exists($object, $name)) throw new Exception("$class::$name is undefined");
			$vals[] = $object->$name;
			$keys[] = $name;
		}
		/*
		foreach ($ref['foreign'] as $table => $foreign) {
			foreach ($foreign as $local_name => $remote_name) {
				$keys[] = $local_name;
				$val = 0;
				$cls = class_by_table($table);
				if ($object->
				$vals[] = $val;
			}
		}*/
		$q = new QRY();
		$q->INSERT($keys);//array_keys($ref['fields']));
		//$q->INSERT();
		$q->INTO(self::getTable($class));
		$q->VALUES($vals);

		$db = self::getDB();
		//_debug_log("ORM Insert:".print_r($q->toRun() ,1));
		$new_id = $db->set ( $q->toRun() );
		_debug_log("inserted and saving to cache (Id: $new_id)");
		//$object->identify( $new_id );
		//$object->{$object->reflection()->primary} = $new_id;
		$object->{$ref['primary']} = $new_id;
		//ORM::Cache($object);
		//$object->prepare2(null, 1);
		_debug_log("new id: $new_id");
		return $new_id;
	}

	public static function Delete($object) {
		$class = get_class($object);
		_debug_log("Deleting object $class");
		$ref = self::get_reflection($class, $tmp, $object);
		$q = new QRY();
		$q->DELETE();
		$q->FROM(ORM::getTable($class));
		$q->WHERE(array($ref['primary']));
		$q->DATA(array($ref['primary']=>$object->id()));

		$db = self::getDB();
		$stm = $q->toRun();
		$db->run ( $stm );

		return true;
	}

	public static function Update($object, $fields = array()) {
		$class = get_class($object);
		_debug_log("Updating object $class");
		$ref = self::get_reflection($class, $tmp, $object);
		//print_r($ref);	exit;

		ORM::Wrap($object);

		$vals = array();
		$keys = array();
		foreach ($ref['fields'] as $name => $sql) {
			if ($name == $ref['primary']) continue;
			if ($fields && !in_array($name, $fields)) continue;
			#er("$name: ", $object->$name);
			$vals[] = $object->$name;
			$keys[] = $name;
		}

		$q = new QRY();
		$q->UPDATE(self::getTable($class));
		$q->SET($keys);//array_keys($ref['fields']));
		$q->VALUES($vals);
		$q->WHERE(array($ref['primary']));
		$q->DATA($object->id());

		$db = self::getDB();
		//_debug_log("ORM Save:".print_r($q->toRun(),1));
		$db->set ( $q->toRun() );
		
		return true;
	}

	private static function cache_add($key, $val) {
		self::$static_cache[$key] = $val;	
	//	self::$new_cache[] = 'self::$static_cache[\''.$key.'\'] = '. self::php_encode($val) .';'.PHP_EOL;
	//	self::cache_flush();
	}
	public static function class_by_table($table_name) {
		foreach ( get_declared_classes() as $c ) {
			if (!is_subclass_of($c, 'ORM_Model')) continue;
			$class_table = self::getTable($c);
			_debug_log("Class $c -> table $class_table");
			if ( $class_table === $table_name ) {
				return $c;
			}
		}
		return false;
	}
	public static function get_reflection($class_name, &$fresh = 0, $dummy = null) {

		if (!class_exists($class_name)) {
			throw new Exception("No such class ".$class_name);
		}
		if (!isset(self::$static_cache[$class_name])) {
			$fresh = 1;	
			$agg = array('fields'=>array(), 'foreign'=>array(), 'primary'=>''); /* Agregate everything here */

			//throw new Exception('No DocComment specifiying table name found for class "'.$class_name.'"');
			if (isset($class_name::$table_name)) {
				$agg['table'] = $class_name::$table_name;
			} else
			$agg['table'] = strtolower($class_name).'s';

			$nprops = array();

			if (isset($dummy)) {
				$internal = $dummy->internal_reflection();
				$nprops = $internal['fields'];
				$agg['table'] = $internal['table'];
			}
			
			if (isset($class_name::$_sql)) {
				$nprops = $class_name::$_sql;

				$parent_class = $class_name;
				while (($parent_class = get_parent_class($parent_class)) != 'ORM_Model') {
				if (isset($parent_class::$_sql))
					$nprops = array_merge($parent_class::$_sql, $nprops);
				}

			} else
			if (class_exists('ReflectionClass')) {
				$ref = new ReflectionClass($class_name);
				$props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);
				if (($tbl = $ref->getDocComment())) {
					$agg['table'] = trim(substr($tbl, 3, strpos($tbl, '*', 3) - 3));
				}
				if (empty($props)) throw new Exception('Class "'.$class_name.'" has no public fields!');
				foreach ($props as $prop) {
					if ($prop->isStatic()) continue;
					$def = $prop->getDocComment();
					if ($def) $def = trim(substr($def, 3, strpos($def, '*', 3) - 3));
					$name = $prop->name;

					$nprops[$name] = $def;	
				}
			}

			foreach ($nprops as $name=>$def) {
				$foreign = '';
				if (!$def) $def = 'TEXT';
//				if (!$def) throw new Exception('Unproperly formatted DocComment for property '.$prop->name.' in class '. get_class($this));
				if (stripos($def, 'PRIMARY')) $agg['primary'] = $name;
				if (($l = stripos($def, 'FOREIGN'))) {
					$foreign = substr($def, $l);
					$def = substr($def, 0, $l-1);
					if (!preg_match('/FOREIGN KEY REFERENCES (\w+)\s{0,1}\((\w+)\)/i', $foreign, $mc)) throw new Exception('Foreign definition "'.$def.'" must be in innodb format; property '.$name.' in class '. $class_name);
					$agg['foreign'][$mc[1]] = array($name => $mc[2]);
				}
				$agg['fields'][$name] = $def;
			}
			$ref = null;/*
			if (!$agg['primary']) {
				$agg['primary'] = 'id';
				$agg['fields']['id'] = 'MEDIUMINT(255) PRIMARY KEY AUTO_INCREMENT';        	        	
			}*/
			self::cache_add($class_name, $agg);
		}
		return self::$static_cache[$class_name];
	}

}

class ORM_Collection implements Iterator, Countable, ArrayAccess {

	private $counted = FALSE;
	private $loaded = FALSE;
	private $filter = NULL;

	private $position = 0;
	private $data = NULL;

	private $model_class;
	private $ctx = NULL;
	private $ctx_id = NULL;

	public function __construct($model_name, $filters = null, $loader_ctx = null) {
		//if (!$db) $db = db_init();
		if (!isset($model_name)) throw new Exception('No model name provided');
		//$this->db = $db;
		$this->model_class = $model_name;
		$this->filter = $filters;
		if (isset($loader_ctx)) $this->using($loader_ctx);
		else $this->using( ORM::objectCacheAsLoader() ); 
	}

	public function loaded() {
		return $this->loaded ? TRUE : FALSE ;
	}

	public function order_using($by, $values, $reset = false) {
		if (!$this->loaded) throw new Exception("ORDER_USING is not allowed on unloaded ORM_Collections");
		if (sizeof($this->data) != sizeof($values)) throw new Exception("Values array must have same size as the collection");
		if ($this->data[0] && !isset($this->data[0]->{$by})) throw new Exception("Undefined field `".$by."` for class ".$this->model_class);

		/* Save field */
		if ($reset === true) {
			$initials = array();
			foreach ($this->data as $item)
				$initials[] = $item->{$by}; 
		}

		/* SORT */
		$id2index = $index_weight = array();
		foreach ($this->data as $i=>$item)
			$id2index[$item->id] = $i;
		foreach ($values as $i=>$val)
			$index_weight[$id2index[$val]] = $i;
		ksort($index_weight);

		array_multisort($index_weight, SORT_ASC, $this->data);

		/* Set new field */
		if ($reset === true) {
			$i = 0;
			foreach ($this->data as $item) {
				$item->{$by} = $initials[$i];
				$i++;
			}
		}
		if ($reset === 2) {
			$i = 0;
			foreach ($this->data as $item) {
				$item->{$by} = $i;
				$i++;
			}
		}
	}

	public function order_by($by, $way = 'ASC', $reset = false) {
		$way = strtoupper($way);
		if ($this->loaded) {
			/* Save field */
			if ($reset) {
				$initials = array();
				foreach ($this->data as $item)
		   			$initials[] = $item->{$by}; 
			}
			/* Actually reorder what we have */
			$sort_func = create_function('$a, $b', 'return $a->'.$by.
				($way == 'ASC' ? ' > ' : ' < ').'$b->'.$by.';');
			usort($this->data, $sort_func);
			if ($reset) {
				$i = 0;
				foreach ($this->data as $item) {
					$item->{$by} = $initials[$i];
					$i++;
				}
			}
			return $this;
		}
		if (is_string($this->filter) || is_array($this->filter)) {
			$q = new QRY();
			$q->WHERE(array_keys($this->filter));
			$q->DATA($this->filter);
			$this->filter = $q;
		} else if (!$this->filter) {
			$this->filter = new QRY();
		}
		if (is_object($this->filter)) {
			$this->filter->ORDER_BY(array($by => $way)); 
		}
		return $this;
	}

	public function using($ctx) {
		if ($ctx) $this->ctx_id = $ctx->unique_id;
		else $this->ctx_id = NULL;
		return $this;
	}

	public function load($page = null, $quantity = null, $depth = -1) {
		_debug_log("Loading collection <b>".$this->model_class."</b>");
		//if (!isset($this->ctx_id)) $ctx = ORM::objectCacheAsLoader();

		$ctx = ORM::Loader( $this->ctx_id );

		if ($page && $quantity) $ctx->limit($page, $quantity);
		//if (is_object($this->filter)) $this->filter->visDump();

		$simple_filter = is_object($this->filter) ? $this->filter->asFilter() : $this->filter;

		$this->data = $ctx->filterCache($this->model_class, $simple_filter); 		

		if (!$this->data) {

			$ctx->load($this->model_class, $this->filter, $depth);
			$this->data = $ctx->last_batch;
			if (!$this->data) $this->data = $ctx->filterCache($this->model_class, $simple_filter);

			if ($this->data) {
				if (defined('HEAVY_DEBUG')) er($this->data);
				if (defined('HEAVY_DEBUG')) er($ctx);
				$ctx->assembleAll($this->model_class, 1);//$depth - 1);//$obj, $depth);
				//$ctx->assembleOne($obj, $depth);//$obj, $depth);
			}
		}
		$this->loaded = 1;
		$this->filter = NULL;

		return $this->loaded;
	}
	public function forceFilter($new_filter) {
		if (!$this->filter) {
			$this->filter = $new_filter;
			return;
		}
		$this->filter($new_filter);
	}
	public function filter($new_filter) {
		if ($this->loaded) throw new Exception('Changing filter in loaded collection is not allowed!');
		if (!$this->filter) {
			$this->filter = $new_filter;
			return;
		}
		else if (is_string($this->filter) || is_array($this->filter)) {
			$q = new QRY();
			$q->WHERE(array_keys($this->filter));
			$q->DATA($this->filter);
			$this->filter = $q;
		}
		if (is_object($this->filter)) {
			if (is_object($new_filter)) {
				//$this->filter->visDump();
				//$new_filter->visDump();
				//$new_filter->applyTo($this->filter);
				//$new_filter->visDump();
			} else {
				$this->filter->WHERE(array_keys($new_filter));
				foreach ($new_filter as $key => $val) {
					$this->filter->DATA($key, $val);
				}
			}
		}
	}

	public function one() {
		if ($this->valid()) return $this->current();
		return false;
	}
	public function chainLoad($p=null,$q=null,$d=-1) {
		$this->load($p,$q,$d);
		return $this;
	}

	public function loadFrom($entries) {
		if ($this->loaded) throw new Exception("Can't load-from a loaded collection");
		foreach ($entries as $obj) {
			if (is_object($obj) && get_class($obj) == $this->model_class) {
				$this->data[] = $obj;
				continue;
			}
			$props = (array)$obj;
			$class = $this->model_class;
			$elem = new $class;
			foreach ($props as $prop=>$val) {
				$elem->$prop = $val;
			}
			$elem->assemble();
			$this->data[] = $elem;
		}
		$this->loaded = 1;
		$this->_imported = 1;
		return $this->loaded;
	}

	public function loadFromXML($xmlstr) {
		$entries = new SimpleXMLElement($xmlstr);

		$class = $this->model_class;

		er($entries->{$class}->count());
		
		$this->loadFrom($entries->{$class});
	}

	public function toXML($fields = array(), &$payload = null) {
		$ret = '';
		
		$ref = ORM::get_reflection($this->model_class);
		
		$ret .= "".'<'. $ref['table'] . '>'."\n";
		foreach ($this->data as $item) {
			$ret .= ORM::ConvertToXML($item, $fields, $payload);
		}
		$ret .= "".'</'.$ref['table'] . '>'."\n";
		return $ret;
	}

	public function save($fields=null) {
		if (!$this->loaded) throw new Exception("Can't save unloaded ORM_Collection");
		
		$ref = ORM::get_reflection($this->model_class);

		if ($fields != null)		
		foreach ($fields as $name)
			if (!in_array($name, array_keys($ref['fields'])))
				throw new Exception("Undefined field `".$name."` for class ".$this->model_class);

		if (isset($this->_imported) && $this->_imported == 1) { // Hack -- INSERT unloaded, UPDATE loaded

			$q = new QRY();
			$q->INTO(ORM::getTable($this->model_class));
			foreach ($this->data as $item) {
				$vals = array();
				$keys = array();
				foreach ($ref['fields'] as $name => $sql) {
					if ($fields && !in_array($name, $fields)) continue;
					$vals[] = $item->$name;
					$keys[] = $name;
				}
				$q->VALUES($vals);
			}
			$q->INSERT($keys);

		} else {

			$q = new QRY();
			$q->UPDATE(ORM::getTable($this->model_class));
			foreach ($this->data as $item) {
				$vals = array();
				$keys = array();
				foreach ($ref['fields'] as $name => $sql) {
					if ($name == $ref['primary']) continue;
					if ($fields && !in_array($name, $fields)) continue;
					$vals[] = $item->$name;
					$keys[] = $name;
				}
				$q->VALUES($vals);
				$q->DATA($item->id());
			}
			$q->SET($keys);
			$q->WHERE(array($ref['primary']));

		} //endhack

		$db = ORM::getDB();
		$sql = $q->toBatch();
		$ok = $db->batch( $sql );

		//$t=$ok=0; foreach ($this->data as $item) { $t++; $ok += ($item->save($fields)?1:0); }
		//return ($t == $ok ? true : false);

		return $ok;
	}

	public function delete() {
		//if (!$this->loaded) throw new Exception("Can't delete unloaded ORM_Collection");
		//if (!$this->data) return true; /* might be null or false */

		$ref = ORM::get_reflection($this->model_class);
		$q = new QRY();
		$q->DELETE();
		$q->FROM(ORM::getTable($this->model_class));

		if ($this->loaded)
		{
			$ids = array();
			foreach ($this->data as $item) {
			    $ids[] = $item->id(); 
			    $item->onDelete();
			}
			//er("Delete by id", $ids);
			$q->WHERE('id');
    		$q->IN($ids);

    		if (!$ids) return false; /* do not delete empty id set */
    	}
    	else
			if ($this->filter)
				ORM_Loader::apply_filters($q, $this->filter);

		$db = ORM::getDB();
		$sql = $q->toRun();
		$db->set( $sql );

		return true;
	}

	function rewind() {
		//var_dump(__METHOD__);
		$this->position = 0;
	}
	function current() {
		//var_dump(__METHOD__);
		return $this->data[$this->position];
	}
	function key() {
		//var_dump(__METHOD__);
		return $this->position;
	}
	function next() {
		//var_dump(__METHOD__);
		++$this->position;
	}
	function valid() {
		//var_dump(__METHOD__);
		return isset($this->data[$this->position]);
 	}

	function count() {
		if ($this->loaded) return count($this->data);
		if ($this->counted === FALSE) {
			$table = ORM::getTable($this->model_class);

			$ref = ORM::get_reflection($this->model_class);
			$field = $ref['primary'];
			if (!$field) $field = '*';

			$q = new QRY();
			$q->SELECT('COUNT('.$field.')');
			$q->FROM($table);
			if ($this->filter)
				ORM_Loader::apply_filters($q, $this->filter);

			$db = ORM::getDB();

			$sql = $q->toRun();
			$res = $db->fetch( $sql );
			//_debug_log("ORM count:".print_r($q->toRun(),1).print_r($res,1));
			if ($res) {
				$this->counted = $res[0]['COUNT('.$field.')'];
			}
		}
		return $this->counted;
		var_dump(__METHOD__);
		return count($this->data);
	}
	function offsetExists($offset) {
		if (!$this->loaded) throw new Exception("Accessing unloaded Collection (".$this->model_class.")");
		return isset($this->data[$offset]);	
	}
	function offsetGet($offset) {
		if (!$this->loaded) throw new Exception("Accessing unloaded Collection (".$this->model_class.")");
		if (!isset($this->data[$offset])) throw new Exception('Accessing undefined index `'.$offset.'` in Collection '.$this->model_class);
			return $this->data[$offset];
	}
	function offsetSet($offset, $set) {
		throw new Exception("Can't set objects in ORM_Collection (yet)");
		if (!$this->loaded) throw new Exception("Accessing unloaded Collection (".$this->model_class.")");
	}
	function offsetUnset($offset) {
		throw new Exception("Can't delete objects from ORM_Collection (yet)");
		if (!$this->loaded) throw new Exception("Accessing unloaded Collection (".$this->model_class.")");
	}
}

class ORM_Model {

	public static $table_name;

	final public function __construct($load = true) {
		if ($load) $this->load();
	}

	public function load($ctx = null, $depth = -1) {
		if (!$this->id())    $this->prepare2($ctx);
		else                 $this->assemble2($ctx, $depth);
	}

	public function prepare2($loader_ctx) {
		if (!$loader_ctx) $loader_ctx = ORM::objectCacheAsLoader();

		$loader_ctx->assembleOne($this, 1, false);
	}

	public function autofields($before_assemble) {
		$class = get_class($this);
		$map = ORM::Map($class);

		foreach ($map['auto'] as $name => $config) {
			if (is_bool($config) && $config != $before_assemble) continue;
			if (is_callable(array($this, $name.'_auto'))) {
				$fnc = $name.'_auto';
				$this->$name = $this->$fnc();
			}
			else if (is_string($config)) {
				$this->$name = str_replace('*', $this->id(), $config);
			}
			else {
				$this->$name = $config;
			}
		}
	}

	private function rm_has_one() {
		$object =& $this;
		$class = get_class($this);
		$ref = ORM::get_reflection($class, $tmp, $this);

		$map = ORM::Map($class);

		foreach ($map['autojoin'] as $has_one) {
			//if ($has_one['property'] != 'master') continue;
			//this way we remove belongs_to too

			$name = $has_one['property'];
			$subclass = $has_one['class'];		
			$key_property = $has_one['left'];
			$field = $key_property;
			
			$table = $has_one['table'];			
			
			$ref2 = ORM::get_reflection($subclass);
			$table = $ref2['table'];

			$subfield = $has_one['right'];

			//invert values			
			if ($has_one['relation'] == 'slave') {
				//$subfield = $has_one['left'];
				//$field = $has_one['right'];
			}
			if ($field == $ref['primary']) continue;

			//echo "<br>For class $class : <br>";
			//echo "Must have a field $name of subclass $subclass with field $subfield to populate var $field";
			/* FORCEFULLY PROTECT THE FIELD... */
			$ok = false;

			if (isset($object->$name) && is_object($object->$name)) {
				$subobj =& $object->$name;

				if (strtolower(get_class($subobj)) == strtolower($subclass)) {
					$object->$field = $subobj->$subfield;
					$ok = true;
				} 
			}
			if (property_exists($object, $name) && $object->$name === NULL) {
				$object->$field = 0;
				$ok = true;
			}
			//echo "OK:".$ok.", $field:".$object->$field;
			if (!property_exists($object, $field)) {
				throw new Exception("Field `".$field."` can't be deducted for $class because it's `$name` property is NULL");
			}
			//$object->$field = 
			//$object->$name = null;
		}

	}

	public function assemble() { return $this->assemble2(null); }

	public function assemble2($loader_ctx, $depth = -1) {

		if (!$loader_ctx) $loader_ctx = ORM::objectCacheAsLoader();

		$loader_ctx->assembleOne($this, $depth);
	}

	public function tear($has_many_via = FALSE, $has_many = FALSE, $has_one = TRUE) {
		_debug_log("Dismembering <b>".get_class($this)."</b>");
		//er($this);
		//$this->rmautofields(FALSE);
		//if ($has_many_via) $this->rm_has_many_via();
		//if ($has_many)	$this->rm_has_many();
		if ($has_one) {
			$this->rm_has_one();
		}
		//$this->rmautofields(TRUE);	

	}

	public function update($fields = null) {
		return ORM::Update($this, $fields);
	}

	public function insert($fields = null) {
		$id = ORM::Insert($this, $fields);
		if ($id !== false) $this->assemble();  
		return $id;
	}

	public function save($fields = null) {
		if ($this->id()) return $this->update($fields);
		else return $this->insert($fields);
	}

	final public function delete() {
		$this->onDelete();
		return ORM::Delete($this);
	}

	public function onExport() { /* plugin */ }
	public function onDelete() { /* overload for hooking in */ }

	public function internal_reflection() {
		$agg = array(
			'table' => isset($this->_table_name) ? $this->_table_name :
				strtolower( get_class($this) ) . 's',
			'fields' => isset($this->_sql) ? $this->_sql : array(),
			'primary' => null,
			'foreign' => null,
		);
		return $agg;
	}

	public function reflection() {
		return (object) ORM::get_reflection( get_class($this), $tmp, $this );
	}

	/* Utility */
	public function identify($id) { $this->{$this->reflection()->primary} = $id;
		$this->assemble2(null); }
	public function id() { $p = $this->reflection()->primary; return isset($this->{$p}) ? $this->{$p} : null;	}
	public function describe() {		return $this->reflection()->fields;	}
	public function ascribe() {
		if (!$this->ascription) $this->ascription = array_diff(array_keys($this->describe()),
									array($this->reflection()->primary) );
		return $this->ascription; 
	}

}

class ORM_Loader {

	public $unique_id = 0;
	private $heap = array(); /* Array of objects, classified by CLASS and ID */
	//private $last = NULL;    /* pointer to last cached object, for speedup */
	//public function one() {        return $this->last;    }
    
	public function __construct() {
		$this->unique_id = ORM::uniqueId();
	}

	public function cache($object) {
		if (!is_object($object)) throw new Exception("Must be an object");
		$class = get_class($object);
		$id = $object->id();
		if (!$id) { /* Bad hack ... */
			$map = ORM::Map($class);
			foreach ($map['autojoin'] as $puzzle) {
			if ($puzzle['relation'] == 'master' && $puzzle['relation'] != $class) {
				$key = $puzzle['left'];
				$id = $object->$key;
				break;
			}
			}
		}
		$this->heap[$class][$id] = $object;
		//$this->last = $object;
	}
	public function queryCache($class, $property, $match) {
		if ($property === null) {
			if (isset($this->heap[$class]) && isset($this->heap[$class][$match]))
				return $this->heap[$class][$match];
		}
		if (isset($this->heap[$class]))
		foreach ($this->heap[$class] as $id => $object)
			if (isset($object->$property) && $object->$property == $match)
				return $object;
		return NULL;
	}

	public function filterCache($class, $filters) {
		$data = array();
		if (isset($this->heap[$class]))
		foreach ($this->heap[$class] as $id => $object) {
			$ok = true;
			if ($filters) foreach ($filters as $property => $match) {
				if (!isset($object->$property) || $object->$property != $match) {
					$ok = false;
					break;
				}
			}
			if ($ok) $data[] = $object;
		}
		return $data;
	}

	static function apply_filters($q, $filters) {

		if (isset($filters)) {
			if (is_object($filters)) {
				//$filters = $filters->asFilter();
				$q->applyFrom($filters);
			}
			else if (is_array($filters)) {
				$q->WHERE(array_keys($filters));
				$q->DATA($filters);
			} else {
			    // literal string
				$q->WHERE($filters);
			}
		}
	}
	private function create_query($class, &$filters) {
		if (is_object($filters) && get_class($filters) == 'QRY') {
			//$q = $filters;
			$q = new QRY();
			$ref = ORM::get_reflection($class);
			$q->FROM(ORM::getTable($class) . ' ' . $ref['table']);
			$q->SELECT(array_keys($ref['fields']));
			//$q->applyFrom($filters);
			ORM_Loader::apply_filters($q, $filters);

		} else {
			$q = new QRY();
			$ref = ORM::get_reflection($class);
			$q->FROM(ORM::getTable($class). ' '. $ref['table']);
			$q->SELECT(array_keys($ref['fields']));
			ORM_Loader::apply_filters($q, $filters);
			//$filters = null;
		}
		return $q;
	}

	private function PrepareJoin2($class, &$q, $depth = 1, $Xstop = 0, $dir = 'master', &$rels = array(), $last_deep = 0, &$deep = -1) {
		// hack -- avoid endless recursion (which might happen in linked lists)
		if ($depth <= -60) throw new Exception("Recursing too deep");
		if ($depth === 0) { if (defined('HEAVY_DEBUG')) echo "(ingoring $class as $dir)<br>"; return; }
		if (defined('HEAVY_DEBUG')) echo "<ul>";
		if (defined('HEAVY_DEBUG')) echo "<li> FOR $class (as $dir) (at depth $depth)";
		//if ($stop === 1) return;
		//er("Already formed: ", $rels);

		$last_rel = $rels[sizeof($rels)-1];

		$real_last_deep = $last_deep; 
		$last_deep = $deep;
		$deep++;
		$next_deep = $deep;

		$map = ORM::Map($class);
		$db = ORM::getDB();

		$moved = 0;
		if (defined('HEAVY_DEBUG')) echo "<li> Has autojoin:".($map['autojoin'] ? "YES" : "NO")."<BR>";
		foreach ($map['autojoin'] as $has_one) {
			if ($has_one['relation'] == $dir && $Xstop) continue;//{ $sub_depth = 0; $stop = 1; }

			$property = $has_one['property'];
			$primary_key = $has_one['right'];
			$foreign_key = $has_one['left'];

			$table = $has_one['table'];
			$subclass = $has_one['class'];

			$map2 = ORM::Map($subclass);

            // Verify it's not a circular
            $stop = 2;
            //if ($has_one['relation'] != $dir) $stop = 0;
            $test_deep = min($real_last_deep, $last_deep) - 1;
            $has_one['ident'] = $table.($next_deep+1);
            //$test_ident = $table.($real_last_deep);//+$stop+$moved);
            //if ($has_one['relation'] != $dir)
               $test_ident = $table.(($test_deep == 0 || $test_deep == -1) ? '' : $test_deep);
            $moved ++;

            //er("[", $map2['autojoin'], "]");
            if (defined('HEAVY_DEBUG')) er("+JOIN", $has_one, "TEST IDENT:".$test_ident, "DIR:".$dir,"LAST DEEP:".$last_deep,"REAL LAST DEEP:".$real_last_deep);
            $related = false;
            foreach ($rels as $rel) {
                if (defined('HEAVY_DEBUG')) echo "<li>[$test_ident] Is it circular to ".print_r($rel,1);
                if ($rel['ident'] == $test_ident) {
                    if (defined('HEAVY_DEBUG')) echo "\nYES";
                    $related = true;
                    break;
                    //echo "Something something darkside";
                }
                if (defined('HEAVY_DEBUG')) echo "\nNO";
            }
            $circular = false;
            //$related = true;
            if ($related) {
                foreach ($map2['autojoin'] as $rel_one) {
                    if (
                        $rel_one['relation'] != $has_one['relation'] &&
                        $rel_one['left'] == $has_one['right'] &&
                        $has_one['left'] == $rel_one['right'] &&
                        $rel_one['class'] == $class &&
                        $has_one['class'] == $subclass
                    ) {
                        if (defined('HEAVY_DEBUG')) er($has_one, $rel_one);
                        if (defined('HEAVY_DEBUG')) echo ",TOTALLY";
                        $circular = true;
                        break;
                    }
                }
            }
            if ($circular) continue;
//...............

			if ($has_one['relation'] == 'slave') {
				//$primary_key = $has_one['left'];
				//$foreign_key = $has_one['right'];
			}
			if (defined('HEAVY_DEBUG')) echo "<br>RELS:".print_r($rels,1)."<br>";
			$ref2 = ORM::get_reflection($subclass);

			$q->FROM($db->_prefix().$table . ' ' . $has_one['ident']);
			$q->SELECT(array_keys($ref2['fields']));
			$q->ADD_ON(array($foreign_key => $primary_key), $next_deep);//offset);
			if (defined('HEAVY_DEBUG')) echo "<div style='background: green'> ADD ON -> $foreign_key , $primary_key, $next_deep </div>";

			$rels[] = array('ident' => $has_one['ident'], 'class' => $has_one['class'], 'primary_key'=>$ref2['primary'], 'name'=>$has_one['property'], 'parent'=> !$last_rel ? NULL : $last_rel['ident']);
			//$deep++;
			if (defined('HEAVY_DEBUG')) echo "<BR>FOR IT's ".$has_one['property'] . "==";
			$this->PrepareJoin2($subclass, $q,  $depth-1, 1, $has_one['relation'], $rels, $next_deep+1, $deep);
		}
		if (defined('HEAVY_DEBUG')) echo "</ul>";
	}

	private function UpdateObjectFromArray(&$array, $class_name, $primary, $prefix, $clear = FALSE) {
		$primary_key = $prefix . $primary;
		if (!array_key_exists($primary_key, $array)) throw new Exception("Unable to find key '$primary' with prefix '$prefix' in the obj.array".er($array,1)); 

		$id = $array[$primary_key];

		if (!$id) return NULL;//new $class_name(false);

		$delets = array();
		$obj = $this->queryCache($class_name, null, $id);
		if (!$obj) $obj = new $class_name(false);

		foreach ($array as $key => $value) {
			if (substr($key, 0, strlen($prefix)) == $prefix) {
				$short_key = substr($key, strlen($prefix));
				$obj->$short_key = $value;
				$delets[] = $key;
			}
		}
		if ($clear) foreach ($delets as $key) {
			unset($array[$key]);
		}

		$obj->autofields(true); // HACK -- autofields
		//$this->cache($obj);
		return $obj;
	}

	public function limit($page, $quantity) {
		if ($page && $quantity) {
			$page = round($page); if ($page < 1) $page = 1;
			$quantity = round($quantity); if ($quantity <= 0) $quantity = 0;
			if ($quantity)
			    $this->limit = (($page-1) * $quantity) . ',' . $quantity; 
				//$q->LIMIT( (($page-1) * $quantity) . ',' . $quantity);
		}
	}

	public function load($class, $filters = null, $depth = -1) {
		if ($depth === 0) return false;
		// Prepare DB
		$db = ORM::getDB();
		$map = ORM::Map($class);
		$ref = ORM::get_reflection($class);

		$this->last_batch = array();

		// Prepare query
		$q = $this->create_query($class, $filters);

		if (isset($this->limit)) $q->LIMIT( $this->limit );
/*

*/
		// Simple load
		if ($depth == 1 || (!$map['autojoin'] && !$map['hasonevia'])) {

			$sql = $q->toRun();
			$objects = $db->fetchObject( $sql , $class, array(false) );
			if (!$objects) return false;

			foreach ($objects as $obj)
			{ 
			    $this->cache($obj);
			    $this->last_batch[] = $obj;
			}
		}
		// Left join load
		else {
			// Prepare SQL query with multiple LEFT JOINs
			$tmp = array(array('ident'=>$ref['table'], 'class'=>$class, 'primary_key'=>$ref['primary'], 'name'=>NULL));

			$this->PrepareJoin2($class, $q,  $depth, 0, 'master', $tmp);
			// Prepare SQL query with multiple LEFT JOINs
			//$this->PrepareJoinVia($class, $q, 0);

			//er("Load", $class, "From", $arr, "tmp", $tmp, "Using:", $ref);
			/* Hack -- if table doesn't have a primary key, use
			 * right-hand link key */
			if (!$ref['primary']) {
				foreach ($map['autojoin'] as $puzzle) {
					if ($puzzle['relation'] == 'master' && $puzzle['class'] != $class) {
						$ref['primary'] = $puzzle['left'];
						break;
					}
				}
			}

			// Change SQL query to include EVERY alias
			$q->alias_fields = 1;
			$q->alias_tables = 1;
			$q->option('alias_fields', 1);
			$q->option('alias_tables', 1);

			// add group-by by original id
			$q->GROUP_BY($ref['table'].'.'.$ref['primary'], 0);


			/////filters
			//$this->apply_filters($q, $filters);
			//$q->option('named_params', 1);
			//$q->visDump();

			// Load entries
			$sql = $q->toRun();
			$entries = $db->fetch($sql);//, $name);
			if (!$entries) return false;

			if (defined('HEAVY_DEBUG')) er("<b><hr>TMP:",$entries,$tmp,"</b>");
			// Break entries into objects
			foreach ($entries as &$arr) 
			{
				$obj = $this->ExecJoin2($class, $arr, $tmp, $ref['table'], $ref['primary']);//, $ref['table'], $depth);

				//execJoin will also cache...
				//$this->assembleOne($obj, 1);
				$this->last_batch[] = $obj;
			}
			//er("<i>",$this,"</i>");
        }
		return true;
	}

	public function ExecJoin2($class, &$arr, $rels, $p_ident, $primary_key) {

		$classS = $class;
		$tableS = $p_ident.'__';

		$obj = $this->UpdateObjectFromArray($arr, $classS, $primary_key, $tableS, true);        

		if (!$obj) return NULL;

		foreach ($rels as $pff) {
			if (isset($pff['parent']) && $pff['parent'] == $p_ident) {
				$field = $pff['name'];
				$obj->$field = $this->ExecJoin2($pff['class'], $arr, $rels, $pff['ident'], $pff['primary_key']);  
			}
		}
		//$this->assembleOne($obj, 1);
		$this->cache($obj);
		return $obj;    
	}

	public function assembleAll($name, $depth = -1) {
		if (!is_string($name)) throw new Exception("ARG must be string");
		$i = 0;
		if (!isset($this->heap[$name])) return;
		//foreach ($this->heap as $class) {
		$max = sizeof($this->heap[$name]);
		foreach ($this->heap[$name] as $id => &$object) {
			if ($i >= $max) break;
			$this->assembleOne($object, $depth);
			$i++;
		}
		//}
	}

	public function assembleOne(&$object, $depth = -1, $fatal = true) {
		if ($depth === 0) return;
		$class = get_class($object);
		$map = ORM::Map($class);	

		$object->autofields(true);

		//er("MUST ASSEMBLE ($depth)", $object);
		//er("USING -- ", $map);
		//$fatal = true;

		/* HAS ONE */
		foreach ($map['autojoin'] as $has_one) {
			//if ($has_one['relation'] != 'master') continue;

			$has_class = $has_one['class'];
			$key_property = $has_one['right'];
			$foreign_property = $has_one['left'];
			$name = $has_one['property'];

			if (!isset($object->$foreign_property)) {
				if (!$fatal) $object->$foreign_property = NULL;
				else throw new Exception("Unable to add `$name` using {$has_one['relation']} $class's `$foreign_property`");
			}

			if (defined('HEAVY_DEBUG')) _debug_log(" $depth > 0 | $class [with $foreign_property={$object->$foreign_property}] ~{$has_one['relation']}~ $has_class($key_property={$object->$foreign_property}), mapped to field $class->$name ");
			if (property_exists($object, $name)) {
				if (defined('HEAVY_DEBUG')) _debug_log("Property $name already set");
				continue;
			}

			if ($object->$foreign_property) {
				if (defined('HEAVY_DEBUG')) _debug_log("$class $foreign_property is set");
				if (defined('HEAVY_DEBUG')) _debug_log("Adding $has_class to $class->$name via $key_property = ".$object->$foreign_property);

				$link = $this->queryCache($has_class, $key_property, $object->$foreign_property);

				if (!$link) {
					if (defined('HEAVY_DEBUG')) _debug_log("Found nothing in cache for class $has_class($key_property=".$object->$foreign_property . ") (property $foreign_property of $class)");
					if (defined('HEAVY_DEBUG')) er("SQL-QUERYING $has_class, $key_property => {$object->$foreign_property}");
					$this->load($has_class, array($key_property => $object->$foreign_property), $depth - 1);
					$object->$name = $this->queryCache($has_class, $key_property, $object->$foreign_property);//ORM::Model($has_class, $object->$key_property);
				} else {
					$object->$name = $link;
					if (defined('HEAVY_DEBUG')) er("Now, let's assemble some more", $object->$name);
					$this->assembleOne( $object->$name, $depth -1 );    		    
				}

			} else {
				$object->$name = NULL;
			}
/*
			if ($has_one['relation'] == 'master') {
				_debug_log("Unsetting $key_property ..from $has_class now"); 
				unset($object->$name->$key_property);
			} else {
				_debug_log("Unsetting $foreign_property ..from $class now");
				unset($object->$foreign_property);
			}
*/
		}

		/* HAS MANY */
		foreach ($map['fastload'] as $has_many) {

			$has_class = $has_many['class'];

			$name = $has_many['property'];
			$filter = null;

			$my_key = $has_many['right'];
			$his_key = $has_many['left'];

			if (defined('HEAVY_DEBUG')) _debug_log(" $depth > 0 ? $class has many $has_class(s) via properties , mapped to field `$name` ");

			if (isset($object->$name)) {
				_debug_log("Property $name already set");
				continue;
			}

			if (isset($object->$his_key))
				//$filter = $my_key . ' = ' . $this->$his_key;//$filter = array($my_key => $this->$his_key);
				$filter = array($my_key => $object->$his_key);

			if ($has_many['filters']) {
				if ($filter)
					$filter = array_merge($filter, $has_many['filters']);
				else
					$filter = $has_many['filters'];
			}

			if ($filter) {
			    $collection = new ORM_Collection($has_class, $filter, false);
			    $collection->using($this);
				$object->$name = $collection;

				if ($depth < 0 || $depth > 0) {
				    //$this->load($has_class, $filter, $depth - 1);
				    //$collection->load(null, null, $depth - 1);
				}
			}
			else if (!$fatal)
				$object->$name = null;
			else
				throw new Exception("Unable to form $has_class using $filter");				
		}

		/* HAS MANY VIA */
		foreach ($map['slowload'] as $config) {
			$name = $config['property'];

			$has_class = $config['class'];
			$has_table = $config['table'];
			$via_class = $config['via_class'];
			$via_table = $config['via_table'];

			$local_field = $config['left'];
			$remote_field = $config['middle'];
			$remote_hasmany = $config['right'];
			$remote_property = $config['via_property'];

			//er("Add HAS_MANY_VIA to $class");
			//er($config);
			//_debug_log(" $depth > 1 ? $class has many $has_class(s) via single/multiple $via_class object(s) ");

			$object->$name = NULL;

			//er("Single", $config);
			//er("<h1>Checkit</h1>");
			//if (!($depth < 0 || $depth > 1)) continue;
			$link = $this->queryCache($via_class, $remote_field, $object->$local_field); 

			if (!$link) {
				$filter = array($remote_field => $object->$local_field);
				// * * *
				// echo "Now load some more";
				//er("'' $via_class '' Filter", $filter, "local field: $local_field");
				$this->load($via_class, $filter, $depth - 1);
				$links = $this->filterCache($via_class, $filter, TRUE);
				//
				//er("LINKS:", $links, "/LINKS");
			}
			else {
				$links = array($link);
			}
			//er("Lining", $links);
			$ret = array();
			foreach ($links as $link) {
				//if (!isset($link->$remote_property)) $this->assembleOne($link, 2);
				if (!isset($link->$remote_property)) {
					//continue;
					throw new Exception("$via_class object lacks `$remote_property` property");
				}
				$ret[] = $link->$remote_property;
			}
			$object->$name = $ret;
			//exit;
		}

		$object->autofields(false);
		//er("RESULT", $object);
	}

	public function attach($object, $property) {

	}

	public function detach ($object, $property) {

	}

	public function hackFill($fill) {
		$this->heap = $fill;
	}
}
?>