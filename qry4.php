<?php
error_reporting(E_ALL);ini_set('display_errors', '1');
class QRY {

	public $tsets = array();		/* */
	public $tables = array();	/* table { name, alias } */
	public $matches = array();	/* match: { left_side, right_side, type } */
	public $list = array();		/* ... */
	public $fsets = array();	/* fset: { field, field, field } */
	public $fields = array();	/* fields: { table_ref, value } */
	public $sets = array();		/* set: { match, match, match } */
	public $changes = array();	/* change: { field, new value } */
	public $csets = array();	/* change: { field, new value } */
	public $jsets = array();	/* .... */
	public $joints = array();	/* joint: { 2 fields used for join } */ 
	public $params = array();	/* param: { name } */
	public $gbsets = array();	/* group_by: { table_ref, value } */
	public $groupbys = array();	/* group_by: { table_ref, value } */
	public $orsets = array();	/* order_by: { table_ref, value, way } */
	public $orderbys = array();	/* order_by: { table_ref, value, way } */

	public $dsets = array();	/* datasets: */
	public $datas = array();	/* data: */
	public $data_ptr = 0;		/* offset into current dataset position

	public $hint	= FALSE;	/* Query Type (SELECT/INSERT/UPDATE/DELETE)|(SHOW/DESCRIBE) */

	public $limit_offset = null;	/* per-query limit (and/or offset), literal */

	public $alias_tables = 0;
	public $alias_fields = 0;
	public $named_params = 0;

	public $hint = '';

	public function evaluate($bit) {
		if (!is_array($bit) || empty($bit) || !isset($bit['type'])) throw new Exception('Cant evaluate malformed $bit'.print_r($bit,1));
		switch ($bit['type']) {
			case 'literal':
				return $bit['value'];
			break;
			case 'none': return '';
			case 'param-ref':
				$param = $this->params[$bit['value']];
				return $this->evaluate($param);
			case 'param':
				return ($this->named_params ? ':'. $bit['value']: '?');
			case 'star':
				return '*';
			case 'bit':
				return $this->evaluate($bit['value']);
			case 'table':
				return $bit['value'].($this->alias_tables ? ' ' . $bit['alias'] : '');
			case 'table-ref':
				$table = $this->tables[$bit['value']];
				return $this->evaluate($table);
			case 'table-set':
				$r = ''; $sep = ''; 
				foreach ($bit['value'] as $table)
				{ 
					$r .= $sep.$this->evaluate($table);
					$sep = ', ';
				}
				return $bit['mix'].' '.$r;
			case 'table-set-ref':
				$tset = $this->tsets[$bit['value']];
				return $this->evaluate($tset);				
			case 'match':
				$this->alias_fields = 0;
				return $this->evaluate($bit['lvalue']).$this->evaluate($bit['cmp']).$this->evaluate($bit['value']);
			case 'match-ref':
				$match = $this->matches[$bit['value']];
				return $this->evaluate($match);
			case 'match-set':
				$sep = ''; $r = '';
				foreach ($bit['value'] as $match) {
					$r .= $sep.$this->evaluate($match);
					$sep = ' '.$bit['glue'].' ';
				}
				$cl = ' ('; $cr = ')';
				if (count($this->sets) < 2) { $cl = ' ';$cr = ''; }
				return $bit['mix'].' '. $cl . $r . $cr;
			case 'match-set-ref':
				$set = $this->sets[$bit['value']];
				return $this->evaluate($set);
			case 'change':
				return $this->evaluate($bit['lvalue']).' = '.$this->evaluate($bit['value']);
			case 'change-ref':
				$change = $this->changes[$bit['value']];
				return $this->evaluate($change);
			case 'change-set-ref':
				$cset = $this->csets[$bit['value']];
				return $this->evaluate($cset);
			case 'change-set':
				if ($bit['mix'] == 'UPDATE') {
					$sep = $r = ''; 
					foreach ($bit['value'] as $change) {
						$r .= $sep.$this->evaluate($change);
						$sep = ', ';
					}
					return 'SET '. $r;
				}
				$sep = $r = $e = ''; 
				foreach ($bit['value'] as $change_ref) {
					$change = $this->changes[$change_ref['value']];
					$r .= $sep.$this->evaluate($change['lvalue']);
					$e .= $sep.$this->evaluate($change['value']);
					$sep = ', ';//.$bit['glue'];
				}
				return '('. $r . ') VALUES (' . $e .')';
			case 'field':
				$table_id = $bit['table-id'];
				$plus = ''; $post = '';
				if ($table_id < 0) $table_id = count($this->tables)-1;
				if ($table_id > -1) {
					$table = $this->tables[$table_id];
					if ($this->alias_tables) $plus = $table['alias'].'.';
					if ($this->alias_fields) $post = ' AS ' . $table['alias'].'__'.$bit['value'];
				}
				return $plus . $bit['value'] . $post;
			case 'field-ref':
				$field = $this->fields[$bit['value']];
				return $this->evaluate($field);
			case 'field-set':
				$r = '';$sep = '';
				foreach ($bit['value'] as $field) {
					$r .= $sep.$this->evaluate($field);
					$sep = ', ';
				}
				return $bit['mix'].' '.$r;			
			case 'field-set-ref':
				$fset = $this->fsets[$bit['value']];
				return $this->evaluate($fset);
			case 'joint':
				$r = $this->evaluate($bit['lvalue']).$this->evaluate($bit['cmp']).$this->evaluate($bit['value']);
				$rtbl_id = $this->fields[ $bit['value']['value'] ]['table-id'];
				$rtbl = $this->tables[ $rtbl_id ];
				return ' LEFT JOIN '.$this->evaluate($rtbl).' ON '.$r; 
			case 'joint-ref':
				$joint = $this->joints[$bit['value']];
				return $this->evaluate($joint);
			case 'joint-set':
				$r = '';
				foreach ($bit['value'] as $joint) {
					$r .= $this->evaluate($joint);
				}
				return $r;
			case 'groupby-set':
				$r = '';$sep = '';
				foreach ($bit['value'] as $field) {
					$r .= $sep.$this->evaluate($field);
					$sep = ', ';
				}
				return $bit['mix'].' '.$r;					
			case 'orderby-set':
				$r = '';$sep = '';
				foreach ($bit['value'] as $field) {
					$r .= $sep.$this->evaluate($field);
					$sep = ', ';
				}
				return $bit['mix'].' '.$r;					
			default:
				throw new Exception('Unrecognized $bit[type] ' . $bit['type']);
			break;
		}
	}
	private $functional = array('SELECT', 'FROM', 'WHERE', 'IN',
	 'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'ON', 
	 'SHOW', 'LIKE', 'DESCRIBE', 'LIMIT', 'GROUP_BY', 'ORDER_BY');
	public function __call($name, $argv) {
		if (in_array(strtoupper($name), $this->functional)) {
			if ($argv) { foreach ($argv as $arg) {
				if (!is_array($arg)) $this->{'parse_'.$name}($arg);
				else if (!empty($arg) && is_numeric(key($arg))) $this->{'list_'.$name}($arg);
				else $this->$name($arg);
			} } else { $this->$name(); }
		} else throw new Exception('Inaccessible method ' .$name);
	}
	private function add_param($name) {
		$this->params[] = array('type'=>'param', 'value'=>$name);
		return (count($this->params)-1);
	}
	private function new_bit($type, $value) { return array('type'=>$type, 'value'=>$value); }
	private function new_match($left, $right, $type) {
		return array('type'=>'match', 'value'=>$right, 'lvalue'=>$left, 'cmp'=>$type);
	}
	private function add_bit($type, $value) {
		$this->list[] = array('type'=>$type, 'value'=>$value);
	}
	private function add_tableset($mix=FALSE) {
		if ($mix === FALSE) $mix = (count($this->tsets) < 1 ? $mix = 'FROM' : $mix = ',');
		$this->tsets[] = array('type'=>'table-set', 'value'=>array(), 'mix'=>$mix);
		return (count($this->tsets)-1); 		
	}	
	private function add_to_tableset($tset, $table, $alias) {
		
		if ($tset == -1) $tset = (count($this->tsets)-1);
		$tid = $this->add_table($table, $alias);
		$this->tsets[$tset]['value'][] = array('type'=>'table-ref', 'value'=>$tid);
	}
	private function add_table($table, $alias) {
		if (!$alias) $alias = $table;
		$this->tables[] = array('type'=>'table', 'value'=>$table, 'alias'=>$alias);
		return (count($this->tables)-1); 
	}
	private function add_field($name, $table=-1, $alias='') {
		if ($table < 0) $table = (count($this->tables)+$table);
		if ($table < 0) $table = 0;
		$this->fields[] = array('type'=>'field', 'value'=>$name, 'table-id'=>$table, 'alias'=>$alias);
		return (count($this->fields)-1); 
	}
	private function add_match($set=-1, $left, $right, $type) {
		$this->matches[] = $this->new_match($left, $right, $type);		
		$this->add_to_matchset($set,  $this->new_bit('match-ref', (count($this->matches)-1) ) );
	}
	private function add_matchset($mix=FALSE, $glue='AND') {
		if ($mix === FALSE) $mix = (count($this->sets) < 1) ? 'WHERE' : 'OR';
		if (count($this->list) && $this->list[count($this->list)-1]['type'] == 'literal') $mix = '';
		$this->sets[] = array('type'=>'match-set', 'value'=>array(), 'glue'=>$glue, 'mix'=>$mix);
		return (count($this->sets)-1); 		
	}
	private function add_to_matchset($set=-1, $bit) {
		if ($set == -1) $set = (count($this->sets)-1);	
		$this->sets[$set]['value'][] = $bit;
		return (count($this->sets)-1); 		
	}

	private function add_change($cset=-1, $field, $val) {
		if ($cset == -1) $cset = (count($this->csets)-1);
		$this->changes[] = array('type'=>'change', 'value'=>$val, 'lvalue'=>$field);//$this->new_bit('change', -1);
		$this->add_to_changeset($cset,  $this->new_bit('change-ref', (count($this->changes)-1) ) );		
	}
	private function add_changeset($mix='INSERT') {
		$this->csets[] = array('type'=>'change-set', 'value'=>array(), 'mix'=>$mix);
		return (count($this->csets)-1); 		
	}
	private function add_to_changeset($cset=-1, $bit) {
		if ($cset == -1) $cset = (count($this->csets)-1);	
		$this->csets[$cset]['value'][] = $bit;
		return (count($this->csets)-1); 		
	}

	private function add_fieldset($mix=',') {
		if (count($this->fsets) < 1) $mix = 'SELECT';
		$this->fsets[] = array('type'=>'field-set', 'value'=>array(), 'mix'=>$mix);
		return (count($this->fsets)-1);
	}
	private function add_to_fieldset($fset=-1, $table=-1, $alias='') {
		$fid = $this->add_field($table, $alias);
		$bit = $this->new_bit('field-ref', $fid);
		if ($fset == -1) $fset = (count($this->fsets)-1);	
		$this->fsets[$fset]['value'][] = $bit;
		return (count($this->fsets)-1);
	}
	private function add_groupbyset($mix=null) {
		if (count($this->gbsets) < 1) $mix = 'GROUP BY';
		$this->gbsets[] = array('type'=>'groupby-set', 'value'=>array(), 'mix'=>$mix);
		return (count($this->gbsets)-1);
	}
	private function add_to_groupbyset($gbset=-1, $table=-1, $alias='') {
		$gbid = $this->add_field($table, $alias);
		$bit = $this->new_bit('field-ref', $gbid);
		if ($gbset == -1) $gbset = (count($this->gbsets)-1);	
		$this->gbsets[$gbset]['value'][] = $bit;
		return (count($this->gbsets)-1);
	}

	private function add_orderbyset($mix=null) {
		if (count($this->orsets) < 1) $mix = 'ORDER BY';
		$this->orsets[] = array('type'=>'orderby-set', 'value'=>array(), 'mix'=>$mix);
		return (count($this->orsets)-1);
	}
	private function add_to_orderbyset($orset=-1, $table=-1, $alias='', $way='ASC') {
		$orid = $this->add_field($table, $alias);
		$bit = $this->new_bit('field-ref', $orid);
		if ($orset == -1) $orset = (count($this->orsets)-1);	
		$this->orsets[$orset]['value'][] = $bit;
		return (count($this->orsets)-1);
	}


	private function add_jointset() {
		
	}
	private function get_field($table_id, $name) {
		if ($table_id < 0) $table_id = count($this->tables)+$table_id; 
	}
	private function add_joint($lkey, $rkey) {
		$lval = $this->new_bit('literal', $lkey);	
		$rval = $this->new_bit('literal', $rkey);
$lfid = $this->add_field($lkey, -2);
$rfid = $this->add_field($rkey, -1);
$lvalue = array('type'=>'field-ref', 'value'=>$lfid);
$rvalue = array('type'=>'field-ref', 'value'=>$rfid);

		$tbls = count($this->tsets);
		$tset = $tbls-1;
	//	$ltbl = $this->tables[ $tbls-2 ];
//		$rtbl = $this->tables[ $tbls-1 ];
		$lset = $this->tsets[ $tbls-2 ];
		$rset =& $this->tsets[ $tbls-1 ];
		
		$rset['mix'] = '';
		$this->joints[] = array('type'=>'joint', 'value'=>$rvalue, 'lvalue'=>$lvalue, 'cmp'=>array('type'=>'literal','value'=>' = '));
		$jid = count($this->joints)-1;
		//$this->tsets[$tset]['value'][] = array(type=>'joint-ref', value=>$jid);
$EEAT = array_shift($rset['value']);
	}



	private function parse_GROUP_BY($str) {
		$gsid = $this->add_groupbyset();
		$gid = $this->add_to_groupbyset($gsid, $str, -1); 
	}

	private function parse_ORDER_BY($str) {
		$orid = $this->add_orderbyset();
		$oid = $this->add_to_orderbyset($orid, $str, -1); 
	}

	private function parse_SELECT($str) {
	/*
		if (strpos($str, '(') !== FALSE) {
			//$this->hint = 'SELECT';
					$fsid = $this->add_fieldset();
			//$this->add_bit('literal', $str);
					$fid = $this->add_to_fieldset($fsid, $str, -1);
			return;
		}*/
		//$this->add_bit('literal', 'SELECT ' . $str);
		//$this->hint = 'SELECT';
		
		/*$fsid = count($this->fsets)-1; 
		if ($fsid < 0) {
			$fsid = $this->add_fieldset();
			$this->add_bit('field-set-ref', $fsid);
		}*/
		$fsid = $this->add_fieldset();
		//$this->add_bit('field-set-ref', $fsid);
		$fid = $this->add_to_fieldset($fsid, $str, -1);
	}
	private function list_SELECT($argv) {
		//$this->hint = 'SELECT';
		$fsid = $this->add_fieldset();
		foreach ($argv as $field) {
			$fid = $this->add_to_fieldset($fsid, $field, -1);
		}
	}
	private function SELECT($argv) {
		//$this->hint = 'SELECT';
	}
	
	public function DELETE() {
		$this->hint = 'DELETE';
	}

	private function parse_FROM($str, $base=FALSE) {
		$words = str_word_count($str, 0, '_');
		if ($words > 2) {
			$defs = explode(',', $str);
			if (count($defs) < $words) {
				foreach ($defs as $def) $this->parse_FROM(trim($def), $base);
			}
		} else if ($words > 0) { 
			@list($table, $alias) = split(' ',$str);
			$tsid = $this->add_tableset($base);
			//if (!count($this->tables)) $this->list[] = array('type'=>'table-set-ref', 'value'=>0, 'mix'=>$base);
			$tid = $this->add_to_tableset($tsid, $table, $alias);
			if ($base) {
			//$this->add_bit('literal', $base);
			//$this->add_bit('table-ref', $tid);
			}
		} else throw new Exception(':(((');
	}
	private function FROM($argv) {
    }

	private function parse_WHERE($str) {
		$words = count(preg_split('/[^a-z]/i', $str));
			if ($str == '(' || $str == ')') {//HORRIBLE HACK
				if (!count($this->sets)) $this->add_bit('literal', 'WHERE');
				$this->add_bit('literal', $str);
				return;
			}
		if ($words > 1) {
			//$this->add_bit('literal', 'WHERE');
			$msid = $this->add_matchset();
			$mid = $this->add_to_matchset($msid, $this->new_bit('literal', $str));
			$this->add_bit('match-set-ref', $mid);
		} else {
			if ($str == 'AND' || $str == 'OR') $this->add_bit('literal', $str);
			else $this->list_WHERE(array($str));
		}
	}
	private function list_WHERE($argv, $cmp='=') {
		$msid = $this->add_matchset();
		foreach ($argv as $name) {
			$fid = $this->add_field($name, -1);
			$pid = $this->add_param($name);
			$lvalue = array('type'=>'field-ref', 'value'=>$fid);
			$rvalue = array('type'=>'param-ref', 'value'=>$pid);
			$cvalue = array('type'=>'literal', 'value'=>' '.$cmp.' ');
			$mid = $this->add_match($msid, $lvalue, $rvalue, $cvalue);
		}
		$this->add_bit('match-set-ref', $msid);
	}
	private function WHERE($argv, $cmp='=') {
		$msid = $this->add_matchset();
		foreach ($argv as $name=>$val) {
			$fid = $this->add_field($name, -1);//
			$lvalue = array('type'=>'field-ref', 'value'=>$fid);
			$rvalue = array('type'=>'literal', 'value'=>$val);
			$cvalue = array('type'=>'literal', 'value'=>' '.$cmp.' ');
			$mid = $this->add_match($msid, $lvalue, $rvalue, $cvalue);
				//$this->add_bit('match-ref', $mid);

				$this->add_data($name, $val);
		}
		$this->add_bit('match-set-ref', $msid);
	}
	
	private function list_INSERT($argv, $base='INSERT') {
		foreach ($argv as $field) {
			$this->parse_INSERT($field, $base);
		}
	}
	private function parse_INSERT($str, $base='INSERT', $mix='INTO') {
		$this->hint = $base;
		$words = count(preg_split('/[^a-z_]/i', $str));
		//if (!count($this->tables)) $this->list[] = array('type'=>'table-set-ref', 'value'=>0, 'mix'=>$mix);
		if ($words == 1) {
			$fid = $this->add_field($str, -1);
			$lvalue = array('type'=>'field-ref', 'value'=>$fid);
			$pid = $this->add_param($str);
			$csid = count($this->csets)-1; 
			if ($csid < 0) {
				$csid = $this->add_changeset($base);
				//$this->add_bit('change-set-ref', $csid);
			}
			$cid = $this->add_change($csid, $lvalue, $this->new_bit('param-ref',$pid));
		}
		if ($words > 1) {
			throw new Exception("Can't parse `$str`, too many words");
			//$this->add_bit('literal', 'WHERE');
			//$msid = $this->add_matchset();
			//$mid = $this->add_to_matchset($msid, $this->new_bit('literal', $str));
			//$this->add_bit('change-ref', $mid);
		} else {
			//if ($str == 'AND') $this->add_bit('literal', $str);
			//else $this->WHERE(array($str));
		}
	}
	private function parse_IN($str) {
	}
	private function list_IN($argv) {
		$lmatch =& $this->matches[ count($this->matches) - 1];
		$lmatch['cmp'] = $this->new_bit('literal',' ');
		$msid = $this->add_matchset('IN', ',');

		$name = $this->evaluate($lmatch['lvalue']);
		$pid = $this->add_param($name);
		foreach ($argv as $name=>$val) {
			//$name = $val;
			//$fid = $this->add_field($name, -1);
			//$pid = $this->add_param($name);
			$rvalue = array('type'=>'none', 'value'=>'none');
			//$lvalue = array('type'=>'param-ref', 'value'=>$pid);
			//$lvalue = array('type'=>'literal', 'value'=>$val);
			$lvalue = $lmatch['value'];
			$cvalue = array('type'=>'literal', 'value'=>'');
			$mid = $this->add_match($msid, $lvalue, $rvalue, $cvalue);

			$this->add_data($name, $val);
		}
			
		$lmatch['value'] = $this->new_bit('match-set-ref', $msid);
		
	}
	private function IN($argv) {
	}

	private function parse_INTO($str) { return $this->parse_FROM($str, 'INTO'); }
	private function INTO($argv) {
    }
    private function parse_VALUES($str) {
    
    }
    private function list_VALUES($argv) {
    	//print_r($argv);
    	$this->DATA($argv);
    }
	private function VALUES($argv) {
	
	}

	private function parse_UPDATE($str) {
		$this->hint = 'UPDATE';
		return $this->parse_FROM($str, ''); 
	}
	
	private function parse_SET($str) {
		$this->parse_INSERT($str, 'UPDATE', '');
		return;
	}
	private function list_SET($argv) {
		$this->list_INSERT($argv, 'UPDATE');
	}

	private function ON($argv) {
		$this->alias_tables = 1;
		foreach ($argv as $lkey=>$rkey) {
			$this->add_joint($lkey, $rkey);
		}
	}

	private function parse_LIMIT($str) {
		$this->limit_offset = $str;
	}

	private function parse_LIKE($str) {
	
	}
	private function list_LIKE($argv) {
		if ($this->hint != 'SHOW') {
			$this->list_WHERE($argv, 'LIKE');
		} else {
			$msid = $this->add_matchset('');
			foreach ($argv as $name) {
				$pid = $this->add_param($name);
				$lvalue = array('type'=>'literal', 'value'=>'');
				$rvalue = array('type'=>'param-ref', 'value'=>$pid);
				$cvalue = array('type'=>'literal', 'value'=>' LIKE ');
				$mid = $this->add_match($msid, $lvalue, $rvalue, $cvalue);
			}
			$this->add_bit('match-set-ref', $msid);
		}	
	}
	private function LIKE($argv) {
	}
	private function DESCRIBE($table_name) {
		
	}
	private function list_DESCRIBE($argv) {
		if (count($argv) != 1) throw new Exception('Can only describe 1 element.');
		$this->hint = 'DESCRIBE';
		$this->list[] = array('type'=>'literal', 'value'=>$argv[0]);
	}
	private function parse_SHOW($str='TABLES') {
		$str = strtoupper($str); 
		if ($str != 'TABLES' && $str != 'DATABASES') throw new Exception('Argument 1 must be DATABASES or TABLES');
		$this->hint = 'SHOW';
		$this->list[] = array('type'=>'literal', 'value'=>' ' . $str);
	}

	public function dump() {
		$sets = array('fsets', 'tsets', 'jsets', 'csets', 'list', 'gbsets', 'orsets');
		$this->jsets = ($this->joints? array(0=>array('type'=>'joint-set', 'value'=>$this->joints))
		: array() );
		$r = '';
		$ground = count($this->tables) - count($this->joints);
		foreach ($sets as $set) {
			$arr =& $this->$set;
			foreach ($arr as $bit) {
				//print_r($bit);
				$r .= $this->evaluate($bit);
				$r .= ' '; 
			}
			//$r .= $this->evaluate($this->$set);
		}
		$r = $this->hint . ' ' . $r;
		if (isset($this->limit_offset)) $r .= ' LIMIT ' . $this->limit_offset;
		return $r;
	}
	public function toRun() {
		$str = $this->__toString();
		$arr = $this->toArray();
		
		return array($str => (is_array($arr) && sizeof($arr) == 1 ? $arr[0] : $arr));
	}
	public function toBatch() {
		return array( (string)$this => $this->toArray() ) ;
	}

	public function __toString() {
		$tmp =  $this->dump();
		$tmp = str_replace("  ", " ", $tmp);
		$tmp = str_replace("  ", " ", $tmp);
		$tmp = str_replace(" , ", ", ", $tmp);
		$tmp = str_replace("  ", " ", $tmp);		
		return trim($tmp); 
	}
	public function toArray() {
		$arr = array();
		$c = 0;
		foreach ($this->dsets as $sett) {
			$p = 0;
			$arr[] = array();		
			foreach ($this->params as $param) {
				$key = $this->evaluate($param);
				if ($key == '?') $key = $p;
				else $key = substr($key, 1);
				$link = $sett[$p]['val'];
				$val = $this->datas[$link];
				$arr[$c][$key] = $val;
				$p++;
			}
			$c++;
		}
		//print_r($this->fields);
		//print_r($arr);
		//print_r($this->dsets);
		return $arr;
		$emp = array();
		$c = 0;
		foreach ($this->dsets as $sett) {
			$emp[] = array();		
			foreach ($sett as $reff) {
				$xx = $this->evaluate($reff);
				//$link = $reff['val'];
				//$val = $this->datas[$link];
				//$emp[$c][] = $val;
			}
			$c++;
		}
		return $emp;
	}
	private function find_field($name) {
		$i = 0;
		foreach ($this->params as $param) {

			if ($param['value'] == $name)
				return $i;
			$i++;
		}
		return -1;
	}
	public function add_data($key, $val) {
		$pos = $this->data_ptr;
		$need_cr = FALSE;
		
		if (is_string($key)) {
			$npos = $this->find_field($key);
			if ($npos < $pos) $need_cr = TRUE;
			$pos = $npos;
			$this->named_params = 1;
		}
		if ($pos > count($this->params)-1) $need_cr = TRUE;
		if ($need_cr) {
			$this->dsets[] = array();
			$pos = 0;
		}

		$last = empty($this->dsets) ? 0 : count($this->dsets) - 1;
		$ds =& $this->dsets[ $last ];
		$this->datas[] = $val;
		$id = count($this->datas)-1;
		$ds[] = array('type'=>'ref', 'val'=>$id);
		$pos++;

		$this->data_ptr = $pos;
	}
	public function DATA($argv) {
		if (!is_array($argv) && !is_object($argv))
			$argv = array($argv);		
		foreach ($argv as $key=>$val) {
			$this->add_data($key, $val);
		}
	}

	static public function asDefine($description) {
		$mid = $sp = ''; 
		if ($description)
		foreach ($description as $k => $v) {	
			$mid .= $sp . $k . ' ' . $v;	
			$sp = ', ';		
		}
		return '(' . $mid . ')';
	}

}

?>