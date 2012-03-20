<?php

require_once('db.php');
define('TEST_QRY', 1);
require_once('qry4.php');

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

	private $tables = array();

	private $cache;
	private static $db;

	private static $static_cache = array();
	private static $object_cache = array();
	
	public static function getDB() {
		if (!self::$db) self::$db = db_init();
		return self::$db;
	}

	public static function useDatabase($db) {
		self::$db = $db;
	}

	public static function getTable($model) {
		if (!isset($tables[$model])) {
			$tables[$model] = self::getDB()->_prefix(). strtolower($model).'s';
		}
		return $tables[$model];
	}

	function Filter($model = null) {
		$q = new QRY();
		return $q;
	}

	function Collection($model, $filter = null, $load = TRUE) {
		$db = self::getDB();
		$col = new ORM_Collection($model, $filter, $db);
		if ($load) $col->load();
		return $col;
	}

	private function UpdateObjectFromArray(&$array, $class_name, $primary, $prefix, $clear = FALSE) {
		$primary_key = $prefix . '.' . $primary;
		$id = $array[$primary_key];
		if (!$id) {
			throw new Exception("NO ID! You SUCK!\n");
		}
		$delets = array();
		if (isset(self::$object_cache[$class_name][$id])) {
			$obj = self::$object_cache[$class_name][$id];
		} else {
			$obj = new $class_name;
		}
		foreach ($array as $key => $value) {
			if (substr($key, 0, strlen($prefix)+1) == $prefix . '.') {
				$obj->$key = $value;
				$delets[] = $arr;
			}
		}
		if ($clear) foreach ($delets as $key) {
			unset($array[$key]);
		}
		self::$object_cache[$class_name][$id] =& $obj;
		return $obj;
	}

	function Sync() {
		$db = self::getDB();//Ensure db is on
		_debug_log("Sync all tables...");
		//public function sync_table($table_name, $values) {
		foreach ( get_declared_classes() as $c ) {
			if (!is_subclass_of($c, 'ORM_Model')) continue;
			/*if (is_callable(array($c, 'SYNC'))) {
				$f = new $c;
				call_user_func(array($f, 'SYNC'), $db);
				continue;
			}*/
			$class_table = self::getTable($c);
			$ref = self::get_reflection($c);
			$db->sync_table($class_table, $ref['fields']);
		}

	}
	function FixClear($class) {
		$db = self::getDB();
		$table = self::getTable($class);
		_debug_log("ORM Truncatte: ".$class);
		$db->set("TRUNCATE ".$table, array());
	}
	function FixInsert($array) {
		$db = self::getDB();//Ensure db is on
		foreach ($array as $class => $entries) {
			foreach ($entries as $entry) {
				$obj = self::Model($class);
				foreach ($entry as $key=>$val) {
					$obj->$key = $val;
				}
				$obj->assemble();
				$obj->save();
				//print_r( $obj->save() );
				//$db->run( $obj->save() );
			}
		}
	}

	function Model($name, $id = null) {
		$db = self::getDB();
		$obj = null;
		if (isset($id)) {
			$q = new QRY();
			$ref = self::get_reflection($name);
			$q->FROM(self::getTable($name));
			$q->SELECT(array_keys($ref['fields']));
			$q->WHERE(array($ref['primary']));
			$q->DATA(array($ref['primary']=>$id));

			/* LEFT JOIN all 'has_one' relationships */
			$joints = array();
			if (isset($name::$has_one)) {
				foreach($name::$has_one as $field => $inno) {
					$class = false;
					if ($inno === true) {
						$class = $field;
					}
					if (!$class) continue;

					$ref2 = self::get_reflection($class);
					$table = $ref2['table'];
					$q->FROM(self::$db->_prefix().$table);
					$q->SELECT(array_keys($ref2['fields']));
					/* A very specific key pair was asked via FOREIGN constraint */
					if (isset ( $ref['foreign'][$table] ) ) {
						$q->ON($ref['foreign'][$table]);
					} else
						/* Pull key names out of our ass */
						$q->ON(array(strtolower($class).'_id' => $ref2['primary']));

					$joints[] = array($field => array('class'=>$class, 'primary'=>$ref2['primary'], 'table'=>$table));  
				}
			}

/*
			foreach ($ref['foreign'] as $table => $inno) {
				$cname = self::class_by_table($table);
				if ($cname === false) echo "UNKNOWN CLASS $table ! <BR>";
				$ref2 = self::get_reflection($cname);
				$q->FROM(self::$db->_prefix().$table);
				$q->SELECT(array_keys($ref2['fields']));
				$q->ON($inno);
			}
*/
			$sql = $q->toRun();

			if (sizeof($joints) == 0) {

				$obj = $db->fetchObject($sql, $name);

				$obj = ($obj ? $obj[0] : null);

			} else {

				$ok = $db->fetch($sql);//, $name);
//print_r($ok);
				if (!$ok) return null;

				$arr = $sql[$q->__toString()];

				foreach ($joints as $joint) {
				foreach($joint as $field => $params) {

				//print_r($params);
					$class_name = $params['class'];
					$primary_key = $params['primary'];
					$table = $params['table'];
//WARNING WHEN needed key is not set...
//if (!$arr[$params['primary']]) continue;
					self::UpdateObjectFromArray($arr, $class_name, $primary_key, $table, TRUE);
				} }

				$obj = self::UpdateObjectFromArray($arr, $name, $ref['primary'], self::getTable($name));

			}

		} else {

			$obj = new $name;

		}
		return $obj;
	}

	function Wrap($object) {
		$class = get_class($object);
		$ref = ORM::get_reflection($class, $tmp, $object);
		if (isset($class::$has_one)) {
		foreach ($class::$has_one as $name => $config) {
			//echo "<hr>";
			if ($config === true) $config = array($name);
			if (isset($config[0])) $subclass = $config[0];
			else list($subclass) = each($config);

			$ref2 = self::get_reflection($subclass);
			$table = $ref2['table'];

			if (isset($ref['foreign'][$table])) {
				list( $field, $subfield ) = each ( $ref['foreign'][$table] );
				//echo "field: $field, fix: $subfield";
			} else {
				// pull key name out of nothing
				if (isset($config[$subclass])) {
					$field = $config[$subclass];
				} else
					$field = strtolower($subclass).'_id';
				$subfield = $ref2['primary']; 
			}
			//echo "<br>For class $class : <br>";
			//echo "Must have a field $name of subclass $subclass with field $subfield to populate var $field";
			/* FORCEFULLY PROTECT THE FIELD... */
			$ok = false; 
			//$object->$field = null;
			//echo "<pre>Current is:".print_r($object,1);
			if (isset($object->$name) && is_object($object->$name)) {
				$subobj =& $object->$name;
				//echo "<pre>".print_r($subobj,1);
			 	if (strtolower(get_class($subobj)) == strtolower($subclass)) {
			 		$object->$field = $subobj->$subfield;
			 		$ok = true;
			 	}
			}
			//echo "OK:".$ok.", $field:".$object->$field;
			if (!isset($object->$field)) {
				throw new Exception("Field `".$field."` can't be deducted for $class because it's `$name` property is NULL");
			}
			//$object->$field = 
			//$object->$name = null;
		} }

	}

	function Insert($object, $fields = array()) {
		$class = get_class($object);
		_debug_log("Inserting object $class");
		$ref = self::get_reflection($class, $tmp, $object);
		//print_r($ref);	exit;

		ORM::Wrap($object);

		$vals = array();
		$keys = array();
		foreach ($ref['fields'] as $name => $sql) {
			if ($name == $ref['primary']) continue;
			if ($fields && in_array($name, $fields)) continue;
			if (!property_exists($object,$name)) throw new Exception("$class::$name is undefined");
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

		$this->identify( $new_id );

		return $new_id;
	}

function Delete($object) {
		$class = get_class($object);
		_debug_log("Deleting object $class");
		$ref = self::get_reflection($class, $tmp, $object);
		$q = new QRY();
		$q->DELETE();
		$q->FROM(ORM::getTable($class));
		$q->WHERE(array($ref['primary']));
		$q->DATA(array($ref['primary']=>$this->id()));

		$db = self::getDB();
		$db->run ( $q->toRun() );

		return true;
}

function Update($object, $fields = array()) {
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
			$vals[] = $object->$name;
			$keys[] = $name;
		}

		$q = new QRY();
		$q->UPDATE(self::getTable($class));
		$q->SET($keys);//array_keys($ref['fields']));
		$q->VALUES($vals);
		$q->WHERE(array($ref['primary']));
		$q->DATA(array($ref['primary']=>$this->id()));

		$db = self::getDB();
//_debug_log("ORM Save:".print_r($q->toRun(),1));
		$db->set ( $q->toRun() );
		
		return true;
	}

	private static function cache_add($key, $val) {
		self::$static_cache[$key] = $val;	
	//	self::$new_cache[] = 'self::$static_cache[\''.$key.'\'] = '. self::php_encode($val) .';'.PHP_EOL;
//		self::cache_flush();		
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
			$agg['table'] = strtolower($class_name).'s';

			$nprops = array();

			if (isset($dummy)) {
				$internal = $dummy->internal_reflection();
				$nprops = $internal['fields'];
				$agg['table'] = $internal['table'];
			}
			
			if (isset($class_name::$_sql)) {
				$nprops = $class_name::$_sql;
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
					if (!preg_match('/FOREIGN KEY REFERENCES (\w+) \((\w+)\)/i', $foreign, $mc)) throw new Exception('Foreign definition "'.$ref.'" must be in innodb format; property '.$name.' in class '. $class_name);
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

	private $db;
	private $model_class;

	public function __construct($model_name, $filters = null, $db = null) {
		//if (!$db) $db = db_init();
		if (!isset($model_name)) throw new Exception('No model name provided');
		//$this->db = $db;
		$this->model_class = $model_name;
		$this->filter = $filters;
	}

	public function loaded() {
		return $this->loaded ? TRUE : FALSE ;
	}

	private function apply_filters($q, $filters) {
		if (isset($filters)) {
			if (is_array($filters)) {
				$keys = array_keys($filters);
				$q->WHERE($keys);
				$q->DATA($filters);
			} else {
				$q->WHERE($filters);			
			}
		}
	}
	
	private function create_query($filters) {
		if (is_object($filters) && get_class($filters) == 'QRY') {
			$q = $filters;
			$ref = ORM::get_reflection($this->model_class);
			$q->FROM(ORM::getTable($this->model_class));
			$q->SELECT(array_keys($ref['fields']));
		} else {
			$q = new QRY();
			$ref = ORM::get_reflection($this->model_class);
			$q->FROM(ORM::getTable($this->model_class));
			$q->SELECT(array_keys($ref['fields']));
			$this->apply_filters($q, $this->filter);		
		}
		return $q;
	}

	public function order_using($by, $values, $reset = false) {
		if (!$this->loaded) throw new Exception("ORDER_USING is not allowed on unloaded ORM_Collections");

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
		   	return;
		}
		if (is_string($this->filter) || is_array($this->filter)) {
			$q = new QRY();
			$q->WHERE($this->filter);
			$this->filter = $q;
		}
		if (is_object($this->filter)) {
			$this->filter->ORDER_BY($by);//, $way); 
		}
	}

	public function load($page = null, $quantity = null) {
		$q = $this->create_query($this->filter);
		//$ref = ORM::get_reflection($this->model_class);
		//$q->FROM(ORM::getTable($this->model_class));
		//$q->SELECT(array_keys($ref['fields']));
		//$this->apply_filters($q, $this->filter);		
		//$q->WHERE(array($ref['primary']));
		//$q->DATA(array($ref['primary']=>$id));
		if ($page && $quantity) {
			$page = round($page); if ($page < 1) $page = 1;
			$quantity = round($quantity); if ($quantity <= 0) $quantity = 0;
			if ($quantity)
				$q->LIMIT( (($page-1) * $quantity) . ','.$quantity);
		}

   		$db = ORM::getDB();
//_debug_log("ORM collection: ".print_r($q->toRun(),1).print_r($this->filter,1));    		
  		$this->data = $db->fetchObject( $q->toRun() , $this->model_class );

		$this->loaded = true;

		return $this->loaded;
	}

	public function filter($new_filter) {
		if ($this->loaded === TRUE) throw new Exception('Chaning filter in loaded collection is not allowed!');
	}

	public function one() {/* new*/
		if ($this->valid()) return $this->current();
		return false;
	}

	public function save($fields=null) {
		if (!$this->loaded) throw new Exception("Can't save unloaded ORM_Collection");
		$t=$ok=0; foreach ($this->data as $item) { $t++; $ok += ($item->save($fields)?1:0); }
		return ($t == $ok ? true : false);	
	}
	
	public function delete() {
		if (!$this->loaded) throw new Exception("Can't delete unloaded ORM_Collection");
		$t=$ok=0; foreach ($this->data as $item) { $t++; $ok += ($item->delete($fields)?1:0); }
		return ($t == $ok ? true : false);
	}

	public function reset() {
		
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
//echo "Loaded: ";var_dump($this->loaded);echo "Counted: ";var_dump($this->counted);
    	if ($this->loaded) return count($this->data);
    	if ($this->counted === FALSE) {
    		$table = ORM::getTable($this->model_class);

    		$q = new QRY();
    		$q->SELECT("COUNT(id)");
    		$q->FROM($table);
    		if ($this->filter)
    		$this->apply_filters($q, $this->filter);
    		$db = ORM::getDB();

    		$res = $db->fetch( $q->toRun() );
//_debug_log("ORM count:".print_r($q->toRun(),1).print_r($res,1));
    		if ($res) {
    			$this->counted = $res[0]['COUNT(id)'];
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
	}
	function offsetUnset($offset) {
		throw new Exception("Can't delete objects from ORM_Collection (yet)");
	}
}

class ORM_Model {

	public static $table_name;

	public function __construct() {
		$this->prepare();
		//if (static::$table_name) {
			//static::$table_name = strtolower(get_class($this)).'s';
		//}
		//$this->reflection = $this->get_reflection($this->scheme_name, 0);
		if ($this->id()) $this->assemble();
	}

	public function prepare() {
		//_debug_log("Preparing <b>" . get_class($this) . "</b><ul>") ;
		$class = get_class($this);
		if (isset($class::$has_many)) {
			foreach ($class::$has_many as $name => $config) {
				//_debug_log("Has many $name: -- {$config[0]}");
				//echo "Filter is ".print_r($config,1);
				if (isset($config[0])) $has_class = $config[0];
				else list($has_class) = each($config);  
				$ref = ORM::get_reflection($has_class);
				//print_r($ref);

				$table = $this->reflection()->table;
				$filter = null;
				if (isset($ref['foreign'][$table])) {
					list ($my_key, $his_key) = each ( $ref['foreign'][$table] );
					if (isset($this->$his_key))
						$filter = $my_key ." = " . $this->$his_key;
					//$filter = array($my_key => $this->$his_key);
				}
				if ($filter) {
					//_debug_log('filter:`'.print_r($filter,1).'`');
					$this->$name = new ORM_Collection($has_class, $filter);
				} else {
					$this->$name = null;
				}
			}
			//unset($this->has_many);
		}
		if (isset($class::$has_one)) {
			foreach ($class::$has_one as $name => $config) {
				//_debug_log("(pre) Has one $name");
				if ($config === true) $config = array(0=>$name);
				if (isset($config[0])) $has_class = $config[0];
				else list($has_class, $nn) = each($config);
				//echo "Making $has_class";
				if (!isset($nn) || !isset($this->$nn)) $nn = $name.'_id';
				//_debug_log("Related field ($nn) :".$this->$nn);
				if (isset($this->$nn)) {
					//_debug_log($nn ."=". $this->$nn. ", Unsetting $nn");
					$this->$name = ORM::Model($has_class, $this->$nn);
					unset($this->$nn);
				} else{
					//_debug_log("$class -> $name = new ORM::Model $has_class, because $nn is unset:");
					$this->$name = ORM::Model($has_class);
				}
				//$this->$nn = $this->$name->id;//'xxx';
			}
			//unset($this->has_one);
		}
		//_debug_log("</ul>");
	}

	protected function autofields($before_assemble) {
		$class = get_class($this);
		if (isset($class::$_auto)) {
			foreach ($class::$_auto as $name => $config) {
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
	}

	private function add_has_many_via() {
		$class = get_class($this);
		if (isset($class::$has_many_via)) {
			foreach ($class::$has_many_via as $name => $config) {
				//echo "Has many $name via: -- {$config}<br>";
				$has_class = key($config);
				$via_class = $config[$has_class][0]; 
				$local_field = $config[$has_class][1]; 
				$remote_field = $config[$has_class][2];
				$remote_hasmany = $config[$has_class][3];
				if (isset($via_class::$has_many) &&
					isset($via_class::$has_many[$remote_hasmany])) {
					$ref = ORM::get_reflection($has_class);
					//echo "<pre>";print_r($ref);echo "</pre>";
					/* Now, we use local_field to find appropriate MIDDLE entries */
					$filter = array($remote_field => $this->$local_field);
					/* And setup next filter based on it */
					$link = ORM::Collection($via_class, $filter)->one();
					
					if ($link) {
						$table = $this->reflection()->table;
						
						$config = $via_class::$has_many[$remote_hasmany];
						if (isset($config[0])) $last_class = $config[0];
						else list($last_class) = each($config);  

						$ref3 = ORM::get_reflection($via_class);
						$ref2 = ORM::get_reflection($last_class);
						//echo "<pre>";print_r($ref2);echo "</pre>";

						$table = $ref3['table'];
						$filter = null;
						//echo "Table is $table";
						if (isset($ref2['foreign'][$table])) {
							list ($my_key, $his_key) = each ( $ref2['foreign'][$table] );
							$filter = $my_key ." = " . $link->$his_key;
							//$filter = array($my_key => $link->$his_key);
						}
						//echo "<hr>Setting last collection:<pre>";
						//_debug_log('filter:`'.print_r($filter,1).'`');
						$this->$name = new ORM_Collection($last_class, $filter);
						//print_r($this->$name);	echo "</pre>";
					} else
						$this->$name = array();//YUCK
				}
			}
			//unset($this->has_many_via);
		}
	}

	public function assemble() {
		$this->autofields(TRUE);
		$this->add_has_many_via();
		//_debug_log("<li>Assembling <b>" . get_class($this) . "</b><ul>");
		$class = get_class($this);
		if (isset($class::$has_many)) {
			foreach ($class::$has_many as $name => $config) {
				//echo "Has many $name: -- {$config[0]}<br>";
				//echo "Filter is ".print_r($config,1);
				if (isset($config[0])) $has_class = $config[0];
				else list($has_class) = each($config); 
				$ref = ORM::get_reflection($has_class);
				//echo "<pre>";print_r($ref);echo "</pre>";
				
				$table = $this->reflection()->table;
				$filter = null;
				if (isset($ref['foreign'][$table])) {
					list ($my_key, $his_key) = each ( $ref['foreign'][$table] );
					$filter = $my_key ." = " . $this->$his_key;
					//$filter = array($my_key => $this->$his_key);
				} else {
					_debug_log("Problem: class $has_class lacks a key with `FOREIGN KEY REFERENCES` ".ORM::getTable($class)." $_sql declaration");
				}
				//_debug_log('filter:`'.print_r($filter,1).'`');
				$this->$name = new ORM_Collection($has_class, $filter);				
			}
			unset($this->has_many);
		}
		if (isset($class::$has_one)) {
			foreach ($class::$has_one as $name => $config) {
				//_debug_log("> Has one $name".print_r($this->$name,1));
				if ($config === true) $config = array(0=>$name);
				if (isset($config[0])) $has_class = $config[0];
				else list($has_class, $nn) = each($config);
				//list($nn) = each(current($config));
				if (!isset($nn) || !isset($this->$nn)) $nn = $name.'_id';
				if (isset($this->$nn)) {
					//_debug_log($nn ."=". $this->$nn);
					$this->$name = ORM::Model($has_class, $this->$nn);
					//_debug_log("unsetting $nn");
					unset($this->$nn);
				} //else _debug_log("$nn Not set");
				//$this->$nn = $this->$name->id;//'xxx';
			}
			//unset($this->has_one);
		}
		//_debug_log("</ul>");
		$this->autofields(FALSE);
	}


	public function load() {

	}

	public function update($fields = null) {
		return ORM::Update($this, $fields);
	}

	public function insert($fields = null) {
		return ORM::Insert($this, $fields);
	}

	public function save($fields = null) {
		if ($this->id()) return $this->update($fields);
		else return $this->insert($fields);
	}

	public function delete() {
		return ORM::Delete($this);
	}

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
		$this->assemble(); }
	public function id() { $p = $this->reflection()->primary; return isset($this->{$p}) ? $this->{$p} : null;	}
	public function describe() {		return $this->reflection()->fields;	}
	public function ascribe() {
		if (!$this->ascription) $this->ascription = array_diff(array_keys($this->describe()) ,	
									array($this->reflection()->primary) );
		return $this->ascription; 
	}

}


?>