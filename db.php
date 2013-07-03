<?php

global $db_conf;
global $known_db;

$known_db = null;

if (!isset($db_conf))
require_once(constant('APP_DIR').'/db.conf.php');

function print_trace($trace) {
    $out = '';
    $i = 0;
    foreach ($trace as $step) {
        $name = basename(@$step['file']);
        $line = @$step['line'];
        $oclass = '';
        if (isset($step['object'])) $oclass = '('.get_class($step['object']).')';
        $func = $oclass . @$step['class'] . @$step['type'] . $step['function'];
        $out .= '#'. $i . ' ' . $name . ':'. $line . '  '. $func. "\n";
        $i++;
    }
    return $out;
}

function db_init() {
	global $known_db;
	global $db_conf;
	if ($known_db != null) return $known_db;
	if (!$db_conf) {
		global $db_type, $db_host, $db_login, $db_pass, $db_base, $db_utf, $db_prefix;
		$db_conf = array('type'=>$db_type,'host'=>$db_host,'login'=>$db_login,
			'pass'=>$db_pass,'base'=>$db_base, 'utf'=>$db_utf,'prefix'=>$db_prefix,'persist'=>false);
	}
	try {
		$known_db = new db_PDO($db_conf);
	} catch (PDOException $e) {
		echo "Unable to establish a database connection.\n";
		if (defined('DEBUG')) {
			echo "<pre>\n";
			echo "Error: <b>".$e->getMessage()."</b>\n";
			echo "Type: ".$db_conf['type']."\n";
			echo "Address: ".$db_conf['host']."\n";
			echo "Login: ".$db_conf['login']."\n";
			echo "Database: ".$db_conf['base']."\n";
			echo "</pre>";
		};
		exit;
	}
	return $known_db;
}

function db_get() {
	global $known_db; if (!$known_db) db_init();
	$args = func_get_args(); // lame php < 5.3 can't use "func_get_args" as argument 
	return call_user_func_array(array($known_db,'get'), $args);
}
function db_get1() {
	$args = func_get_args();
	$tmp = call_user_func_array('db_get', $args);
	return ($tmp ? $tmp[0] : false) ;
}
function db_getBy() {
	$data = func_get_args();
	$sort = array_pop($data);
	$tmp = call_user_func_array('db_get', $data);
	$ntmp = array();
	foreach ($tmp as $t) 
		$ntmp[$tmp[$sort]] = $tmp;
	return $ntmp;
}
function db_getCol() {
	$data = func_get_args();
	$tmp = call_user_func_array('db_get', $data);
	$ntmp = array();
	foreach ($tmp as $i=>$t) 
		$ntmp[$i] = current($t);
	return $ntmp;
}

function db_set() {
	global $known_db; if (!$known_db) db_init();
	$args = func_get_args();
	return call_user_func_array(array($known_db,'set'), $args);
}

function db_sync($table_name, $values) {
	global $known_db; if (!$known_db) db_init();
	return $known_db->sync_table($table_name, $values);
}

class db_PDO {

	protected $pdo;
	private $type;

	private $report = array('queries'=>0, 'fetches'=>0);
	
	private $known_tables = array();//mini-cache
	
	private $prefix;

	public function __construct($conf) {
		$lazy = array('utf', 'type', 'host', 'login', 'pass', 'base', 'prefix', 'persist');
		foreach ($lazy as $lazy_one) if (!isset($conf[$lazy_one])) throw new Exception ('No ->' .$lazy_one . ' property in array passed as pdo config!'); 
		$conf['dsn'] = $conf['type'].':host='.$conf['host'].";dbname=".$conf['base'];
		$conf['flags'] = ($conf['utf'] ? array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8") : array());
		$pdo = new PDO($conf['dsn'], $conf['login'], $conf['pass'], $conf['flags']);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		if ($conf['persist']) $pdo->setAttribute(PDO::ATTR_PERSISTENT, true);
		//$this->cache_file = $conf['cache_file'];
		//$this->known_tables = json_decode(@file_get_contents($this->cache_file), 1);
		$this->pdo = $pdo;
		$this->type = $conf['type'];
		$this->prefix = $conf['prefix'];
	}

	/** PUBLIC INTERFACE **/
	public function _prefix() {
		return $this->prefix;
	}
	public function _report() {
		return $this->report;
	}

	public function get() {
		$query = func_get_arg(0);
		$data = null;
		if (func_num_args() > 1)
		{
			$data = func_get_args();
			$tmp = array_shift($data);
			if (sizeof($data) == 1 && is_array($data[0])) /* allow second arguments to be an array */
			{
				$data = $data[0];
			}
		}
		$query = str_replace('#__', $this->prefix, $query);

		$sql = array($query=>$data);

		$ret = $this->fetch($sql);
		return $ret;
	}

	public function set() {
		$query = func_get_arg(0);
		$data = null;
		if (func_num_args() > 1)
		{
			$data = func_get_args();
			array_shift($data);
			if (isset($data[0]) && is_array($data[0])) $data = $data[0];
		}
		if (is_array($query)) {
			list($query, $data) = each($query);
		}
		$query = str_replace('#__', $this->prefix, $query);
		
		$sql = array($query=>$data);

		if (isset($data[0]) && is_array($data[0]))
			$ret = $this->batch($sql);
		else
			$ret = $this->run($sql);
		
		return $this->pdo->lastInsertId();
	}

	public function sync_table($table_name, $values, $dry = false) {
		$table_name = str_replace('#__', $this->prefix, $table_name);
		$old_values = $this->describe_table($table_name);
		$diff = $this->diff_table($old_values, $values, $table_name, FALSE);
		$sql = array();
		foreach ($diff as $q) {
			$sql[$q] = null;
		}
		if ($dry) return $sql;
		if ($this->batch($sql)) return $table_name;
		else return FALSE;
	}

	/* Core API. */
	public function fetchObject(&$sql, $name='stdClass', $ctor_args = null) {
		foreach($sql as $query=>$data) {
			try {
				$stm = $this->pdo->prepare($query);
				$stm->execute($data);
			$this->report['queries']++;
			_debug_log("SQL:". $query);
			} catch (PDOException $e) {
				throw new Exception( $e->getMessage() . PHP_EOL . ' ' . join(' ', $e->errorInfo) . ' in query "' . $query . '",'.PHP_EOL.' dataset '."'" . join("','",$data) ."'" );
			}
			$sql[$query] = $stm->fetchAll(PDO::FETCH_CLASS, $name, $ctor_args);
		$this->report['fetches']++;
		//static $wtf = 0;
		//if ($wtf++) throw new Exception(	"SQL:". $query);
		}
		return ($query ? $sql[$query] : NULL);
	}

	public function fetch(&$sql, $mode = PDO::FETCH_ASSOC) {
		foreach($sql as $query=>$data) {
		_debug_log("FETCH SQL:". str_replace(array("FROM","LEFT","WHERE"), array("\nFROM", "\nLEFT", "\nWHERE"), preg_replace("#SELECT (.*?) FROM#", 'SELECT * FROM', $query)));//."|".print_trace(debug_backtrace())
			try {
				$stm = $this->pdo->prepare($query);
				$stm->execute($data);
			$this->report['queries']++;
			} catch (PDOException $e) {
				throw new Exception( $e->getMessage() . PHP_EOL . ' ' . join(' ', $e->errorInfo) . ' in query "' . $query . '",'.PHP_EOL.' dataset '."'" . (is_array($data) ? join("','",$data) : 'X') ."'" );
			}
			$sql[$query] = $stm->fetchAll($mode);
		$this->report['fetches']++;
		}
		return ($query ? $sql[$query] : NULL);
	}

	public function fetchBatch(&$sql, $mode = PDO::FETCH_ASSOC) {
		foreach($sql as $query=>$datas) {
			$stm = $this->pdo->prepare($query);
			$this->report['queries']++;
			try {
				foreach ($datas as $k=>$data) {
					$r = $stm->execute($data);
					$sql[$query][$k] = $stm->fetchAll($mode);
				}
			} catch (PDOException $e) {
				throw new Exception( $e->getMessage() . PHP_EOL . ' ' . join(' ', $e->errorInfo) . ' in query "' . $query . '",'.PHP_EOL.' dataset '."'" . join("','",$data) ."'" );
			}
		$this->report['fetches']++;
		_debug_log("BATCH FETCH SQL:". $query);
		}
		return $stm;
	}

	public function run(&$sql) {
		$stm = NULL;
		if (!is_array($sql)) throw new Exception('Passed SQL is not an array');
		foreach($sql as $query=>$data) {
			if (is_numeric($query)) throw new Exception("NUMERIC QUERY");
			if (!is_array($data)) throw new Exception("STRING DATA");
			try {
				$stm = $this->pdo->prepare($query);
				$stm->execute($data);
			$this->report['queries']++;
			_debug_log("SQL:". $query  );
				$sql[$query] =& $stm;				
			} catch (PDOException $e) {
				throw new Exception( $e->getMessage() . PHP_EOL . ' ' . join(' ', $e->errorInfo) . ' in query "' . $query . '",'.PHP_EOL.' dataset '."'" . join("','",$data) ."'" );
			}
		}
		return $stm;
	}

	public function batch(&$sqlb) {
		if (!$this->pdo->beginTransaction()) throw new Exception('Cannot begin transaction');
		@$this->report['transact']++;#count

		foreach($sqlb as $query=>$datas) {
			$stm = $this->pdo->prepare($query);
		$this->report['queries']++;#count
		_debug_log("SQL:". $query);
		if (is_numeric($query)) throw new Exception("NUMERIC QUERY");
		if ($datas && !@is_array($datas[0])) throw new Exception("STRING DATA");
			$err_data = array();//just for error reporting
			try {
				if ($datas)	foreach ($datas as $k=>$data)
				{
					$sqlb[$query][$k] =& $stm;
					$err_data = &$data;
					$r = $stm->execute($data);
				}
				else {
					$sqlb[$query] =& $stm;
					$stm->execute(NULL);
				}
			} catch (PDOException $e) {
				$this->pdo->rollback();
				throw new Exception( $e->getMessage() . PHP_EOL . ' ' . join(' ', $e->errorInfo) . ' in query "' . $query . '",'.PHP_EOL.' dataset '."'" . join("','",$err_data) ."'" );
			}
		}
		return $this->pdo->commit();
	}

	/* SQL-SYNC Api. Extend this class to get it. */
	protected function describe_table($table_name) {

		/* Try the mini-cache first */
		if (isset($this->known_tables[$table_name])) 
			return $this->known_tables[$table_name];

		$exists = (($tmp = 
			$this->get("SHOW TABLES LIKE '".$table_name."';")) ? sizeof($tmp): 0);
		if (!$exists) return NULL; 	

		/* Ask the DB */
		$fields = $this->get("DESCRIBE " . $table_name);
		$desc = array();	
		foreach ($fields as $field_x) {
			$field = (array) $field_x;
			$key = $field['Field'];
			$val = strtoupper($field['Type']);
			$val .= ( strpos($field['Null'], 'NO') !== false ? " NOT NULL" : "" );
			$val .= ( strpos($field['Key'], 'PRI') !== false ? " PRIMARY KEY" : "" );
			$val .= ( strpos($field['Key'], 'UNI') !== false ? " UNIQUE" : "" );
			$val .= ( strpos($field['Key'], 'MUL') !== false ? " INDEX" : "" );
			$val .= ( strpos($field['Extra'], 'auto_increment') !== false ? " AUTO_INCREMENT" : "" );
			$desc[$key] = $val;
		}

		/* Save in mini-cache */
		$this->known_tables[$table_name] = $desc;

		/* Return the description */
		return $desc;
	}

	protected function diff_table($old_values, $values, $table = '#__', $widen = TRUE, $save = TRUE) {

		$desc = array();

		/* Return value */
		$sql = array();

		if ($old_values) { /* Possible Alter */
			$old_keys = array_keys($old_values);		
			$new_keys = array_keys($values);

			$del_keys = array_diff($old_keys, $new_keys);
			$add_keys = array_diff($new_keys, $old_keys);

			$all_keys = array_merge($old_keys, $new_keys);
			$all_keys = array_diff($all_keys, $add_keys, $del_keys);
			$all_keys = array_unique($all_keys);

			foreach ($all_keys as $key) {
				$nv = $values[$key];
				$ov = $old_values[$key];
				if ($nv != $ov) { /* CHANGED! */
					if ($widen) $nv = $this->widen_field($ov, $nv);
					//echo "<h4>$nv VS $ov </h4>\n";
						
					if ($nv != $ov) {
						$dnv = $nv;
						$dov = $ov;
						if (stripos($dnv, "AUTO_INCREMENT") === FALSE && stripos($dov, "AUTO_INCREMENT") !== FALSE) {
							$sql[] = "ALTER TABLE " . $table . " MODIFY " . $key . " ". $nv;
						}
						/* Both have primary keys */
						if (stripos($dnv, "PRIMARY KEY") !== FALSE && stripos($dov, "PRIMARY KEY") !== FALSE) {
							$dnv = str_replace("PRIMARY KEY", "", $dnv);
							$dov = str_replace("PRIMARY KEY", "", $dnv);
						}						
						if (strpos($nv, "PRIMARY KEY") === FALSE && strpos($ov, "PRIMARY KEY") !== FALSE)
						{
							$dnv = str_replace("PRIMARY KEY", "", $dnv);
							$dov = str_replace("PRIMARY KEY", "", $dnv);
							$sql[] = "ALTER TABLE " . $table . " DROP PRIMARY KEY";
						}
						if (stripos($nv, "UNIQUE") === FALSE && stripos($ov, "UNIQUE") !== FALSE)
						{
							$dnv = str_replace("UNIQUE", "", $dnv);
							$dov = str_replace("UNIQUE", "", $dov);
							//$sql[] = "ALTER TABLE " . $table . " DROP UNIQUE " . $key;
							$sql[] = "ALTER TABLE " . $table . " DROP INDEX " . $key;
						}
						if (stripos($nv, "INDEX") === FALSE && stripos($ov, "INDEX") !== FALSE)
						{
							$dnv = str_replace("INDEX", "", $dnv);
							$dov = str_replace("INDEX", "", $dov);
							$sql[] = "ALTER TABLE " . $table . " DROP INDEX " . $key;
						}
						if (stripos($nv, "INDEX") !== FALSE && stripos($ov, "INDEX") === FALSE)
						{
							$dnv = str_replace("INDEX", "", $dnv);
							$dov = str_replace("INDEX", "", $dov);
							$sql[] = "ALTER TABLE " . $table . " ADD INDEX (" . $key. ")";
						}
						if (stripos($nv, "FOREIGN KEY") !== FALSE && stripos($ov, "FOREIGN KEY") === FALSE)
						{
							preg_match("/FOREIGN KEY (.*)$/", $dnv, $nrest);
							preg_match("/FOREIGN KEY (.*)$/", $dov, $orest);
							$dnv = str_replace($nrest[0], "", $dnv);
							$dov = str_replace($orest[0], "", $dov);
							$sql[] = "ALTER TABLE " . $table . " ADD FOREIGN KEY (" . $key. ") " . $nrest[1];
						}
						$dnv = trim($dnv);
						$dov = trim($dov);
						if (strtoupper($dnv) != strtoupper($dov)) {
							$sql[] = "ALTER TABLE " . $table . " CHANGE COLUMN " . $key . " " . $key . " " . $dnv;
						}
					}
				}
				$desc[$key] = $nv;
			}
			foreach ($add_keys as $key) {
				$nv = $values[$key];
				$late = array();
				if (strpos($nv, "INDEX") !== FALSE)
				{
					$nv = str_replace("INDEX", "", $nv);
					$late[] =  "ALTER TABLE " . $table . " ADD INDEX (" . $name. ")"; 
				}
				$sql[] = "ALTER TABLE " . $table . " ADD COLUMN " . $key . " " . $nv;
				$desc[$key] = $nv;
				$sql += $late;
			}
			foreach ($del_keys as $key) {
				$sql[] = "ALTER TABLE " . $table . " DROP COLUMN " . $key;
			}
		} else { /* Create */
			$late = array();
			$defs = array();
			foreach ($values as $name=>$type) {
				if (strpos($type, 'FOREIGN KEY') !== FALSE)
				{
					preg_match("/FOREIGN KEY (.*)$/", $type, $rest);
					$type = str_replace($rest[0], "", $type);
					$late[] =  "ALTER TABLE " . $table . " ADD FOREIGN KEY (" . $name. ") ".$rest[1];
				}
				if (strpos($type, "INDEX") !== FALSE)
				{
					$type = str_replace("INDEX", "", $type);
					$late[] =  "ALTER TABLE " . $table . " ADD INDEX (" . $name. ")"; 
				}
				$defs[] = $name . ' ' . $type;
				$desc[$name] = $type;
			}
			$sql[] = "CREATE TABLE " . $table . ($defs ? " (" . join(', ', $defs) . ") " : "");
			$sql += $late;
		}
		/* Save this info */
		if ($save) $this->known_tables[$table] = $desc;
		//$this->cache_dump = 1;
_debug_log("ALTER-SQL:".print_r($sql,1));
		return $sql;
	}

	/* Given 2 fields in MySQL format, return one that can contain both */
	protected function widen_field($from, $to)
	{
		preg_match('#(\w+)\((\d+)\)(.*)#', $from, $mc1);
		preg_match('#(\w+)\((\d+)\)(.*)#', $to, $mc2);
		$to_name = $mc2[1];
		$to_size = $mc2[2];
		$to_rest = $mc2[3];
		$from_name = $mc1[1];
		$from_size = $mc1[2];
		$from_rest = $mc1[3];
		
			$nval = $to;
		/* Always stay as 'TEXT' */
		if ($to_name == 'TEXT' || $from_name == 'TEXT') {
			$nval = 'TEXT';
		}
		else
		/* Always stay as 'BLOB' */
		if ($to_name == 'BLOB' || $from_name == 'BLOB') {
			$nval = 'BLOB';
		}
		else
		/* Must become a string */
		if ($to_name == 'VARCHAR') {
			/* Was a string */
			if ($from_name == 'VARCHAR') $nval = $to_name.'('.max($to_size, $from_size).')';
			/* Was a number (calc correct from_size) */
			else $nval = $to_name.'('.max($to_size, $from_size).')';
		}
		/* Must become a number */
		else {
			/* Was a string -- stay as string (but calc correct to_size) */
			if ($from_name == 'VARCHAR') $nval = $from_name.'('.max($to_size, $from_size).')';
			/* Was a number -- var int */
			else $nval = 'INT'.'('.max($to_size, $from_size).')';
		}
		return $nval.$to_rest;
	}
	
	public function report() {
		$this->report['execs'] = $this->report['queries'] - $this->report['fetches'];
		return $this->report;
	}
}

?>