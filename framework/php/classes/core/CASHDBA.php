<?php
/**
 * No frills DBA connection class using PHP's PDO library. CASHDBA provides 
 * easy functions for data access using a get/set convention and auto-detection 
 * of conditions. All database tables are abstracted using a lookupTableName() 
 * function to centralize any future schema changes.
 *
 * @package diy.org.cashmusic
 * @author CASH Music
 * @link http://cashmusic.org/
 *
 * Copyright (c) 2011, CASH Music
 * Licensed under the Affero General Public License version 3.
 * See http://www.gnu.org/licenses/agpl-3.0.html
 *
 **/
class CASHDBA {
	public    $error = 'Relax. Everything is okay.';
	protected $db;
	private   $hostname,
			  $username,
			  $password,
			  $dbname,
			  $driver,
			  $port;

	public function __construct($hostname,$username,$password,$database,$driver) {
		if (strpos($hostname,':') === false) {
			$this->hostname = $hostname;
			$this->port = 3306;
		} else {
			if (substr($hostname,0,2) == ':/') {
				$this->hostname = $hostname;
			} else {
				$host_and_port = explode(':',$hostname);
				$this->hostname = $host_and_port[0];
				$this->port = $host_and_port[1];
			}
		}
		$this->username = $username;
		$this->password = $password;
		$this->dbname = $database;
		$this->driver = $driver;
	}

	public function connect() {
		try {  
			if ($this->driver == 'sqlite') {
				$this->db = new PDO("sqlite:" . CASH_PLATFORM_ROOT . "/../db/{$this->dbname}");
			} else {
				if (substr($this->hostname,0,2) == ':/') {
					$this->db = new PDO("{$this->driver}:unix_socket={$this->hostname};dbname={$this->dbname}", $this->username, $this->password);
				} else {
					$this->db = new PDO("{$this->driver}:host={$this->hostname};port={$this->port};dbname={$this->dbname}", $this->username, $this->password);
				}
			}
			// For try/catch (production)
			$this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			
			// For in-workflow php_error.log dumps (dev)
			//$this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING );
		} catch(PDOException $e) {  
			$this->error = $e->getMessage();  
			echo $this->error;
			die();
		}
	}

	public function getErrorMessage() {
		return $this->error;
	}
	
	public function lookupTableName($data_name) {
		$table_lookup = array(
			'analytics' => 'system_analytics',
			'assets' => 'assets',
			'assets_analytics' => 'assets_analytics',
			'commerce_assets' => 'commerce_assets',
			'connections' => 'system_connections',
			'elements' => 'elements',
			'elements_analytics' => 'elements_analytics',
			'events' => 'calendar_events',
			'items' => 'commerce_items',
			'lock_codes' => 'system_lock_codes',
			'metadata' => 'system_metadata',
			'offers' => 'commerce_offers',
			'orders' => 'commerce_orders',
			'settings' => 'system_settings',
			'users' => 'people',
			'people_analytics' => 'people_analytics',
			'people_lists' => 'people_lists',
			'list_members' => 'people_lists_members',
			'transactions' => 'commerce_transactions',
			'venues' => 'calendar_venues'
		);
		if (array_key_exists($data_name, $table_lookup)) {
		    return $table_lookup[$data_name];
		} else {
			return false;
		}
	}

	public function doQuery($query,$values=false) {
		if ($values) {
			$q = $this->db->prepare($query);
			$q->execute($values);
		} else {
			$q = $this->db->query($query);
		}
		$q->setFetchMode(PDO::FETCH_ASSOC);
		try {  
			$result = $q->fetchAll();
		} catch(PDOException $e) {  
			$this->error = $e->getMessage();
		}
		if ($result) {
			if (count($result) == 0) {
				return false;
			} else {
				return $result;
			}
		}
	}
	
	public function getRealTableNames() {
		if ($this->driver == 'sqlite') {
			$query = 'SELECT name FROM sqlite_master WHERE type=\'table\'';
		} else {
			$query = 'SELECT DISTINCT(table_name) FROM information_schema.columns WHERE table_schema = \'' . $this->dbname . '\'';
		}
		if (!is_object($this->db)) {
			$this->connect();
		}
		$result = $this->doQuery($query);
		if (is_array($result)) {
			// if we got a result, get ready to strip it to just an array of table names
			$names_only = array();
			foreach ($result as $table) {
				// this removes text text key and makes the value easier to reference consistently
				$stripped_table = array_values($table);
				$names_only[] = $stripped_table[0];
			}
			// sort alphabetically to return consistent results no matter the DB engine
			sort($names_only);
			return $names_only;
		} else {
			return false;
		}
	}
	
	public function migrateDB($todriver='mysql',$tosettings=false) {
		/* for mysql we're expecting a $tosettings array that looks like:
		   hostname => hostname[:port]
		   username => username
		   password => password
		   database => databasename
		*/
		if ($todriver != 'mysql' || !is_array($tosettings)) {
			return false;
		} else {
			$newdb_hostname = false;
			$newdb_port = false;
			if (strpos($tosettings['hostname'],':') === false) {
				$newdb_hostname = $tosettings['hostname'];
				$newdb_port = 3306;
			} else {
				if (substr($tosettings['hostname'],0,2) == ':/') {
					$newdb_hostname = $tosettings['hostname'];
				} else {
					$host_and_port = explode(':',$tosettings['hostname']);
					$newdb_hostname = $host_and_port[0];
					$newdb_port = $host_and_port[1];
				}
			}
			if ($newdb_hostname) {
				try {  
					if (substr($this->hostname,0,2) == ':/') {
						$newdb = new PDO("$todriver:unix_socket=$newdb_hostname;dbname={$tosettings['database']}", $tosettings['username'], $tosettings['password']);
					} else {
						$newdb = new PDO("$todriver:host=$newdb_hostname;port=$newdb_port;dbname={$tosettings['database']}", $tosettings['username'], $tosettings['password']);
					}
					$newdb->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
				} catch(PDOException $e) {  
					return false;
				}
				// run the baseline sql file — will blow away any old bullshit but leave non-standard tables
				if ($newdb->query(file_get_contents(CASH_PLATFORM_ROOT.'/settings/sql/cashmusic_db.sql'))) {
					// begin a transaction for the newdb
					$newdb->beginTransaction();
					// get all the current tables
					$current_tables = $this->getRealTableNames();
					foreach ($current_tables as $tablename) {
						// looping through and starting the CRAAAAZZZZEEEEEE
						// first get all data in the current table
						$tabledata = $this->doQuery('SELECT * FROM ' . $tablename);
						// now jam that junk into an insert on the new db
						if (is_array($tabledata)) {
							// we found data, so loop through each one with an insert
							foreach ($tabledata as $data) {
								$query = "INSERT INTO $tablename (";
								$separator = '';
								$query_columns = '';
								$query_values = '';
								foreach ($data as $fieldname => $value) {
									$query_columns .= $separator.$fieldname;
									$query_values .= $separator.':'.$fieldname;
									$separator = ',';
								}
								$query .= "$query_columns) VALUES ($query_values)";
								try {  
									$q = $newdb->prepare($query);
									$q->execute($data);
								} catch(PDOException $e) {
									// something went wrong. roll back and quit
									$newdb->rollBack();
									return false;
								}
							}
						}
					}
					// fuckin a right.
					$result = $newdb->commit();
					if ($result) {
						CASHSystem::setSystemSetting('driver',$todriver);
						CASHSystem::setSystemSetting('hostname',$tosettings['hostname']);
						CASHSystem::setSystemSetting('username',$tosettings['username']);
						CASHSystem::setSystemSetting('password',$tosettings['password']);
						CASHSystem::setSystemSetting('database',$tosettings['database']);
						return $result;
					} else {
						return $result;
					}
					
				}
			} else {
				return false;
			}
		}
	}
	
	public function parseConditions($conditions,$prepared=true) {
		$return_str = " WHERE ";
		$separator = '';
		foreach ($conditions as $value => $details) {
			if ($prepared) {
				$return_str .= $separator . $value . ' ' . $details['condition'] . ' ' . ':where' . $value;
			} else {
				if (is_string($details['value'])) {
					$query_value = "'" . str_replace("'","\'",$details['value']) . "'";
				} else {
					$query_value = $details['value'];
				}
				$return_str .= $separator . $value . ' ' . $details['condition'] . ' ' . $query_value;
			}
			$separator = ' AND ';
		}
		return $return_str;
	}

	public function getData($data_name,$data,$conditions=false,$limit=false,$orderby=false) {
		if (!is_object($this->db)) {
			$this->connect();
		}
		$query = false;
		$table_name = $this->lookupTableName($data_name);
		if ($table_name === false) {
			return $this->getSpecialData($data_name,$conditions,$limit,$orderby);
		}
		if ($data) {
			$query = "SELECT $data FROM $table_name";
			if ($conditions) {
				$query .= $this->parseConditions($conditions);
			}
			if ($orderby) $query .= " ORDER BY $orderby";
			if ($limit) $query .= " LIMIT $limit";
		}
		if ($query) {
			if ($conditions) {
				$values_array = array();
				foreach ($conditions as $value => $details) {
					$values_array[':where'.$value] = $details['value'];
				}
				return $this->doQuery($query,$values_array);
			} else {
				return $this->doQuery($query);
			}
		} else {
			return false;
		}
	}
	
	public function setData($data_name,$data,$conditions=false) {
		if (!is_object($this->db)) {
			$this->connect();
		}
		$query = false;
		$table_name = $this->lookupTableName($data_name);
		if (is_array($data) && $table_name) {
			if ($conditions) {
				// if $condition is set then we're doing an UPDATE
				$data['modification_date'] = time();
				$query = "UPDATE $table_name SET ";
				$separator = '';
				foreach ($data as $fieldname => $value) {
					$query .= $separator."$fieldname=:$fieldname";
					$separator = ',';
				}
				$query .= $this->parseConditions($conditions);

				$values_array = array();
				foreach ($conditions as $value => $details) {
					$values_array[':where'.$value] = $details['value'];
				}
				$data = array_merge($data,$values_array);
			} else {
				// no condition? we're doing an INSERT
				$data['creation_date'] = time();
				$query = "INSERT INTO $table_name (";
				$separator = '';
				foreach ($data as $fieldname => $value) {
					$query .= $separator.$fieldname;
					$separator = ',';
				}
				$query .= ") VALUES (";
				$separator = '';
				foreach ($data as $fieldname => $value) {
					$query .= $separator.':'.$fieldname;
					$separator = ',';
				}
				$query .= ")";
			}
			if ($query) {
				try {  
					$q = $this->db->prepare($query);
					$success = $q->execute($data);
					if ($success) {
						if ($conditions) {
							if (array_key_exists('id',$conditions)) {
								return $conditions['id']['value'];
							} else {
								return true;
							}
						} else {
							return $this->db->lastInsertId();
						}
					} else {
						return false;
					}
				} catch(PDOException $e) {  
					$this->error = $e->getMessage();
				}	
			} else {
				return false;
			}
		} else {
			return false;
		}
	}
	
	public function deleteData($data_name,$conditions=false) {
		if (!is_object($this->db)) {
			$this->connect();
		}
		$query = false;
		$table_name = $this->lookupTableName($data_name);
		if ($conditions) {
			$query = "DELETE FROM $table_name" . $this->parseConditions($conditions,false);
			try {  
				$result = $this->db->exec($query);
				if ($result) {
					return true;
				} else {
					return false;
				}
			} catch(PDOException $e) {  
				$this->error = $e->getMessage();
				die();
			}
		} else {
			return false;
		}
	}
	
	public function getSpecialData($data_name,$conditions=false,$limit=false,$orderby=false) {
		if (!is_object($this->db)) {
			$this->connect();
		}
		switch ($data_name) {
			case 'AssetPlant_getAnalytics_mostaccessed':
				$query = "SELECT aa.asset_id as 'id', COUNT(aa.id) as 'count', a.title as 'title', a.description as 'description' "
				. "FROM assets_analytics aa JOIN assets a ON aa.asset_id = a.id "
				. "WHERE a.user_id = :user_id AND a.parent_id = 0 "
				. "GROUP BY aa.asset_id "
				. "ORDER BY count DESC";
				break;
			case 'ElementPlant_getAnalytics_mostactive':
				$query = "SELECT ea.element_id as 'id', COUNT(ea.id) as 'count', e.name as 'name' "
				. "FROM elements_analytics ea JOIN elements e ON ea.element_id = e.id "
				. "WHERE e.user_id = :user_id AND ea.access_time > " . (time() - 1209600) . " " // active == used in the last 2 weeks
				. "GROUP BY ea.element_id "
				. "ORDER BY count DESC";
				break;
			case 'ElementPlant_getAnalytics_elementbylocation':
				$query = "SELECT access_location, COUNT(cash_session_id) as 'total' "
				. "FROM elements_analytics "
				. "WHERE element_id = :element_id "
				. "GROUP BY access_location "
				. "ORDER BY total DESC";
				break;
			case 'ElementPlant_getAnalytics_elementbymethod':
				$query = "SELECT access_method, COUNT(cash_session_id) as 'total' "
				. "FROM elements_analytics "
				. "WHERE element_id = :element_id "
				. "GROUP BY access_method "
				. "ORDER BY total DESC";
				break;
			case 'PeoplePlant_getAnalytics_listmembership':
				$query = "SELECT COUNT(*) AS total, COUNT(CASE WHEN active = 1 THEN 1 END) AS active, COUNT(CASE WHEN active = 0 THEN 1 END) AS inactive, COUNT(CASE WHEN creation_date > " . (time() - 604800) . " THEN 1 END) AS last_week "
				. "FROM people_lists_members "
				. "WHERE list_id = :list_id";
				break;
			case 'PeoplePlant_getUsersForList':
				$query = "SELECT u.id,u.email_address,u.display_name,"
				. "l.initial_comment,l.additional_data,l.active,l.verified,l.creation_date "
				. "FROM people u LEFT OUTER JOIN people_lists_members l ON u.id = l.user_id "
				. "WHERE l.list_id = :list_id AND l.active = 1";
				if ($orderby) $query .= " ORDER BY $orderby";
				if ($limit) $query .= " LIMIT $limit";
				break;
			case 'PeoplePlant_getVerifiedUsersForList':
				$query = "SELECT u.id,u.email_address,u.display_name,"
				. "l.initial_comment,l.additional_data,l.creation_date "
				. "FROM people u LEFT OUTER JOIN people_lists_members l ON u.id = l.user_id "
				. "WHERE l.list_id = :list_id AND l.verified = 1 AND l.active = 1";
				if ($orderby) $query .= " ORDER BY $orderby";
				if ($limit) $query .= " LIMIT $limit";
				break;
			case 'CalendarPlant_getDatesBetween':
				$query = "SELECT e.id as 'event_id', e.date as 'date',e.published as 'published',e.cancelled as 'cancelled',e.purchase_url as 'purchase_url',e.comments as 'comments',e.creation_date as 'creation_date',e.modification_date as 'modification_date', "
				. "v.name as 'venue_name',v.address1 as 'venue_address1',v.address2 as 'venue_address2',v.city 'venue_city',v.region as 'venue_region',v.country as 'venue_country',v.postalcode as 'venue_postalcode',v.url as 'venue_url',v.phone as 'venue_phone'"
				. "FROM calendar_events e LEFT OUTER JOIN calendar_venues v ON e.venue_id = v.id "
				. "WHERE e.date > :cutoff_date_low AND e.date < :cutoff_date_high AND e.user_id = :user_id AND e.published = :published_status AND e.cancelled = :cancelled_status ORDER BY e.date ASC";
				break;
			case 'CalendarPlant_getEvent':
				$query = "SELECT e.id as 'event_id', e.user_id as 'user_id', e.date as 'date',e.published as 'published',e.cancelled as 'cancelled',e.purchase_url as 'purchase_url',e.comments as 'comments',e.creation_date as 'creation_date',e.modification_date as 'modification_date', "
				. "v.id as 'venue_id',v.name as 'venue_name',v.address1 as 'venue_address1',v.address2 as 'venue_address2',v.city 'venue_city',v.region as 'venue_region',v.country as 'venue_country',v.postalcode as 'venue_postalcode',v.url as 'venue_url',v.phone as 'venue_phone'"
				. "FROM calendar_events e LEFT OUTER JOIN calendar_venues v ON e.venue_id = v.id "
				. "WHERE e.id = :event_id LIMIT 1";
				break;
		    default:
		       return false;
		}
		if ($query) {
			if ($conditions) {
				$values_array = array();
				foreach ($conditions as $value => $details) {
					$values_array[':'.$value] = $details['value'];
				}
				return $this->doQuery($query,$values_array);
			} else {
				return $this->doQuery($query);
			}
		} else {
			return false;
		}
	}
} // END class 
?>
