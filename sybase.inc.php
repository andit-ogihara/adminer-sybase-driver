<?php
/**
* @author Kazuhiro Ogihara
*/
add_driver("sybase", "SYBASE");

if (isset($_GET["sybase"])) {
    define("DRIVER", "sybase");
    $_sybase_queries = array();
    if (extension_loaded("sybase_ct")) {
        class Min_DB {
            public $extension = "SYBASE_CT", $_link, $_result, $server_info, $affected_rows, $error;

            function __construct() {
                $adminer = adminer();
                foreach ($adminer->plugins as $plugin) {
                    if (get_class($plugin) == 'AdminerSybaseDriver') {
                        return;
                    }
                }
                adminer()->plugins[] = new AdminerSybaseDriver(true);
            }

            function connect($server, $username, $password) {
                $this->_link = @sybase_connect($server, $username, $password, 'utf8');
                if ($this->_link) {
                    $result = $this->query("SELECT @@VERSION");
                    if ($result) {
                        $row = $result->fetch_row();
                        $this->server_info = $row[0];
                    }
                } else {
                    $this->error = sybase_get_last_message();
                }
                return (bool) $this->_link;
            }

            function quote($string) {
                return "'" . str_replace("'", "''", $string) . "'";
            }

            function select_db($database) {
                return @sybase_select_db($database);
            }

            function query($query, $unbuffered = false) {
                global $_sybase_queries;
                if ($this->_next_select) {
                    $this->_next_select = false;
                    return $this->_result;
                }

                $query = preg_replace("/;+\s*$/D", '', $query);
                if (preg_match("/^.+--isql-go-command.*$/m", $query)) {
                    $query = preg_replace("/^.+--isql-go-command.*$/m", '', $query);
                }
                $start_time = microtime(true);
                if ($unbuffered) {
                    $result = @sybase_unbuffered_query($query, $this->_link);
                } else {
                    $result = @sybase_query($query, $this->_link);
                }
                $_sybase_queries[] = array($query, microtime(true) - $start_time);
                $this->error = "";
                if (!$result) {
                    $this->error = sybase_get_last_message();
                    return false;
                }
                if ($result === true) {
                    $this->affected_rows = sybase_affected_rows($this->_link);
                    return true;
                }

                if (preg_match('~/\* offset:(\d+) \*/~', $query, $matches)) {
                    sybase_data_seek($result, $matches[1]);
                }

                return new Min_Result($result);
            }

            function multi_query(&$query) {
                if (preg_match_all("/^CALL\s+([^\s\(]+)\((.+)\)$/", $query, $matches, PREG_OFFSET_CAPTURE)) {
                    $query = "EXEC " . $matches[1][0][0] . " " . $matches[2][0][0];
                    $name = explode(".", $matches[1][0][0]);
                    $procedure = preg_replace("/[\[\]]/", "", array_pop($name));
                    $info = routine($procedure, "procedure");
                    $fields = array();
                    foreach ($info["fields"] as $field) {
                        $fields[$field["field"]] = $field;
                    }

                    $outputs = array();
                    if (preg_match_all("/(@@[^\s,\)]+)/",
                                       $query, $matches, PREG_OFFSET_CAPTURE)) {
                        $len = 0;
                        foreach ($matches[1] as $match) {
                            $outputs[] = substr($match[0], 1);
                            $query = substr($query, 0, $match[1] + $len) . substr($match[0], 1) . " out" . substr($query, $match[1] + strlen($match[0]) + $len);
                            $len += 3;
                        }
                    }

                    $declare = null;
                    foreach ($outputs as $out) {
                        if (array_key_exists($out, $fields)) {
                            $val = $out;
                            if ($field["unsigned"]) {
                                $val .= " unsigned";
                            }
                            $val .= " " . $field["type"];
                            if ($field["length"]) {
                                $val .= " (" . $field["length"] . ")";
                            }
                            if (is_null($declare)) {
                                $declare = "DECLARE " . $val;
                            } else {
                                $declare .= ", " . $val;
                            }
                        }
                    }
                    if ($declare) {
                        $declare .= "\nset proc_output_params off";
                        $declare .= "\nset proc_return_status off";
                        $query = $declare . "\n\n" .  $query;
                    }
                    $select = null;
                    foreach ($outputs as $out) {
                        if (array_key_exists($out, $fields)) {
                            if (is_null($select)) {
                                $select = "SELECT ";
                            } else {
                                $select .= ", ";
                            }
                            $select .= $out . " AS '" . substr($out, 1) . "'";
                        }
                    }
                    if ($select) {
                        $query = $query . "\n\n" .  $select;
                        $result = $this->query($query);
                        $this->_result = $this->query($query);
                        $this->_next_select = true;
                        return true;
                    }
                }
                return $this->_result = $this->query($query);
            }

            function store_result() {
                return $this->_result;
            }

            function next_result() {
                return false;
            }

            function result($query, $field = 0) {
                $result = $this->query($query);
                if (!is_object($result)) {
                    return false;
                }
                return sybase_result($result->_result, 0, $field);
            }
        }

        class Min_Result {
            public $_result, $_offset = 0, $_fields, $num_rows;
            public $_queries = array();

            function __construct($result) {
                $this->_result = $result;
                $this->num_rows = sybase_num_rows($result);
            }

            function fetch_assoc() {
                return sybase_fetch_assoc($this->_result);
            }

            function fetch_row() {
                return sybase_fetch_row($this->_result);
            }

            function num_rows() {
                return sybase_num_rows($this->_result);
            }

            function fetch_field() {
                $return = sybase_fetch_field($this->_result);
                $return->orgtable = $return->table;
                $return->orgname = $return->name;
                return $return;
            }

            function seek($offset) {
                sybase_data_seek($this->_result, $offset);
            }

            function __destruct() {
                sybase_free_result($this->_result);
            }
        }

    } elseif (extension_loaded("pdo_dblib")) {
        class Min_DB extends Min_PDO {
            public $extension = "PDO_DBLIB";
        
            function __construct() {
                $adminer = adminer();
                foreach ($adminer->plugins as $plugin) {
                    if (get_class($plugin) == 'AdminerSybaseDriver') {
                        parent::__construct();
                        return;
                    }
                }
                adminer()->plugins[] = new AdminerSybaseDriver();
                parent::__construct();
            }

            function connect($server, $username, $password) {
                $drivers = PDO::getAvailableDrivers();
                if (in_array("dblib", $drivers)) {
                    $this->dsn("dblib:charset=utf8;host=" . str_replace(":", ";unix_socket=", preg_replace('~:(\d)~', ';port=\1', $server)), $username, $password);
                } elseif (in_array("sybase", $drivers)) {
                    $this->dsn("sybase:charset=utf8;host=" . str_replace(":", ";unix_socket=", preg_replace('~:(\d)~', ';port=\1', $server)), $username, $password);
                } else {
                    $this->error = "Could not find PDO driver";
                    return false;
                }

                return true;
            }

            function dsn($dsn, $username, $password, $options = array()) {
                $return = parent::dsn($dsn, $username, $password, $options);

                $result = $this->query("SELECT @@VERSION");
                if ($result) {
                    $row = $result->fetch_row();
                    $this->server_info = $row[0];
                }
                return $return;
            }

            function select_db($database) {
                return $this->query("USE " . idf_escape($database));
            }

            function query($query, $unbuffered = false) {
                global $_sybase_queries;
                $query = preg_replace("/;+\s*$/D", '', $query);
                if (preg_match("/^.+--isql-go-command.*$/m", $query)) {
                    $query = preg_replace("/^.+--isql-go-command.*$/m", '', $query);
                }
                $start_time = microtime(true);
                $result = parent::query($query, $unbuffered);
                $_sybase_queries[] = array($query, microtime(true) - $start_time);
                if ($result && preg_match('~/\* offset:(\d+) \*/~', $query, $matches)) {
                    for ($i = 0; $i < $matches[1]; $i++) {
                        $result->fetch(PDO::FETCH_NUM);
                    }
                }

                return $result;
            }
        }
    }


    class Min_Driver extends Min_SQL {
        private function convert_field($field) {
            $column = idf_escape($field["field"]);
            switch ($field['type']) {
                case "tinyint":
                case "smallint":
                case "int":
                case "bigint":
                case "bit":
                case "numeric":
                case "decimal":
                case "real":
                case "float":
                case "smallmoney":
                case "money":
                    return;
                case 'time':
                    return "CONVERT(char(12), $columb, 20)";
                case 'date':
                    return "CONVERT(char(10), $column, 111)";
                case 'smalldatetime':
                    return "NULLIF(CONVERT(char(10), $column, 111) + ' ' + CONVERT(char(5), $column, 18), ' ')";
                case 'datetime':
                    return "NULLIF(CONVERT(char(10), $column, 111) + ' ' + CONVERT(char(12), $column, 20), ' ')";

                case "char":
                case "varchar":
                case "nchar":
                case "nvarchar":
                    return;
                case "text":
                case "ntext":
                    return "CONVERT(varchar(16384), $column)";
            }
        }

        function select($table, $select, $where, $group, $order = array(), $limit = 1, $page = 0, $print = false) {
            $fields = fields($table);
            if ($fields) {
                $select2 = array();
                foreach ($select as $col) {
                    if ($col == "*") {
                        foreach ($fields as $name => $field) {
                            if (!in_array($name, $select2)) {
                                $select2[] = idf_escape($name);
                            }
                        }
                    } else {
                        if (!in_array($col, $select2)) {
                            $select2[] = $col;
                        }
                    }
                }
                $convert_flg = false;
                foreach ($select2 as &$col) {
                    $name = idf_unescape2($col);
                    if (array_key_exists($name, $fields)) {
                        $field = $fields[$name];
                        $convert = $this->convert_field($field);
                        if ($convert) {
                            $convert_flg = true;
                            $col = $convert . " AS " . $col;
                        }
                    }
                }
                if (!$convert_flg) {
                    $select2 = $select;
                }
            }
            return parent::select($table, $select2, $where, $group, $order, $limit, $page, $print);
        }

        function insert($table, $set) {
            if ($set) {
                return queries("INSERT INTO " . table($table) . " (" . implode(", ", array_keys($set)) . ")\nVALUES (" . implode(", ", $set) . ")");
            } else {
                $this->_conn->error = "Can't insert";
            }
        }

        function insertUpdate($table, $rows, $primary) {
            $config = driver_config();
            $numbers = $config['structured_types'][lang('Numbers')];
            $fields = fields($table);
            foreach ($rows as $cols) {
                $wheres = array();
                foreach ($primary as $name => $dummy) {
                    if (in_array($fields[$name]['type'], $numbers)) {
                        $wheres[] = "$name = " . trim($cols[$name], "'");
                    } else {
                        $wheres[] = "$name = " . $cols[$name];
                    }
                }
                $where = "WHERE " . implode(" AND ", $wheres);

                $sql = "IF EXISTS (SELECT * FROM " . table($table) . " $where)\n";
                $sql .= "UPDATE ". table($table) . " SET ";
                $sets = array();
                foreach ($cols as $name => $val) {
                    if (array_key_exists($name, $primary)) continue;
                    if (in_array($fields[$name]['type'], $numbers)) {
                        $sets[] = "$name = " . trim($val, "'");
                    } else {
                        $sets[] = "$name = $val";
                    }
                }
                $sql .= implode(", ", $sets);
                $sql .= " $where";
                $sql .= "\nELSE\n";
                $sql .= "INSERT INTO ". table($table);
                $sql .= "(". implode(", ", array_keys($cols)) . ")";
                $values = array();
                foreach ($cols as $name => $val) {
                    if (in_array($fields[$name]['type'], $numbers)) {
                        $values[] = trim($val, "'");
                    } else {
                        $values[] = $val;
                    }
                }
                $sql .= "VALUES (". implode(", ", $values) . ")";
                if (!queries($sql)) return false;
            }
            return true;
        }

        function begin() {
            return queries("BEGIN TRANSACTION");
        }
    }


    function idf_escape($idf) {
        if (substr($idf, 0, 1) == "@") return $idf;
        if (strpos($idf, ".") !== false) {
            $vals = explode(".", $idf);
            foreach ($vals as &$val) {
                $val = idf_escape($val);
            }
            return implode(".", $vals);
        }
        return "[" . str_replace("]", "]]", $idf) . "]";
    }

    function idf_unescape2($idf) {
        if (preg_match("~^\[(.+)\]$~", $idf, $matches)) {
            $idf = $matches[1];
        }
        return $idf;
    }

    function table($idf) {
        return ($_GET["ns"] != "" ? idf_escape($_GET["ns"]) . "." : "") . idf_escape($idf);
    }

    function connect() {
        $connection = new Min_DB;
        $credentials = adminer()->credentials();
        if ($connection->connect($credentials[0], $credentials[1], $credentials[2])) {
            return $connection;
        }
        return $connection->error;
    }

    function get_databases() {
        return get_vals("sp_databases", 0);
    }

    function limit($query, $where, $limit, $offset = 0, $separator = " ") {
        if (preg_match("/INTO\s.+\sSELECT /i", $query)) {
            $top = $limit !== null ? "TOP " . ($limit + $offset) : "";
            return preg_replace("/(INTO\s.+\sSELECT )/i", "$1$top ", " $query");
        } else {
            $return = "";
            if ($limit !== null) {
                $return .= " TOP " . ($limit + $offset);
                if ($offset) {
                    $return .= " /* offset:$offset */";
                }
            }
            $return .= " $query$where";
            return $return;
        }
    }

    function limit1($table, $query, $where, $separator = "\n") {
        return limit($query, $where, 1, 0, $separator);
    }

    function db_collation($db, $collations) {
        $connection = connection();
        return $connection->result("SELECT USER");
    }

    function engines() {
        return array();
    }

    function logged_user() {
        $connection = connection();
        return $connection->result("SELECT SUSER_NAME()");
    }

    function tables_list() {
        static $cache = null;
        if (is_null($sybase)) {
            $cache = get_key_vals("
SELECT name,
  CASE type
  WHEN 'U' THEN 'USER_TABLE'
  WHEN 'V' THEN 'VIEW'
  WHEN 'S' THEN 'SYSTEM_TABLE'
  ELSE NULL
  END AS type
FROM sysobjects
WHERE type IN ('U', 'V') ORDER BY name");
        }
        return $cache;
    }

    function count_tables($databases) {
        $connection = connection();
        $return = array();
        foreach ($databases as $db) {
            $connection->select_db($db);
            $return[$db] = $connection->result("SELECT COUNT(*) FROM sysobjects WHERE type IN ('U', 'V')");
        }
        return $return;
    }

    function table_status($name = "") {
        static $cache = array();
        if (!array_key_exists($name, $cache)) {
            foreach (get_rows("
SELECT
  so.name AS Name,
  CASE so.type
    WHEN 'U' THEN 'USER_TABLE'
    WHEN 'V' THEN 'VIEW'
    WHEN 'S' THEN 'SYSTEM_TABLE'
    ELSE NULL
  END AS Engine,
  sts.rowcnt AS 'Rows',
  sts.rowcnt * sts.datarowsize AS Data_length
FROM sysobjects AS so
LEFT OUTER JOIN systabstats AS sts ON sts.id = so.id
WHERE so.type IN ('U', 'V') " . ($name != "" ? "AND so.name = " . q($name) : "ORDER BY so.name")) as $row) {
                $cache[$row["Name"]] = $row;
                ksort($cache);
            }
        }
        if ($name != "") {
            return $cache[$name];
        } else {
            return $cache;
        }
    }

    function is_view($table_status) {
        return $table_status["Engine"] == "VIEW";
    }

    function fk_support($table_status) {
        return true;
    }

    function pkeys($table) {
        static $cache = array();
        if (!array_key_exists($table, $cache)) {
            $cache[$table] = array();
            $pkeys = get_vals("EXECUTE sp_pkeys " . table($table), 3);
            if ($pkeys) {
                $cache[$table] = $pkeys;
            } else {
                foreach (indexes($table) as $name => $index) {
                    if (preg_match('/UNIQUE|PRIMARY/i', $index['type'])) {
                        $cache[$table] = $index["columns"];
                        break;
                    }
                }
            }
        }
        return $cache[$table];
    }

    function fields($table) {
        static $cache = array();
        if (!array_key_exists($table, $cache)) {
            $return = array();
            $pkeys = pkeys($table);
            $connection = connection();
            $ncharsize = $connection->result("SELECT @@ncharsize");
            foreach (get_rows("
SELECT
  c.name,
  t.name AS type,
  c.length,
  c.prec,
  c.scale,
  cm.text AS cdefault,
  CASE (c.status & 8) WHEN 8 THEN 1 ELSE 0 END AS 'null',
  CASE (c.status & 128) WHEN 128 THEN 1 ELSE 0 END AS 'identity'
FROM syscolumns AS c
JOIN systypes AS t ON c.usertype = t.usertype
LEFT OUTER JOIN syscomments AS cm on cm.id = c.cdefault
WHERE c.id = object_id('$table')
ORDER by c.colid") as $row) {
                if (preg_match('/^u(.*int)$/', $row["type"], $matches)) {
                    $type = $matches[1];
                    $unsigned = "unsigned";
                } else {
                    $type = $row["type"];
                    $unsigned = "";
                }
                if (preg_match("~char|binary~", $type)) {
                    if ($row["type"] == "nchar" || $row["type"] == "nvarchar") {
                        $length = $row["length"] / $ncharsize;
                    } else {
                        $length = $row["length"];
                    }
                } elseif (preg_match("/^numeric|^decimal/", $type)) {
                    $length = "{$row['prec']},{$row['scale']}";
                } else {
                    $length = "";
                }
                $return[$row["name"]] = array("field" => $row["name"],
                                              "full_type" => $type . ($unsigned ? " UNSIGNED " : "") . ($length ? "($length)" : ""),
                                              "type" => $type,
                                              "unsigned" => $unsigned,
                                              "length" => $length,
                                              "default" => preg_match("/^DEFAULT /", $row["cdefault"]) ? trim(str_replace("DEFAULT ", "", $row["cdefault"])) : null,
                                              "null" => $row["null"],
                                              "auto_increment" => $row["identity"],
                                              "privileges" => $row["identity"] ? array("select" => 1) : array("insert" => 1, "select" => 1, "update" => 1),
                                              "primary" => in_array($row["name"], $pkeys),
                                              "comment" => null,
                                              );
            }
            $cache[$table] = $return;
        }
        return $cache[$table];
    }

    function indexes($table, $connection2 = null) {
        static $cache = array();
        if (!array_key_exists($table, $cache) || $connection2) {
            $return = array();
            $primary = false;
            foreach (get_rows("
SELECT
  i.name,
  i.status,
  i.status2,
  i.indid,
  i.keycnt,
  user_name(o.uid) AS user_name,
  object_name(i.id) AS object_name
FROM sysindexes AS i
join sysobjects AS o ON o.id = i.id
WHERE i.id = object_id(" . q($table) . ")
AND i.indid BETWEEN 1 AND 250
ORDER BY i.indid") as $row) {
                $name = $row['name'];
                $return[$name] = array();
                if (($row["status"] & 2048)) { // primary key index
                    $type = "PRIMARY";
                    $primary = true;
                } else if (($row["status2"] & 2)) { // primary constraint or unique constraint
                    $type = "UNIQUE";
                } elseif ($row["status"] & 2) { // unique
                    $type = "UNIQUE";
                } else {
                    $type = "INDEX";
                }
                $return[$name]["type"] = $type;
                $return[$name]["lengths"] = array();
                $return[$name]["columns"] = array();
                $return[$name]["descs"] = array();
                $keycnt = ($row["keycnt"] == 1) ? 1 : $row["keycnt"] - 1;
                for ($i = 1; $i <= $keycnt; $i++) {
                    $row2 = get_rows("SELECT index_col(" . q($row["user_name"] . "." . $row["object_name"]) . ", " . $row["indid"] . ", $i) AS col, index_colorder(" . q($row["user_name"] . "." . $row["object_name"]) . ", " . $row["indid"] . ", $i) AS colorder");
                    $return[$name]["lengths"][] = null;
                    $return[$name]["columns"][] = $row2[0]["col"];
                    //$return[$name]["descs"][] = ($row2[0]["colorder"] == "DESC");
                    $return[$name]["descs"][] = null; // bug
                    $return[$name]["colorder"][] = $row2[0]["colorder"]; // Sybase only
                }
                // Sybase only
                $return[$name]["status"] = $row["status"];
                $return[$name]["status2"] = $row["status2"];
            }
            if ($connection2) {
                return $return;
            }
            $cache[$table] = $return;
        }
        return $cache[$table];
    }

    function view($name) {
        $connection = connection();
        $sql = "";
        foreach (get_vals("
SELECT text
FROM syscomments
WHERE id IN (
  SELECT id
  FROM sysobjects
  WHERE name = ". q($name) .")
ORDER BY colid2, colid") as $line) {
            if (!preg_match("~^\s*/\*.*\*/[\s/]*$~", $line)) {
                $sql .= $line . "\n";
            }
        }
        return array("select" => $sql);
    }

    function collations() {
        //! supported in CREATE DATABASE
        return array();
    }

    function information_schema($db) {
        return false;
    }

    function error() {
        $connection = connection();
        return nl_br(h(preg_replace('~^(\[[^]]*])+~m', '', $connection->error)));
    }

    function create_database($db, $collation) {
        return queries("CREATE DATABASE " . idf_escape($db));
    }

    function drop_databases($databases) {
        return queries("DROP DATABASE " . implode(", ", array_map('idf_escape', $databases)));
    }

    function rename_database($name, $collation) {
        queries("EXECUTE sp_renamedb " . q(DB) . ", " . q($name));
        return true;
    }

    function auto_increment() {
        return " IDENTITY PRIMARY KEY";
    }

    function alter_table($table, $name, $fields, $foreign, $comment, $engine, $collation, $auto_increment, $partitioning) {
        $fields_org = array();
        foreach (fields($table) as $n => $field) {
            $val = array();
            $val[0] = $n;
            if ($field["unsigned"]) {
                $val[1] = str_replace(" ", "", "unsigned " . $field["full_type"]);
            } else {
                $val[1] = str_replace(" ", "", $field["full_type"]);
            }
            $val[2] = trim($field["default"]);
            $val[3] = $field["null"] ? "NULL" : "NOT NULL";
            $val[5] = $val[4] = null;
            $val[6] = $field["auto_increment"] ? trim(auto_increment()) : null;
            $fields_org[$n] = $val;
        }

        $alter = array();
        foreach ($fields as $field) {
            $column = idf_escape($field[0]);
            $val = $field[1];
            if (preg_match('/^\s*(.+)\s+unsigned\s*$/', $val[1], $matches)) {
                $val[1] = " unsigned " . $matches[1];
            }
            if ($val[6] == auto_increment()) {
                $val[2] = "";
            }
            $v = $val[2];
            $val[2] = $val[3];
            $val[3] = $v;
            if ($val[2]) { // DEFAULT
                $v = explode(" ", trim($val[2]), 2);
                $v[1] = unconvert_field(array('type' => trim($val[1])), $v[1]);
                $val[2] = " " . implode(" ", $v);
            }
            if (!$val) {
                $alter["DROP"][] = $column;
            } else {
                if ($field[0] == "") {
                    $alter["ADD"][] = implode("", $val);
                } else {
                    $val[2] = "";
                    if ($column != $val[0]) {
                        queries("EXECUTE sp_rename " . q(table($table) . ".$column") . ", " . q(idf_unescape($val[0])) . ", 'column'");
                        $val_org = $fields_org[$column];
                        if (str_replace(" ", "", $val[1]) != $val_org[1] ||
                            trim($val[2]) != $val_org[2] ||
                            trim($val[3]) != $val_org[3] ||
                            trim($val[6]) != $val_org[6]) {
                            $alter["MODIFY"][] = implode("", $val);
                        }
                    } else {
                        $alter["MODIFY"][] = implode("", $val);
                    }
                }
            }
        }
        if ($table == "") {
            return queries("CREATE TABLE " . table($name) . " (" . implode(",", (array) $alter["ADD"]) . "\n)");
        }
        if ($table != $name) {
            queries("EXECUTE sp_rename " . q(table($table)) . ", " . q($name));
        }
        foreach ($alter as $key => $val) {
            if (!queries("ALTER TABLE " . idf_escape($name) . " $key " . implode(",", $val))) {
                return false;
            }
        }
        return true;
    }

    function alter_indexes($table, $alter) {
        $indexes = indexes($table);
        $adds = array();
        $drops = array();
        $primary = 1;
        foreach ($alter as $val) {
            $type = $val[0];
            $name = trim($val[1]);
            if ($val[2] == "DROP") {
                if (!array_key_exists($name, $indexes)) {
                    connection()->error = "Not found index [$name]";
                    return false;
                }
                $index = $indexes[$name];
                if ($index["status2"] & 2) { // primary constraint or unique constraint
                    $drops[] = "ALTER TABLE " . 
                        table($table) . " DROP CONSTRAINT " . idf_escape($name);
                    if ($type == "PRIMARY") {
                        $primary--;
                    }
                } else {
                    $drops[] = "DROP INDEX " . table($table) . "." . idf_escape($name);
                }
            } else {
                $columns = $val[2];
                if ($type == "PRIMARY") {
                    $adds[] = "ALTER TABLE " . table($table) . " ADD CONSTRAINT " . idf_escape($name) . " PRIMARY KEY CLUSTERED (" . implode(", ", $columns) . ")";
                    $primary++;
                } elseif ($type == "UNIQUE") {
                    $adds[] = "ALTER TABLE " . table($table) . " ADD CONSTRAINT " . idf_escape($name) . " UNIQUE NONCLUSTERED (" . implode(", ", $columns) . ")";
                } elseif ($type == "INDEX") {
                    $adds[] = "CREATE NONCLUSTERED INDEX " . idf_escape($name) . " ON " . table($table) . "(" . implode($columns) . ")";
                }
            }
        }
        foreach ($drops as $sql) {
            if (!queries($sql)) return false;
        }
        foreach ($adds as $sql) {
            if (!queries($sql)) return false;
        }
        return true;
    }

    function last_id() {
        $connection = connection();
        return $connection->result("SELECT @@identity");
    }

    function explain($connection, $query) {
        //$connection = connection();
        //$connection->query("SET SHOWPLAN, NOEXEC ON");
        //$return = $connection->query($query);
        //$connection->query("SET SHOWPLAN, NOEXEC OFF");
        //return $return;
    }

    function found_rows($table_status, $where) {
        return null;
    }

    function foreign_keys($table) {
        static $cache = array();
        if (!array_key_exists($table, $cache)) {
            $return = array();
            $sql = "
SELECT 
  fko.name AS fk_name,
  pko.name AS pktable_name";
            for ($i = 1; $i <= 16; $i++) {
                $sql .= ",
  CASE WHEN ref.fokey{$i} IS NOT NULL THEN col_name(ref.tableid, ref.fokey{$i}) ELSE NULL END AS fk{$i}_name,
  CASE WHEN ref.refkey{$i} IS NOT NULL THEN col_name(ref.reftabid, ref.refkey{$i}) ELSE NULL END AS pk{$i}_name";
            }
            $sql .= "
FROM sysconstraints AS con
JOIN sysobjects AS fko ON con.constrid = fko.id
JOIN sysreferences AS ref ON con.constrid  = ref.constrid
JOIN sysobjects AS pko ON pko.id = ref.reftabid
WHERE con.tableid = object_id(" . q($table) . ")";
            foreach (get_rows($sql) as $row) {
                $foreign_key["table"] = $row["pktable_name"];
                for ($i = 1; $i <= 16; $i++) {
                    if (is_null($row["fk{$i}_name"]) || $row["fk{$i}_name"] === "") break;
                    $foreign_key["source"][] = $row["fk{$i}_name"];
                    $foreign_key["target"][] = $row["pk{$i}_name"];
                }
                $name = $row["fk_name"];
                $return[$name] = $foreign_key;
            }
            $cache[$table] = $return;
        }
        return $cache[$table];
    }

    function truncate_tables($tables) {
        return apply_queries("TRUNCATE TABLE", $tables);
    }

    function drop_views($views) {
        return queries("DROP VIEW " . implode(", ", array_map('table', $views)));
    }

    function drop_tables($tables) {
        return queries("DROP TABLE " . implode(", ", array_map('table', $tables)));
    }

    function move_tables($tables, $views, $target) {
        connection()->error = "Not supported";
        return false;
    }

    function trigger($name) {
        static $cache = array();

        if ($name == "") {
            return array();
        }

        if (!array_key_exists($name, $cache)) {
            $return = array("Trigger" => $name,
                            "Timing" => "FOR",
                            "Event" => "",
                            //"Of" => ,
                            "Type" => "AS",
                            "Statement" => "");
            foreach (get_rows("
SELECT
  instrig.name AS instrig_name,
  updtrig.name AS updtrig_name,
  deltrig.name AS deltrig_name
FROM sysobjects AS tab
LEFT OUTER JOIN sysobjects AS instrig ON instrig.id = tab.instrig
LEFT OUTER JOIN sysobjects AS updtrig ON updtrig.id = tab.updtrig
LEFT OUTER JOIN sysobjects AS deltrig ON deltrig.id = tab.deltrig
WHERE instrig.name = " . q($name) . "
   OR updtrig.name = " . q($name) . "
   OR deltrig.name = " . q($name)) as $row) {
                if ($row["instrig_name"] == $name) {
                    $return["Event"] = 'INSERT';
                }
                if ($row["updtrig_name"] == $name) {
                    if ($return["Event"]) {
                        $return["Event"] .= ',UPDATE';
                    } else {
                        $return["Event"] = 'UPDATE';
                    }
                }
                if ($row["deltrig_name"] == $name) {
                    if ($return["Event"]) {
                        $return["Event"] .= ',DELETE';
                    } else {
                        $return["Event"] = 'DELETE';
                    }
                }
            }

            foreach (get_rows("
SELECT text
FROM syscomments
WHERE id IN (
  SELECT id
  FROM sysobjects
  WHERE name = " . q($name) . "
)
ORDER BY colid2, colid") as $row) {
                $return["Statement"] .= $row["text"];
            }
            $return["Statement"] = preg_replace("/create\s*trigger.*?\sas\s/is", "", $return["Statement"]);

            $cache[$name] = $return;
        }
        return $cache[$name];
    }

    function copy_tables($tables, $views, $target) {
        if (DB != $target) {
            connection()->error = "Cannot copy tables between difference databases";
            return false;
        }
        foreach ($tables as $table) {
            $vals = get_vals("SELECT object_id(" . q($new_table) . ")");
            $new_table = "copy_$table";
            if ($_POST["overwrite"] && $vals[0]) {
                if (!queries("DROP TABLE " . table($new_table))) {
                    return false;
                }
            }
            if (!queries("SELECT * INTO " . table($new_table) . " FROM " . table($table))) {
                return false;
            }
        }
        return true;
    }

    function triggers($table) {
        static $cache = array();
        if (!array_key_exists($table, $cache)) {
            $return = array();
            foreach (get_rows("
SELECT
  instrig.name AS instrig_name,
  updtrig.name AS updtrig_name,
  deltrig.name AS deltrig_name
FROM sysobjects AS tab
LEFT OUTER JOIN sysobjects AS instrig ON instrig.id = tab.instrig
LEFT OUTER JOIN sysobjects AS updtrig ON updtrig.id = tab.updtrig
LEFT OUTER JOIN sysobjects AS deltrig ON deltrig.id = tab.deltrig
WHERE tab.name = " . q($table)) as $row) {
                if ($row["instrig_name"]) {
                    $return[$row["instrig_name"]] = array('INSERT', 'ON');
                }
                if ($row["updtrig_name"]) {
                    if (array_key_exists($row["updtrig_name"], $return)) {
                        $return[$row["updtrig_name"]][0] .= ',UPDATE';
                    } else {
                        $return[$row["updtrig_name"]] = array('UPDATE', 'ON');
                    }
                }
                if ($row["deltrig_name"]) {
                    if (array_key_exists($row["deltrig_name"], $return)) {
                        $return[$row["deltrig_name"]][0] .= ',DELETE';
                    } else {
                        $return[$row["deltrig_name"]] = array('UPDATE', 'ON');
                    }
                }
            }
            $cache[$table] = $return;
        }
        return $cache[$table];
    }

    function trigger_options() {
        return array(
            "Timing" => array("FOR", "INSTEAD OF"),
            "Event" => array("INSERT",
                             "UPDATE",
                             "DELETE",
                             "INSERT,UPDATE",
                             "INSERT,DELETE",
                             "UPDATE,DELETE",
                             "INSERT,UPDATE,DELETE"),
            "Type" => array("AS"),
        );
    }

    function schemas() {
        return array('');
    }

    function get_schema() {
        return '';
    }

    function set_schema($schema) {
        return false;
    }

    function create_sql($table, $auto_increment, $style) {
        $connection = connection();
        
        $ddl = "CREATE TABLE $table (\n";
        $fields = fields($table);
        $i = 0;
        foreach ($fields as $name => $field) {
            $i++;
            $ddl .= $field["field"];
            if ($field["unsigned"]) {
                $ddl .= " UNSIGNED";
            }
            $ddl .= " {$field['full_type']}";
            if ($field["default"]) {
                $ddl .= " DEFAULT {$field['default']}";
            }
            if ($field["auto_increment"]) {
                $ddl .= " IDENTITY";
            } else if ($field["null"]) {
                $ddl .= " NULL";
            } else {
                $ddl .= " NOT NULL";
            }
            $ddl .= $sql . ($i == count($fields) ? "\n" : ",\n");
        }
        $ddl .= ")\n";
        $ddl .= "GO --isql-go-command\n\n";

        // Indexes
        $indexes = indexes($table);
        foreach ($indexes as $name => $index) {
            if ($index['status2'] & 2) { // constraint
                $ddl .= "ALTER TABLE " . table($table) . " ADD";
                if (($index['status2'] & 8) == 0) {
                    $ddl .= " CONSTRAINT $name";
                }
                if ($index['status'] & 2048) { // primary key
                    $ddl .= " PRIMARY KEY";
                } else if ($index['status'] & 2) { // unique
                    $ddl .= " UNIQUE";
                }
                if ($index['status'] & 16) { // clusted
                    $ddl .= " CLUSTERED";
                }
            } else {
                $ddl .= "CREATE";
                if ($index['status'] & 2) { // unique
                    $ddl .= " UNIQUE";
                }
                if ($index['status'] & 16) { // clusted
                    $ddl .= " CLUSTERED";
                }
                $ddl .= " INDEX";
                if (($index['status2'] & 8) == 0) {
                    $ddl .= " $name";
                }
                $ddl .= " ON $table";
            }
            $i = 0;
            $cols = array();
            foreach ($index["columns"] as $column) {
                if ($index["colorder"][$i]) {
                    $cols[] = "$column " . $index["colorder"][$i];
                } else {
                    $cols[] = $column;
                }
            }
            $ddl .= " (" . implode(", ", $cols) . ")\n";
            $ddl .= "GO --isql-go-command\n\n";
        }

        // Foreign keys
        $fkeys = foreign_keys($table);
        foreach ($fkeys as $name => $fkey) {
            $ddl .= "ALTER TABLE " . table($table) . " ADD FOREIGN KEY";
            $ddl .= " (" . implode(", ", $fkey["source"]) .")";
            $ddl .= " REFERENCES " . $fkey["table"];
            $ddl .= " (" . implode(", ", $fkey["target"]) .")\n";
            $ddl .= "GO --isql-go-command\n\n";
        }

        return $ddl;
    }

    function truncate_sql($table) {
        return "TRUNCATE " . table($table);
    }

    function use_sql($database) {
        return "USE " . idf_escape($database);
    }

    function trigger_sql($table) {
        $sql = "";
        foreach (triggers($table) as $trigger => $define) {
            foreach (get_vals("
SELECT text
FROM syscomments
WHERE id IN (
  SELECT id
  FROM sysobjects
  WHERE name = ". q($trigger) .")
ORDER BY colid2, colid") as $line) {
                if (!preg_match("~^\s*/\*.*\*/[\s/]*$~", $line)) {
                    $sql .= $line . "\n";
                }
            }
            $sql .= "GO --isql-go-command\n\n";
        }
        return $sql;
    }

    function show_variables() {
        $return = get_key_vals("EXECUTE sp_helpconfig NULL");
        ksort($return);
        return $return;
    }

    function show_status() {
        $variables = array('@@active_instances',
                           '@@authmech',
                           '@@bootcount',
                           '@@boottime',
                           '@@bulkarraysize',
                           '@@bulkbatchsize',
                           '@@char_convert',
                           '@@cis_rpc_handling',
                           '@@cis_version',
                           '@@client_csexpansion',
                           '@@client_csid',
                           '@@client_csname',
                           '@@clusterboottime',
                           '@@clustercoordid',
                           '@@clustermode',
                           '@@clustername',
                           '@@cmpstate',
                           '@@connections',
                           '@@cpu_busy',
                           '@@cursor_rows',
                           '@@curloid',
                           '@@datefirst',
                           '@@dbts',
                           '@@error',
                           '@@errorlog',
                           '@@failedoverconn',
                           '@@fetch_status',
                           '@@guestuserid',
                           '@@hacmpservername',
                           '@@haconnection',
                           '@@heapmemsize',
                           '@@identity',
                           '@@idle',
                           '@@instanceid',
                           '@@instancename',
                           '@@invaliduserid',
                           '@@io_busy',
                           '@@isolation',
                           '@@jsinstanceid',
                           '@@kernel_addr',
                           '@@kernel_size',
                           '@@kernelmode',
                           '@@langid',
                           '@@language',
                           '@@lastkpgendate',
                           '@@lastlogindate',
                           '@@lock_timeout',
                           '@@lwpid',
                           '@@max_connections',
                           '@@max_precision',
                           '@@maxcharlen',
                           '@@maxgroupid',
                           '@@maxpagesize',
                           '@@maxspid',
                           '@@maxsuid',
                           '@@maxuserid',
                           '@@mempool_addr',
                           '@@min_poolsize',
                           '@@mingroupid',
                           '@@minspid',
                           '@@minsuid',
                           '@@minuserid',
                           '@@monitors_active',
                           '@@ncharsize',
                           '@@nestlevel',
                           '@@nextkpgendate',
                           '@@nodeid',
                           '@@optgoal',
                           '@@options',
                           '@@optlevel',
                           '@@opttimeoutlimit',
                           '@@ospid',
                           '@@pack_received',
                           '@@pack_sent',
                           '@@packet_errors',
                           '@@pagesize',
                           '@@parallel_degree',
                           '@@plwpid',
                           '@@probesuid',
                           '@@procid',
                           '@@quorum_physname',
                           '@@recovery_state',
                           '@@remotestate',
                           '@@repartition_degree',
                           '@@resource_granularity',
                           '@@rowcount',
                           '@@scan_parallel_degree',
                           '@@servername',
                           '@@setrowcount',
                           '@@shmem_flags',
                           '@@spid',
                           '@@sqlstatus',
                           '@@ssl_ciphersuite',
                           '@@stringsize',
                           '@@sys_tempdbid',
                           '@@system_busy',
                           '@@system_view',
                           '@@tempdbid',
                           '@@textcolid',
                           '@@textdataptnid',
                           '@@textdbid',
                           '@@textobjid',
                           '@@textptnid',
                           '@@textptr',
                           '@@textptr_parameters',
                           '@@textsize',
                           '@@textts',
                           '@@thresh_hysteresis',
                           '@@timeticks',
                           '@@total_errors',
                           '@@total_read',
                           '@@total_write',
                           '@@tranchained',
                           '@@trancount',
                           '@@transactional_rpc',
                           '@@transtate',
                           '@@unicharsize',
                           '@@user_busy',
                           '@@version',
                           '@@version_as_integer',
                           '@@version_number',
                           );

        $return = array();
        foreach ($variables as $val) {
            $row = get_vals("SELECT $val");
            $return[$val] = $row ? $row[0] : "";
        }
        return $return;
    }

    function process_list() {
        $rows = get_rows("sp_who");
        foreach ($rows as &$row) {
            foreach ($row as &$val) {
                $val = trim($val);
            }
            $row["pid"] = $row["spid"];
        }
        return $rows;
    }

    function convert_field($field) {
    }
    /*
    function convert_field($field) {
        $column = idf_escape($field["field"]);
        switch ($field['type']) {
            case "tinyint":
            case "smallint":
            case "int":
            case "bigint":
            case "bit":
            case "numeric":
            case "decimal":
            case "real":
            case "float":
            case "smallmoney":
            case "money":
                return;
            case 'time':
                return "CASE WHEN $column IS NULL THEN NULL ELSE CONVERT(char(12), $columb, 20) END";
            case 'date':
                return "CASE WHEN $column IS NULL THEN NULL ELSE CONVERT(char(10), $column, 111) END";
            case 'smalldatetime':
                return "CASE WHEN $column IS NULL THEN NULL ELSE CONVERT(char(10), $column, 111) + ' ' + CONVERT(char(5), $column, 18) END";
            case 'datetime':
                return "CASE WHEN $column IS NULL THEN NULL ELSE CONVERT(char(10), $column, 111) + ' ' + CONVERT(char(12), $column, 20) END";

            case "char":
            case "varchar":
            case "nchar":
            case "nvarchar":
                return;
            case "text":
            case "ntext":
                return "CONVERT(varchar(16384), $column)";
        }
    }
    */

    function unconvert_field($field, $return) {
        switch ($field['type']) {
            case "tinyint":
            case "smallint":
            case "int":
            case "bigint":
            case "bit":
            case "numeric":
            case "decimal":
            case "real":
            case "float":
            case "smallmoney":
            case "money":
                return str_replace("'", "", $return);

            case "date":
            case "smalldatetime":
            case "datetime":
            case "time":
                return $return;

            case "char":
            case "varchar":
            case "text":
            case "nchar":
            case "nvarchar":
            case "ntext":
                return $return;
        }
    }

    function connection_id(){
        return "SELECT @@spid";
    }

    function kill_process($val) {
        return queries("KILL " . number($val));
    }

    function max_connections() {
        $connection = connection();
        return $connection->result("SELECT @@max_connections");
    }

    function routine($name, $type) {
        $return = array('fields' => array());
        $connection = connection();
        $ncharsize = $connection->result("SELECT @@ncharsize");
        $type = $type == "FUNCTION" ? "SF" : "P";
        foreach (get_rows("
SELECT
  sc.name,
  st.name AS type,
  st.prec,
  st.scale,
  sc.status2
FROM sysobjects AS so
JOIN syscolumns AS sc ON sc.id = so.id
JOIN systypes AS st ON st.type = sc.type AND st.usertype = sc.usertype
WHERE so.type = '$type'
  AND so.name = '$name'
ORDER BY sc.colid") as $row) {
            if (preg_match('/^u(.*int)$/', $row["type"], $matches)) {
                $type = $matches[1];
                $unsigned = "unsigned";
            } else {
                $type = $row["type"];
                $unsigned = "";
            }
            if (preg_match("~char|binary~", $type)) {
                if ($row["type"] == "nchar" || $row["type"] == "nvarchar") {
                    $length = $row["length"] / $ncharsize;
                } else {
                    $length = $row["length"];
                }
            } elseif (preg_match("/^numeric|^decimal/", $type)) {
                $length = "{$row['prec']},{$row['scale']}";
            } else {
                $length = "";
            }
            $full_type = $type . ($unsigned ? " UNSIGNED " : "") . ($length ? "($length)" : "");
            $field = array("type" => $type,
                           "length" => $length,
                           "unsigned" => $unsigned,
                           'full_type' => $full_type);

            if ($row["name"] == "Return Type") {
                $return["returns"] = $field;
            } else {
                $field["field"] = $row["name"];
                $field["inout"] = ($row["status2"] & 2) ? "OUT" : "IN";
                $return["fields"][] = $field;
            }
        }

        $return["definition"] = "";
        foreach (get_rows("
SELECT text
FROM syscomments
WHERE id IN (
  SELECT id
  FROM sysobjects
  WHERE name = " . q($name) . "
)
ORDER BY colid2, colid") as $row) {
            $return["definition"] .= $row["text"];
        }
        $return["definition"] = preg_replace("/^.*?([\n\s]AS[\n\s])/si", "$1", $return["definition"]);

        return $return;
    }

    function routines() {
        $return = array();
        $connection = connection();
        $ncharsize = $connection->result("SELECT @@ncharsize");
        foreach (get_rows("
SELECT
  user_name(so.uid) AS user_name,
  so.name,
  so.type AS routine_type,
  st.name AS type,
  st.prec,
  st.scale
FROM sysobjects AS so
LEFT OUTER JOIN syscolumns AS sc ON sc.id = so.id AND sc.name = 'Return Type'
LEFT OUTER JOIN systypes AS st ON st.type = sc.type
WHERE so.type IN ('SF', 'P')
ORDER BY so.name") as $row) {
            if (preg_match('/^u(.*int)$/', $row["type"], $matches)) {
                $type = $matches[1];
                $unsigned = "unsigned";
            } else {
                $type = $row["type"];
                $unsigned = "";
            }
            if (preg_match("~char|binary~", $type)) {
                if ($row["type"] == "nchar" || $row["type"] == "nvarchar") {
                    $length = $row["length"] / $ncharsize;
                } else {
                    $length = $row["length"];
                }
            } elseif (preg_match("/^numeric|^decimal/", $type)) {
                $length = "{$row['prec']},{$row['scale']}";
            } else {
                $length = "";
            }
            $return[] = array("SPECIFIC_NAME" => $row["name"],
                              "ROUTINE_NAME" => "{$row['user_name']}.{$row['name']}",
                              "ROUTINE_TYPE" => $row["routine_type"] == 'SF' ? "FUNCTION" : "PROCEDURE",
                              "DTD_IDENTIFIER" => $type . ($unsigned ? " UNSIGNED " : "") . ($length ? "($length)" : ""),
                              );
        }
        return $return;
    }

    function routine_languages() {
        return array();
    }

    function routine_id($name, $row) {
        return idf_escape($name);
    }

    function support($feature) {
        $support = array(//"comment",
                         "columns",
                         "copy",
                         "database",
                         "descidx",
                         "drop_col",
                         "dump",
                         //"event",
                         "indexes",
                         "kill",
                         //"materializedview",
                         //"move_col",
                         //"partitioning",
                         //"privileges",
                         "procedure",
                         "processlist",
                         "routine",
                         //"scheme",
                         //"sequence",
                         "sql",
                         "status",
                         "table",
                         "trigger",
                         //"type",
                         "variables",
                         "view",
                         //"view_trigger",
                         );
        return in_array($feature, $support);
    }

    function driver_config() {
        $types = array();
        $structured_types = array();
        foreach (array( //! use sys.types
            lang('Numbers') => array("tinyint" => 3,
                                     "smallint" => 5,
                                     "int" => 10,
                                     "bigint" => 20,
                                     "bit" => 1,
                                     "numeric" => 0,
                                     "decimal" => 0,
                                     "real" => 12,
                                     "float" => 53,
                                     "smallmoney" => 10,
                                     "money" => 20),
            lang('Date and time') => array("date" => 10,
                                           "smalldatetime" => 19,
                                           "datetime" => 19,
                                           "time" => 8),
            lang('Strings') => array("char" => 8000,
                                     "varchar" => 8000,
                                     "text" => 2147483647,
                                     "nchar" => 4000,
                                     "nvarchar" => 4000,
                                     "ntext" => 1073741823),
            /*
            lang('Binary') => array("binary" => 8000,
                                    "varbinary" => 8000,
                                    "image" => 2147483647),
            */
        ) as $key => $val) {
            $types += $val;
            $structured_types[$key] = array_keys($val);
        }
        return array(
            'possible_drivers' => array("SYBASE", "PDO_DBLIB"),
            'jush' => (isset($_GET["trigger"]) &&
                       $_SERVER["REQUEST_METHOD"] == "POST") ? "mssql" : "sybase",
            'types' => $types,
            'structured_types' => $structured_types,
            'unsigned' => array('unsigned'),
            'operators' => array("=", "<", ">", "<=", ">=", "<>", "!>", "!<", "BETWEEN", "LIKE", "LIKE %%", "IN", "IS NULL", "NOT LIKE", "NOT IN", "IS NOT NULL"),
            'functions' => array("char_length", "len", "lower", "upper"),
            'grouping' => array("avg", "count", "count distinct", "max", "min", "sum"),
            'edit_functions' => array(
                array(
                    "date|time" => "getdate",
                ), array(
                    "int|decimal|real|float|money|datetime" => "+/-",
                    "char|text" => "+",
                )
            ),
        );
    }
}

class AdminerSybaseDriver {
    private $_debug_sql = false;
    private $_output_html = false;

    function __construct($debug_sql=false) {
        $this->_debug_sql = $debug_sql;
    }

    function dumpData($table, $style, $query) {
        $connection = connection();
        $jush = "sybase";
        $max_packet = ($jush == "sqlite" ? 0 : 1048576); // default, minimum is 1024
        if ($style) {
            if ($_POST["format"] == "sql") {
                if ($style == "TRUNCATE+INSERT") {
                    echo truncate_sql($table) . ";\n";
                }
                $fields = fields($table);
            }
            $result = $connection->query($query, 1);
            if ($result) {
                $insert = "";
                $buffer = "";
                $keys = array();
                $suffix = "";
                $fetch_function = ($table != '' ? 'fetch_assoc' : 'fetch_row');
                while ($row = $result->$fetch_function()) {
                    if (!$keys) {
                        $values = array();
                        foreach ($row as $val) {
                            $field = $result->fetch_field();
                            $keys[] = $field->name;
                            $key = idf_escape($field->name);
                            $values[] = "$key = VALUES($key)";
                        }
                        $suffix = ($style == "INSERT+UPDATE" ? "\nON DUPLICATE KEY UPDATE " . implode(", ", $values) : "") . ";\n";
                    }
                    if ($_POST["format"] != "sql") {
                        if ($style == "table") {
                            dump_csv($keys);
                            $style = "INSERT";
                        }
                        dump_csv($row);
                    } else {
                        if (!$insert) {
                            $insert = "INSERT INTO " . table($table) . " (" . implode(", ", array_map('idf_escape', $keys)) . ") VALUES";
                        }
                        foreach ($row as $key => $val) {
                            $field = $fields[$key];
                            $row[$key] = ($val !== null
                                          ? unconvert_field($field, preg_match(number_type(), $field["type"]) && !preg_match('~\[~', $field["full_type"]) && is_numeric($val) ? $val : q(($val === false ? 0 : $val)))
                                          : "NULL"
                            );
                        }
                        $s = ($max_packet ? "\n" : " ") . "(" . implode(",\t", $row) . ")";

                        /*
                        if (!$buffer) {
                            $buffer = $insert . $s;
                        } elseif (strlen($buffer) + 4 + strlen($s) + strlen($suffix) < $max_packet) { // 4 - length specification
                            $buffer .= ",$s";
                        } else {
                            echo $buffer . $suffix;
                            $buffer = $insert . $s;
                        }
                        */
                        echo $insert . $s . "\nGO --isql-go-command\n";
                    }
                }
                if ($buffer) {
                    echo $buffer . $suffix;
                }
            } elseif ($_POST["format"] == "sql") {
                echo "-- " . str_replace("\n", " ", $connection->error) . "\n";
            }
        }
        return true;
    }

    function __destruct() {
        if (!$this->_debug_sql || !$this->_output_html) return;

        global $_sybase_queries;
        $html = "";
        foreach ($_sybase_queries as $val) {
            $query = str_replace("\n", "<br>", $val[0]);
            $sec = $val[1];
            $html .= "<pre><span class=\"jush\">$query</span></pre><span class=\"time\">($sec 秒)</span>";
        }
?>
<script <?php echo nonce(); ?>>
document.addEventListener('DOMContentLoaded', function() {
  var div = document.querySelector('div#content');
  var msg = document.createElement('div');
  msg.setAttribute('class', 'message');
  msg.innerHTML = "Debug <a href=\"#debug_sql\" class=\"toggle\"><?php echo lang('SQL command'); ?></a><div id=\"debug_sql\" class=\"hidden\">" + <?php echo json_encode($html) ?> + "</div>";
  div.appendChild(msg);
  document.querySelector('a[href="#debug_sql"]').onclick = partial(toggle, 'debug_sql');
});
</script>
<?php
    }

    function head() {
        $this->_output_html = true;
?>
<script <?php echo nonce(); ?>>
document.addEventListener('DOMContentLoaded', function() {
<?php
    // remove Alter indexes link
    if (isset($_GET["table"])) {
?>
  document.querySelectorAll('h3#foreign-keys + table tr > :nth-child(3), h3#foreign-keys + table tr > :nth-child(4)').forEach(function(elm) {
    elm.style.display = 'none';
  });
<?php
    } elseif (isset($_GET["foreign"])) {
?>
  document.querySelector("select[name='on_delete']").closest('p').style.display = 'none';
<?php
    } elseif (isset($_GET["procedure"])) {
?>
  if (document.querySelector("form#form input[name='fields[1][field]']")) {
    for (var i = 1; true; i++) {
      var field = document.querySelector("form#form input[name='fields[" + i + "][field]']");
      if (!field) {
        break;
      }
      var found = field.value.trim().match(/^([^\s]+)(?:\s+(unsigned))?\s+([^\s]+)(?:\((.+)\))?\s+([^\s]+)$/);
      if (found) {
        field.value = found[1];
        var unsigned = document.querySelector("form#form select[name='fields[" + i + "][unsigned]']");
        if (unsigned && found[2]) {
          unsigned.value = found[2];
        }
        var type = document.querySelector("form#form select[name='fields[" + i + "][type]']");
        if (type && found[3]) {
          type.value = found[3];
        }
        var length = document.querySelector("form#form input[name='fields[" + i + "][length]']");
        if (length && found[4]) {
          length.value = found[4];
        }
        var inout = document.querySelector("form#form select[name='fields[" + i + "][inout]']");
        if (inout && found[5]) {
          inout.value = found[5];
        }
      }
    }      
    document.querySelector("form#form").addEventListener('submit', function(event) {
      for (var i = 1; true; i++) {
        var field = document.querySelector("form#form input[name='fields[" + i + "][field]']");
        if (!field) {
          break;
        }
        var unsigned = document.querySelector("form#form select[name='fields[" + i + "][unsigned]']");
        if (unsigned) {
          if (unsigned.value) {
            field.value += " " + unsigned.value;
          }
          unsigned.disabled = true;
        }
        var type = document.querySelector("form#form select[name='fields[" + i + "][type]']");
        if (type) {
          field.value += " " + type.value;
          type.disabled = true;
        }
        var length = document.querySelector("form#form input[name='fields[" + i + "][length]']");
        if (length) {
          if(length.value) {
            field.value += "(" + length.value + ")";
          }
          length.disdabled = true;
        }
        var inout = document.querySelector("form#form select[name='fields[" + i + "][inout]']");
        console.log(inout);
        if (inout) {
          field.value += " " + inout.value;
          inout.disabled = true;
        }
        //event.preventDefault();
      }
    });
  }
<?php
    }
?>

  if (document.querySelector('h3#tables-views')) {
    document.querySelector('fieldset span#selected2').closest('fieldset').style.display = 'none';
  }
});
</script>
<?php
    }
}
