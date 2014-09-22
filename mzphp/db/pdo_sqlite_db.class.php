<?php

//可能没有安装 PDO 用常量来表示
define('PDO_SQLITE_FETCH_ASSOC', 2);

class pdo_sqlite_db {
	
	var $querynum = 0;
	var $link;
	var $charset;
	var $init_db = 0;
	
	function __construct(&$db_conf) {
		if(!class_exists('PDO')){
			die('PDO extension was not installed!');
		}
		$this->connect($db_conf);
	}
	/*
		
	*/
	function connect(&$db_conf) {
		if($this->init_db){
			return;
		}
		$sqlitedb = "sqlite:{$db_conf['host']}";
		try {
			$link = new PDO($sqlitedb);//连接sqlite
			$link->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		} catch (Exception $e) {
	        exit('[pdo_sqlite]cant connect sqlite:'.$e->getMessage().$sqlitedb);
	    }
		$this->link = $link;
		return $link;
	}
	
	
	// 返回行数
	public function exec($sql, $link = NULL) {
		empty($link) && $link = $this->link;
		$n = $link->exec($sql);
		return $n;
	}

	function query($sql) {
		if(DEBUG) {
			$sqlstarttime = $sqlendttime = 0;
			$mtime = explode(' ', microtime());
			$sqlstarttime = number_format(($mtime[1] + $mtime[0] - $_SERVER['starttime']), 6) * 1000;
		}
		$link = &$this->link;
		
		$type = strtolower(substr(trim($sql), 0, 4));
		if($type == 'sele' || $type == 'show') {
			$result = $link->query($sql);
		} else {
			$result = $this->exec($sql, $link);
		}
		
		if(DEBUG) {
			$mtime = explode(' ', microtime());
			$sqlendttime = number_format(($mtime[1] + $mtime[0] - $_SERVER['starttime']), 6) * 1000;
			$sqltime = round(($sqlendttime - $sqlstarttime), 3);
			$explain = array();
			$info = array();
			if($result && $type == 'sele') {
				$explain = $this->fetch_array($link->query('EXPLAIN QUERY PLAN '.$sql));
			}
			$_SERVER['sqls'][] = array('sql'=>$sql, 'type'=>'sqlite', 'time'=>$sqltime, 'info'=>$info, 'explain'=>$explain);
		}
		
		if($result === FALSE) {
			$error = $this->error();
			throw new Exception('[pdo_sqlite]Query Error:'.$sql.' '.(isset($error[2]) ? "Errstr: $error[2]" : ''));
		}
		$this->querynum++;
		
		return $result;
	}
	
	function fetch_array(&$query, $result_type = PDO_SQLITE_FETCH_ASSOC/*PDO::FETCH_ASSOC*/) {
		return $query->fetch($result_type);
	}
	
	function fetch_all(&$query, $result_type = PDO_SQLITE_FETCH_ASSOC){
		return $query->fetchAll($result_type);
	}

	function result(&$query){
		return $query->fetchColumn(0);
	}

	function affected_rows() {
		return $this->link->rowCount();
	}
	

	function error() {
		return (($this->link) ? $this->link->errorInfo() : 0);
	}

	function errno() {
		return intval(($this->link) ? $this->link->errorCode() : 0);
	}


	function insert_id() {
		return ($id = $this->link->lastInsertId()) >= 0 ? $id : $this->result($this->query("SELECT last_insert_id()"), 0);
	}

	
	//simple select
	function select($table, $where, $order=array(), $perpage = -1, $page = 1, $fields = array()){
		$where_sql = $this->build_where_sql($where);
		$field_sql = '*';
		if(is_array($fields)){
			$field_sql = implode(',', $fields);
		}else if ($fields){
			$field_sql = $fields;
		}else{
			$field_sql = '*';
		}
		$start = ($page - 1) * $perpage;
		$fetch_first = $perpage == 0 ? true : false;
		$fetch_all = $perpage == -1 ? true : false;
		$limit_sql = '';
		if(!$fetch_first && !$fetch_all){
			$limit_sql = ' LIMIT '.$start.','.$perpage;
		}
		
		$order_sql = '';
		if($order){
			$order_sql = $this->build_order_sql($order);
		}
		
		$sql = 'SELECT '.$field_sql.' FROM '.$table.$where_sql.$order_sql.$limit_sql;
		$query = $this->query($sql);;
		if($fetch_first){
			return $this->fetch_array($query);
		}else{
			return $this->fetch_all($query);
		}
	}
	
	
	// insert and replace
	function insert($table, $data, $return_id){
		$data_sql = $this->build_insert_sql($data);
		if(!$data_sql){
			return 0;
		}
		$sql = 'INSERT INTO '.$table.' '.$data_sql;
		$this->query($sql);
		return $this->insert_id();
	}
	
	
	// update
	function update($table, $data, $where){
		$data_sql = $this->build_set_sql($data);
		$where_sql = $this->build_where_sql($where);
		if($where_sql){
			$sql = 'UPDATE '.$table.$data_sql.$where_sql;
			return $this->query($sql);
		}else{
			return 0;
		}
	}
	
	// delete
	function delete($table, $where){
		$where_sql = $this->build_where_sql($where);
		if($where_sql){
			$sql = 'DELETE FROM '.$table.$where_sql;
			return $this->query($sql);
		}else{
			return 0;
		}
	}

	//build order sql
	function build_order_sql($order){
		$order_sql = '';
		if(is_array($order)){
			$order_sql .= implode(', ', $order);
		}
		if($order_sql){
			$order_sql = ' ORDER BY '.$order_sql. ' ';
		}
		return $order_sql;
	}
	
	
	// build where sql
	function build_where_sql($where){
		$where_sql = '';
		if(is_array($where)){
			foreach($where as $key=>$value){
				if(is_array($value)){
            		$value = array_map('addslashes', $value);
					$where_sql .= ' AND '.$key.' IN (\''.implode("', '", $value).'\')';
				}elseif(strlen($value)>0){
					switch(substr($value, 0, 1)){
						case '>':
						case '<':
						case '=':
							$where_sql .= ' AND '.$key.$this->fix_where_sql($value).'';
						break;
						default:
							$where_sql .= ' AND '.$key.' = \''.addslashes($value).'\'';
						break;
					}
				}elseif($key){
					$where_sql .= ' AND '.$key;
				}
			}
		}else if($where){
			$where_sql = ' AND '.$where;
		}
		return $where_sql ? ' WHERE 1 '.$where_sql .' ': '';
	}
	
	function fix_where_sql($value){
		$value = preg_replace('/^((?:[><]=?)|=)?\s*(.+)\s*/is', '$1\'$2\'', $value);
		return $value;
	}
	
	// build set sql
	function build_set_sql($data){
		$setkeysql = $comma = '';
		foreach ($data as $set_key => $set_value) {
			if(preg_match('#^\s*?\w+\s*?[\+\-\*\/]\s*?\d+$#is', $set_value)){
				$setkeysql .= $comma.'`'.$set_key.'`='.$set_value.'';
			}else{
				$set_value = '\''.$set_value.'\'';
			}
			$setkeysql .= $comma.'`'.$set_key.'`='.$set_value.'';
			$comma = ',';
		}
		return ' SET '.$setkeysql.' ';
	}
}
?>