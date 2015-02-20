<?php

require_once dirname(__FILE__) . '/Generic.php';


/**
 * Database class for MySQL.
 */
class DbSimple_Mysqli extends DbSimple_Generic_Database
{
	const DEFAULT_CHARSET = 'UTF8';
	const DEFAULT_CONNECTION_TIMEOUT = 1;

	const CR_SERVER_GONE_ERROR = 2006;
	const CR_SERVER_LOST = 2013;
	const CR_INVALID_CONN_HANDLE = 2048;
	const CR_SERVER_LOST_EXTENDED = 2055;

	private static $retryClientErrors = array(
		self::CR_SERVER_GONE_ERROR,
		self::CR_SERVER_LOST,
		self::CR_INVALID_CONN_HANDLE,
		self::CR_SERVER_LOST_EXTENDED
	);

	protected $dsn;

	/**
	 * @var mysqli
	 */
	protected $link;

	/**
	 * constructor(string $dsn)
	 * Connect to MySQL.
	 */
	function __construct($dsn)
	{
		$this->dsn = $dsn;
	}


	function _performEscape($s, $isIdent)
	{
		if (!$this->link) {
			$this->_lazyConnect();
		}

		if (!$isIdent) {
			return "'" . $this->link->real_escape_string($s) . "'";
		} else {
			return "`" . str_replace('`', '``', $s) . "`";
		}
	}


	function _performTransaction($parameters = null)
	{
		return $this->link->begin_transaction();
	}

	function _performGetPlaceholderIgnoreRe()
	{
		return '
            "   (?> [^"\\\\]+|\\\\"|\\\\)*    "   |
            \'  (?> [^\'\\\\]+|\\\\\'|\\\\)* \'   |
            `   (?> [^`]+ | ``)*              `   |   # backticks
            /\* .*?                          \*/      # comments
        ';
	}

	function _performCommit()
	{
		return $this->link->commit();
	}

	function _performRollback()
	{
		return $this->link->rollback();
	}

	function _performTransformQuery(&$queryMain, $how)
	{
		// If we also need to calculate total number of found rows...
		switch ($how) {
			// Prepare total calculation (if possible)
			case 'CALC_TOTAL':
				$m = null;
				if (preg_match('/^(\s* SELECT)(.*)/six', $queryMain[0], $m))
					$queryMain[0] = $m[1] . ' SQL_CALC_FOUND_ROWS' . $m[2];
				return true;

			// Perform total calculation.
			case 'GET_TOTAL':
				// Built-in calculation available?
				$queryMain = array('SELECT FOUND_ROWS()');
				return true;
		}
		return false;
	}

	function _performQuery($queryMain)
	{
		$this->_lastQuery = $queryMain;
		$this->_expandPlaceholders($queryMain, false);
		$result = @$this->link->query($queryMain[0]);

		// If server has gone away, try to reconnect and repeat query once
		if (
			!$result
			&& in_array($this->link->errno, self::$retryClientErrors)
		) {
			$this->disconnect();
			$this->_lazyConnect();
			$result = @$this->link->query($queryMain[0]);
		}

		if (!$result && $this->link->errno) {
			return $this->_setDbError($queryMain[0]);
		}

		if ($result === true) {
			if (preg_match('/^\s* INSERT \s+/six', $queryMain[0])) {
				if ($this->link->affected_rows == 1) {
					return $this->link->insert_id;
				}
			}

			return $this->link->affected_rows;
		}

		Assert::isTrue($result instanceof mysqli_result, 'mysqli_result instance expected');

		return $result;
	}


	/**
	 * @param mysqli_result $result
	 * @return null|array
	 */
	function _performFetch($result)
	{
		Assert::isTrue(
			$result instanceof mysqli_result,
			'%s got unexpected result: %s',
			__METHOD__, $result
		);

		$row = $result->fetch_assoc();
		if (is_null($row) && $this->link->error) {
			return $this->_setDbError($this->_lastQuery);
		}
		return $row;
	}

	function _freeResult($result)
	{
		if ($result instanceof mysqli_result) {
			$result->free_result();
		}
	}

	protected function _setDbError($query)
	{
		return $this->_setLastError($this->link->errno, $this->link->error, $query);
	}

	function _lazyConnect()
	{
		$p = DbSimple_Generic::parseDSN($this->dsn);

		$base = preg_replace('{^/}s', '', $p['path']);
		if (!class_exists('mysqli')) {
			return $this->_setLastError('-1', 'mysqli extension is not loaded', 'mysqli');
		}

		mysqli_report(MYSQLI_REPORT_OFF);
		$this->link = mysqli_init();

		$this->link->options(
			MYSQLI_OPT_CONNECT_TIMEOUT,
			isset($p['timeout']) && $p['timeout'] ? $p['timeout'] : self::DEFAULT_CONNECTION_TIMEOUT
		);

		@$this->link->real_connect(
			(isset($p['persist']) && $p['persist']) ? 'p:' . $p['host'] : $p['host'],
			$p['user'],
			isset($p['pass']) ? $p['pass'] : '',
			$base,
			empty($p['port']) ? NULL : $p['port'],
			NULL,
			(isset($p['compression']) && $p['compression'])
				? MYSQLI_CLIENT_COMPRESS : NULL
		);

		$this->_resetLastError();
		if ($this->link->connect_errno) {
			$dsnHiddenPassword = DbSimple_Generic::dsnWithHiddenPassword($p);
			return $this->_setLastError($this->link->connect_errno, "mysqli_connect($dsnHiddenPassword): " . $this->link->connect_error, "mysqli_connect($dsnHiddenPassword)");
		}

		if ($this->link->errno) {
			return $this->_setDbError("mysql_select_db({$base})");
		}

		$charset = @$p['charset'] ?: self::DEFAULT_CHARSET;
		if (!$this->link->set_charset($charset)) {
			return $this->_setDbError("set_charset({$charset})");
		}

		$this->_performQuery('SET time_zone=\'' . date('P') . '\'');
	}

	function disconnect()
	{
		@$this->link->close();
		$this->link = null;
	}

	function getAffectedRows()
	{
		return $this->link->affected_rows;
	}
}
