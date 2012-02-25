<?php

function db_filesafe($dir=null, $name='media', $view=false) {
	$db = db_init();
	global $known_fs;
	global $db_conf;
	if ($known_fs != null) return $known_fs;
	if (!$db_conf) { $db_conf = array(); }
	if (isset($dir)) $db_conf['fs_dir'] = $dir;
	if (isset($name)) $db_conf['fs_name'] = $name;
	if (isset($view)) $db_conf['fs_view'] = $view;
	if (!isset($db_conf['fs_dir'])) {	global $fs_dir;
		$db_conf['fs_dir'] = $fs_dir;
	}
	if (!isset($db_conf['fs_name'])) {	global $fs_name;
		$db_conf['fs_name'] = $fs_name;
	}
	if (!isset($db_conf['fs_view'])) {	global $fs_view;
		$db_conf['fs_view'] = $fs_view;
	}
	$known_fs = new FileSafe($db, $db_conf);
	return $known_fs;
}

function ms_mime($path, $suggested='application/force-download') {
	if (function_exists('finfo_file')) {
		$finfo = finfo_open(FILEINFO_MIME);
		$mtype = finfo_file($finfo, $path);
		finfo_close($finfo);
	} else if (mime_content_type('finfo_file')) {
		$mtype = mime_content_type($path);
	}
	return ($mtype ? $mtype : $suggested);
}

class FileSafe extends db_PDO {

	private $db;

	private $dir;
	private $name;

	private $table_files, $table_stats, $table_links, $view_table = null;

	function __construct($conf, $conf2=null) {
		if (is_object($conf) && is_subclass_of($conf, "db_PDO") || $conf instanceof db_PDO) {
			$this->db = $conf;
			$conf = $conf2;
		} else {
			parent::__construct($conf);
			$this->db = $this;
		}
		$this->pdo = $this->db->pdo;		
		if (!is_array($conf)) throw new Exception ('Passed argument is not a config array.');
		$lazy = array('fs_dir', 'fs_name', 'fs_view');
		foreach ($lazy as $lazy_one) if (!isset($conf[$lazy_one])) throw new Exception ('No [' .$lazy_one . '] index in array passed as FileSafe config!');
		$this->dir = $conf['fs_dir'];
		$this->name = $conf['fs_name'];
		$this->table_files = '#__'.$this->name.'_files';
		$this->table_stats = '#__'.$this->name.'_stats';
		$this->table_links = '#__'.$this->name.'_links';
		if ($conf['fs_view']) $this->view_table = '#__'.$this->name.'_view';
		if (!file_exists($this->dir.'/write.test') || 1) {
			if (!$this->make_tables()) throw new Exception("Unable to create tables");
			/* Perform tests and throw exceptions */
			if (!file_put_contents($this->dir.'/write.test',1)) throw new Exception("Unable to write to directory ".$this->dir);
			if (!chmod($this->dir.'/write.test', 0666)) throw new Exception("Unable to chmod in directory ".$this->dir); 
		} 
	}

	private function make_tables() {
		$this->table_stats = $this->sync_table($this->table_stats, array(
		'id'=>'INT(9) PRIMARY KEY AUTO_INCREMENT',
		'path'=>'VARCHAR(1024)',
		'mtime'=>'DATETIME',
		'atime'=>'DATETIME',
			)		
		);
		$this->table_files = $this->sync_table($this->table_files, array(
		'id'=>'INT(9) PRIMARY KEY AUTO_INCREMENT',
		'hash'=>'VARCHAR(64)',
		'name'=>'VARCHAR(1024)',
		'path'=>'VARCHAR(1024)',
		'mime'=>'VARCHAR(255)',
		'size'=>'INT(9)',
		'title'=>'VARCHAR(255)',
		'description'=>'TEXT',
			)		
		);
		$this->table_links = $this->sync_table($this->table_links, array(
		'id'=>'INT(9) PRIMARY KEY AUTO_INCREMENT',
		'file_id'=>'INT(9) INDEX',
		'stat_id'=>'INT(9) INDEX',
		'depth'=>'INT(9)',
			)
		);

		return TRUE;
	}

	public function modRewrite() {
	
	}
	private function rdir($apath) {
		if (!$apath) throw new Exception("Must provide a path");
		$base = $this->dir;
		$path = $base;
		foreach ($apath as $dir) {
			$path .= '/'.$dir;
			//echo "Checking $path \n";
			if (!is_dir($path)) {
				mkdir($path);
				chmod($path, 0777);
			}
		}
	}
	private function file_extension($file) {
		$basename = basename($file);
		$arr = preg_split('#\.#', $basename);
		if (sizeof($arr) < 2) return false;
		return $arr[sizeof($arr)-1];
	}
	public function add($file, $vpath, $sname='') {
		if (!is_array($vpath)) $vpath = preg_split('#/|\.#', $vpath, -1, PREG_SPLIT_NO_EMPTY);
		$this->rdir($vpath);
		
		$id = $this->insert($file, $vpath, $sname);
		//echo "ID: $id \n";
	}
	private function insert($file, $vpath, $title, $desc='') {
		if ($file == 'folder/0') throw new Exception('wtf?');
		if (is_array($vpath)) {
			$apath = $vpath;		
			$vpath = join('/', $apath);
		} else {
			$apath = preg_split("#/#", $vpath);
		}
		if ($vpath == '.') $vpath = '';
		//if (substr($vpath,-1) != '/') $vpath .= '/'; 
		$basename = basename($file);
		$hash = md5_file($file);
		$size = filesize($file);
		$mime = ms_mime($file);
		//echo "<li>full file: $file";
		//echo "<li>need to insert file $basename -- $hash || $vpath ";
		$dup = $this->db->get("SELECT id, path FROM ".$this->table_files." WHERE hash = ? AND path = ? LIMIT 1", $hash, $vpath.($vpath?'/':''));

		if ($dup) return $dup[0]['id'];
		$id = $this->set("INSERT INTO ".$this->table_files.
		" (path, hash, name, size, mime, title, description) VALUES (?,?,?,?,?,?,?)",
		$vpath.($vpath?'/':''), $hash, $basename, $size, $mime, $title, $desc);

		$max = sizeof($apath)-1;
		$cpath = '';
		foreach ($apath as $i=>$step) {
			$cpath .= ($cpath?'/':'').$step;
			$stat = $this->get("SELECT id, path FROM ".$this->table_stats." WHERE path = ? LIMIT 1", $cpath.($cpath?'/':''));
			$stat_id = -1;
			if (!$stat) {
				$stat_id = $this->set("INSERT INTO ".$this->table_stats." (mtime, path) VALUES (FROM_UNIXTIME(?), ?)", 0, $cpath.($cpath?'/':''));		
	 		} else {
				$stat_id = $stat[0]['id'];
			} 

			$depth = $max - $i;
			$n_id = $this->set("INSERT INTO ".$this->table_links.
				" (file_id, stat_id, depth) VALUES (?,?,?)",
				$id, $stat_id, $depth);
				//echo "<li>Inserted $n_id</li>";
		}

		//echo "<li> Now insert all links</li>";	print_r($apath);

		return $id;
	}

	public function select_sql($whereString,$fields='f.id, f.name, s.path, l.depth', $raw=FALSE) {
		if ($raw || !$this->view_table) {
			if (!$raw) {
					 $fields = preg_replace('#(\w+?)\s+?(=|>=)#', 'f.'.'\1 \2', $fields);
				$whereString = preg_replace('#(\w+?)\s+?(=|>=)#', 'f.'.'\1 \2', $whereString);
			}
			$query = 
				'SELECT '.$fields .' FROM '.$this->table_files.' f '.
				'JOIN '.$this->table_links.' l ON f.id = l.file_id '.
				'JOIN '.$this->table_stats.' s ON l.stat_id = s.id '.
				$whereString;
		} else
		if ($this->view_table) {
			$query = 
				'SELECT * FROM '.$this->table_files.' '.
				$whereString;
		}
		return $query;
	}

	private function delete_sql($id) {
		$sql=array();
		if (is_array($id) && sizeof($id) == 1) $id = current($id);
		if (is_array($id)) {
			$ids = join(',', $id);
			$sql["DELETE FROM ".$this->table_files. " WHERE id IN (".$ids.")"] = null;
			$sql["DELETE FROM ".$this->table_links. " WHERE file_id IN (".$ids.")"] = null;
		} else {
			$sql["DELETE FROM ".$this->table_stats." WHERE id = ? LIMIT 1"] = array($id);
			$sql["DELETE FROM ".$this->table_links." WHERE id = ? LIMIT 1"] = array($id);
		}
		return $sql;
	}

	public function delete($id) {
		if (!$id) return FALSE;
		return $this->db->run($this->delete_sql($id));
	}
	public function findId($name, $path) {
		$path = rtrim($path,'/').($path?'/':'');
		$query = $this->select_sql("WHERE f.name = ? AND s.path = ?", "f.id AS id", TRUE);
		$sql = array($query => array($name, $path));
		$res = $this->db->fetch($sql);
		if ($res) {
			$id = $res[0]['id'];
		} else return FALSE;
		return $id;
	}
	public function deleteByName($name, $path) {
		return $this->delete($this->findId($path, $recursive));
	}
	public function findPath($path, $recursive=FALSE) {
		$path = rtrim($path, '/');

		$where = "WHERE s.path = ?". (!$recursive ? " AND depth = 0" : '');
		$data = array($path.($path?'/':''));

		$query = $this->select_sql($where, "DISTINCT f.id", TRUE);//,f.name,f.path

		$sql = array($query => $data);

		$tmp = $this->fetch($sql);
		if (!$tmp) return FALSE;		

		$ids = array();
		foreach ($tmp as $i=>$t) 
			$ids[$i] = current($t);//$t['id'];

		return $ids;
	}
	public function deletePath($path, $recursive=FALSE) {

		return $this->delete($this->findPath($path, $recursive));
	}
	public function ls($path, $recursive=FALSE) {
		$ids = $this->findPath($path, $recursive);
		if ($ids) {
			$query = $this->select_sql('WHERE id IN ('.join(',', $ids).')');
			$sql = array($query => $data);

			$tmp = $this->fetch($sql);
			print_r($tmp);	
		} 
	
	}


	public function unix_sync($start='') {
		$files = array();
		$this->unix_syncV($start, 0, $files);
		//echo "<hr>";print_r($files);
	}
	public function clean_db($vpath='') {
		//echo "GOTTA CLEAN DB ".$vpath."\n";
		srand();
		$max = $this->get("SELECT COUNT(id) as num FROM ".$this->table_files." WHERE path LIKE ?", $vpath.'/%');
		$num = $max[0]['num'];
		$batch = 255;
		$pages = round($num / $batch);
		$page = round(rand(0, $pages-1));
		/* Gotta process ($batch) files, starting from offset ($page * $batch) */ 
		$bulk = $this->get("SELECT * FROM ".$this->table_files." WHERE path LIKE ? LIMIT ".$page * $batch.", ".$batch, $vpath.'/%');
		//echo "SELECT * FROM ".$this->table_files." WHERE path LIKE ? LIMIT ".$page * $batch.", ".$batch, $vpath.'/%';
		$del = array();
		foreach ($bulk as $file) {
			if (!file_exists($this->dir.'/'.$file['path'].'/'.$file['name'])) {
				echo "<li>no such file--".$this->dir.'/'.$file['path'].'/'.$file['name']."--";
				$del[] = round($file['id']);
			}
		}
		if ($del) $this->delete($del);
	}
	public function unix_syncV($vpath, $max, &$files) {

		//echo "<li>Entering `$vpath`</li>";echo "<ul>";
		echo "SELECT *, UNIX_TIMESTAMP(mtime) as mtime FROM ".$this->table_stats." WHERE path LIKE ?", $vpath.($vpath?'/':'').'%';
		$stats = $this->get("SELECT *, UNIX_TIMESTAMP(mtime) as mtime FROM ".$this->table_stats." WHERE path LIKE ?", $vpath.($vpath?'/':'').'%');
		$nstats = array();
		foreach ($stats as $stat) $nstats[rtrim($stat['path'],'/')] = $stat['mtime'];

		$root = (substr($this->dir, -1) == '/' ? '' : './' ) . $this->dir;

		$newmax = array();

		//echo "stats:";		print_r($stats);		print_r($nstats);
		
		$ttime = (isset($nstats[$vpath]) ? $nstats[$vpath] : -1);
		//echo ;
		//mkdir('/tmp/delme/');

		$tmp_file = '/tmp/'.'xx';//uniqid();
		$test_file = $root ."/".$vpath;
		$test_file = $tmp_file;
		touch($test_file, $ttime);//$nstats[$vpath]);
		$f = 'find '.$root.'/'.$vpath." -newer ".$test_file.' -printf "%T@\t%p\n"';
		//echo "<li><u>$f </u>".date("Y-m-d H:i:s", $ttime);
		$cfiles = array();
		$p = popen($f, 'r');
		//echo "<ul>CHANGES SINCE $ttime:";
		while (!feof($p)) {
			$f = fgets($p,1024);
			if (!strlen($f)) {
				continue;
			}
			$v = preg_split("#\.|\t#", $f, 3, PREG_SPLIT_NO_EMPTY);
			$z = substr(trim($v[2]), strlen($root)+1);
			$v[2] = $z;
			//echo "<li><b>yowza==";			print_r($f);			echo "</b>";
			if ($z === 0) $z = '';
			$cfiles[$z] = $v[0];
		}
		//echo "</ul>";
		pclose($p);
		//echo "</ul>";
		///echo "Merging changes:\n";
		//print_r($cfiles);
		//echo "<ul>";
		$dirs = array();
		foreach ($cfiles as $vname=>$mtime) {
			if ($vname === 0) $vname='';
			//echo "<li>What is $vname";
			if (is_dir($this->dir.'/'.$vname)) {
				//echo "<li>Remembering dir $vname";
				$dirs[$vname] = 2;
				$xtime = filemtime($this->dir.'/'.$vname);
				if (!isset($newmax[$vname])) $newmax[$vname] = 0;
				$newmax[$vname] = max($newmax[$vname], $xtime);
			} else {
				$xpath = dirname($vname);
				if ($xpath == '.') $xpath = '';
				$file = basename($vname);
				//echo "<li>Doing file $file | $xpath <li>";
				$xtime = filemtime($this->dir.'/'.$vname);
				if (!isset($newmax[$xpath])) $newmax[$xpath] = 0;
				$newmax[$xpath] = max($newmax[$xpath], $xtime);
				$dirs[$xpath] = (!isset($dirs[$xpath]) ? 1 : 2); 
				//if ($file == '.' || $file == '..') continue;
				$id = $this->insert($this->dir.'/'.$vname, $xpath, '');
			}
		}
		//print_r($newmax);
		foreach ($dirs as $vname=>$typ) {
			$prev = $vname;
			if (!isset($newmax[$vname])) continue;
			while (($prev = dirname($prev))) {
				if ($prev == '.') $prev = '';
				if (!isset($newmax[$prev])) $newmax[$prev] = 0;
				$newmax[$prev] = max($newmax[$prev], $newmax[$vname]);
				//echo "\nSAVING IN $prev -- {$newmax[$prev]}\n";
			}
			if ($typ == 2) $this->clean_db($vname);
		}		
		
		$this->update_stats($newmax, $nstats);
		//echo "</ul>";

	}

	private function update_stats($stat_table, &$nstats) {
		//echo "<ul style='background:red;'>";
		//echo "<li>SAVING";
		//print_r($stat_table);
		//print_r($nstats);
		//echo "\n";
		$max = 0;
		foreach ($stat_table as $vname=>$xtime) {
			$savepath = $vname.($vname?'/':'');
			if (!isset($nstats[$vname])) {
				$this->set("INSERT INTO ".$this->table_stats." (mtime, path) VALUES (FROM_UNIXTIME(?), ?)", $xtime, $savepath);
			}
			else if ($xtime > $nstats[$vname])	{
				$this->set("UPDATE ".$this->table_stats." SET mtime = FROM_UNIXTIME(?) WHERE mtime < FROM_UNIXTIME(?) AND path = ?", $xtime, $xtime, $savepath);
			}
			$max = max($xtime, $max);
			$nstats[$vname] = $max;
		}
		//echo "</ul>";
		return $max;
	}
	
	public function full_sync($vpath='') {
		$stats = $this->get("SELECT *, UNIX_TIMESTAMP(mtime) as mtime FROM ".$this->table_stats." WHERE path LIKE ?", $vpath.($vpath?'/':'').'%');
		$nstats = array();
		foreach ($stats as $stat) $nstats[rtrim($stat['path'],'/')] = $stat['mtime'];

		$files = array();
		$dirs = array();
		$max = $this->rec_sync($vpath, $nstats, $files, $dirs);

		$newmax= $files; 
		$cnt = 0; $cnt2 = 0;
		foreach ($files as $vname=>$xtime) {
			$xpath = dirname($vname);
			$file = basename($vname);
			//echo "<li>Doing file $file | $xpath ";
			$xname = $this->dir.'/'.$vpath.'/'.$file;
			$xtime = filemtime($this->dir.'/'.$vname);
			if (!isset($newmax[$xpath])) $newmax[$xpath] = 0;
			$newmax[$xpath] = max($newmax[$xpath], $xtime);
			if (is_dir($xname))
				$dirs[$xpath] = (!isset($dirs[$xpath]) ? 1 : 2); 
			$id = $this->insert($this->dir.'/'.$vname, $xpath, '');
			$cnt++;$cnt2++;
			if ($cnt >= 100) { 
				$this->update_stats($dirs, $nstats);
				$cnt = 0;
			} 
			if ($cnt2 >= 1000) break;
		}		

		$this->update_stats($dirs, $nstats);

		foreach ($dirs as $dir=>$nvm) $this->clean_db($dir);
		
		return $files;
	}
	public function rec_sync($vpath, &$nstats, &$files, &$dirs) {
		
		//echo "<li>Entering `$vpath`</li>";
		//echo "<ul>";

		//echo "stats:"; print_r($nstats);
		
		$min = $max = (isset($nstats[$vpath]) ? $nstats[$vpath] : 0);
		
		$path = $this->dir.($vpath?'/':'').$vpath;
		$cfiles = scandir($path);
		foreach ($cfiles as $file) {
			if ($file == '.' || $file == '..') continue;
			$vname = $vpath.($vpath?'/':'').$file;
			//echo "<li>Comparing $vname";	
			$xname = $this->dir.'/'.$vpath.($vpath?'/':'').$file;
			$xtime = filemtime($xname);
			//echo "[ $xname | $xtime | $min ]";
			if (is_dir($xname)) {
				if ($xtime > $min) $dirs[$vname] = $xtime;
				$xtime = $this->rec_sync($vpath.($vpath?'/':'').$file, $nstats, $files, $dirs);
			} else {
				if ($xtime > $min) $files[$vname] = $xtime;
			}
			$max = max($max, $xtime);
		}
		//echo "</ul>";
		if ($min < $max) {
			if (!isset($dirs[$vpath])) $dirs[$vpath] = 0;
			$dirs[$vpath] = max($max, $dirs[$vpath]);
		}
		return $max;
	}
}

?>