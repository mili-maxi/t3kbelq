<?php


/* 
* 	t3kbelq - Trickery best effort locking and queueing
* 
*	@version 1.0.1.
*	@author Alen Milincevic
*
*	@section LICENSE
*
*	Copyright 2013 Alen Milincevic
*
*	Licensed under the Apache License, Version 2.0 (the "License");
*	you may not use this file except in compliance with the License.
*	You may obtain a copy of the License at
*
*	http://www.apache.org/licenses/LICENSE-2.0
*
*	Unless required by applicable law or agreed to in writing, software
*	distributed under the License is distributed on an "AS IS" BASIS,
*	WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
*	See the License for the specific language governing permissions and
*	limitations under the License.
*/

/* - Simple file and PDO based locking mechanism
 * - Simple directory and PDO based queue mechanism
 * 
 * Not implemented, since php built in functionality (or would require major custom php to run):
 * - System V semaphores (http://php.net/manual/en/book.sem.php) (pipelineing, queues)
 * - php pthreads functionality (http://php.net/manual/en/book.pthreads.php)
 * - memcache(d) (http://php.net/manual/en/book.memcache.php)
 * - shared memory (http://php.net/manual/en/book.shmop.php)
 * 
 */

// mkdir/rmdir
// rename
// PDO
/* uuid(/uniqid) and filename combination // TODO
 * uuid(/uniqid) could be intermengled due to nonatominess on some fs-es (i.e. sometimes on NFS)
 * therefore aditional check is done
*/

/*
 * Database (row) format for lock (default DB name: t3klocks )::
 *  - t3klocks - UNIQUE (important, must be UNIQUE!), varchar(255)
 *  
 * Database (row) format for queue (default DB name: t3kqueue ):
 *  - t3kqueue - UNIQUE (important, must be UNIQUE!), varchar(255)
 *  - t3kdata, varchar(255)
 *  - t3kdate, varchar(255)
 *  
 */

class T3kLock {
	
	var $lockrootdir = __DIR__;
	var $lockfilesuffix = ".lock";
	
	// 0 = mkdir/rmdir; 1 = rename;
	// 3 = PDO; 4 = PDO with GET_LOCK() (MySQL);
	// 5 = symbolic link -> TODO: more testing. NOTE: Requires special privilegies under Windows!
	// 6 = chmod (readonly/notreadonly manipulation). Might only work on Linux/POSIX, not on Windows.
	var $locktype = 0; 
	
	/*
	 * Database structure:
	 * TODO - describe
	 */
	
	var $pdoArray = ""; // for PDO reference
	var $pdodsn = "";
	var $pdousername = "";
	var $pdopassword = "";
	
	// PDO insert/delete/select
	var $pdooperation = array(
			"insert"  => "INSERT INTO t3klocks ( t3klocks ) values ( ? )",
			"delete" => "DELETE FROM t3klocks WHERE t3klocks=?",
			"select" => "SELECT t3klocks from t3klocks WHERE t3klocks=?",
	);
	
	// PDO get_lock
	var $pdogetlocktimeout = 60;
	
	public function setRootDir($rootdir) {
		$this->lockrootdir = $rootdir;
	}
	
	public function setLockSuffix($suffix) {
		$this->lockfilesuffix = $suffix;
	}

	public function setLockType($locktype) {
		if (is_numeric($locktype) == false) $locktype = 0;
		if ($locktype < 0)  $locktype = 0;
		if ($locktype > 6)  $locktype = 6;
		$this->locktype = $locktype;
	}
	
	public function setDBDSNUsernamePassword($dsn, $username, $password) {
		$this->pdodsn = $dsn;
		$this->pdousername = $username;
		$this->pdopassword = $password;
	}
	
	public function setDBOperations($pdooperation) {
		$this->pdooperation = $pdooperation;
	}
	
	public function setDBGetLockTimeout($timeout) {
		$this->pdogetlocktimeout = $timeout;
	}
	
	public function lock($id) {

		if ($this->islocked($id)) return false;
		if ($this->locktype == 0) {
			if (mkdir($this->lockrootdir . $id) == false)
				return false;
		}
		if ($this->locktype == 1) {
			if (@rename($this->lockrootdir . $id, $this->lockrootdir . $id . $this->lockfilesuffix) == false)
				return false;
		}
		/* TODO: more testing*/
		if ($this->locktype == 2) {
			$firstpart = substr($id,0, strpos($id, "."));
			$namepart =  substr($id, strpos($id, ".")+1,strlen($id));
			file_put_contents($this->lockrootdir . $namepart, $firstpart, FILE_APPEND | LOCK_EX);
			$firstpartin = file_get_contents($this->lockrootdir . $namepart);
			if ($firstpartin != $firstpart) return false;
		}
		if ($this->locktype == 3) {
			try {
				$this->pdoArray[$id] = new PDO($this->pdodsn);
				$data = array($id);
				$STH = $this->pdoArray[$id]->prepare($this->pdooperation["insert"]);
				$STH->execute($data);
			}catch(PDOException $e) {
				return false;	
			}			
		}
		if ($this->locktype == 4) {
			try {
				$this->pdoArray[$id] = new PDO($this->pdodsn);
				$data = array($id,$this->pdogetlocktimeout);
				$STH = $this->pdoArray[$id]->prepare("SELECT GET_LOCK('?','?')");
				$STH->execute($data);
			}catch(PDOException $e) {
				return false;
			}
		}
		/* Warning! Experimental. For Windows Vista/7, the policy must be updated in order to work
		 * TODO: more testing */
		if ($this->locktype == 5) {
			clearstatcache();
			if (@symlink($this->lockrootdir . $id . $this->lockfilesuffix,$this->lockrootdir . $id) == false)
				return false;
		}
		/* The file with the name of $id MUST EXIST prior to mode change trying.*/
		if ($this->locktype == 6) {
			clearstatcache();
			if (chmod($this->lockrootdir . $id, 0400) == false)
				return false;
		}
		
		return true;
	}
	
	/**
	 * Locking with timeout. This is the main function, that is to be used.
	 * 
	 * @param string $id Any unique opaque string
	 * @param number $timeout the timeout in msec to wait for
	 * @param number $randomretrymsec radnom retry pause, for better random thread concurrency
	 * @return boolean true if successfully locked, false otherwise
	 */
	public function trylockuntiltimeout($id, $timeout = 30, $randomretrymsec = 100) {
		$looping = true;
		$timer = time();
		while($looping == true){
			$locktry = $this->lock($id);
			$looping = false;
			if ($locktry == false) {
				if ($randomretrymsec > 0) {
					$retry = mt_rand(0,$randomretrymsec);
					usleep($retry);
				}				
				$looping = true;
			}
			// timeout
			if ( (time() - $timer) > $timeout) {
				$locktry = false;
				$looping = false; 						
			}
		}
		return $locktry;		
	}
	
	public function unlock($id) {
		if ($this->islocked($id) == false) return false;
		if ($this->locktype == 0) {
			if (rmdir($this->lockrootdir . $id) == false)
				return false;
		}
		if ($this->locktype == 1) {
			if (@rename($this->lockrootdir . $id . $this->lockfilesuffix, $this->lockrootdir . $id) == false)
				return false;
		}
		/* TODO: more testing*/
		if ($this->locktype == 2) {
			$firstpart = substr($id,0, strpos($id, "."));
			$namepart =  substr($id, strpos($id, ".")+1,strlen($id));
			$firstpartin = file_get_contents($this->lockrootdir . $namepart);
			if ($firstpartin != $firstpart) return false;
			if (unlink($this->lockrootdir . $namepart) == false) return false;
		}
		if ($this->locktype == 3) {
			try {		
				$this->pdoArray[$id] = new PDO($this->pdodsn);				
				$data = array($id);
				$STH = $this->pdoArray[$id]->prepare($this->pdooperation["delete"]);
				$STH->execute($data);				
			}catch(PDOException $e) {
				return false;
			}
		}
		if ($this->locktype == 4) {
			try {
				//$this->pdoArray[$id] = new PDO($this->pdodsn); // reuse the pdo, as mysql works this way
				$data = array($id);
				$STH = $this->pdoArray[$id]->prepare("SELECT RELEASE_LOCK('?')");
				$STH->execute($data);
			}catch(PDOException $e) {
				return false;
			}
		}
		/* Warning! Experimental. For Windows Vista/7, the policy must be updated in order to work
		 * TODO: more testing */
		if ($this->locktype == 5) {
			clearstatcache();
			if (unlink($this->lockrootdir . $id) == false)
				return false;
		}
		/* The file with the name of $id MUST EXIST prior to mode change trying.*/
		if ($this->locktype == 6) {
			clearstatcache();
			if (chmod($this->lockrootdir . $id, 0600) == false)
				return false;
		}
		
		return true;
	}
	
	public function islocked($id) {
		if ($this->locktype == 0) {
			if (file_exists($this->lockrootdir . $id))
			return true;
		}
		if ($this->locktype == 1) {
			if (file_exists($this->lockrootdir . $id . $this->lockfilesuffix))
				return true;
		}
		/* TODO: more testing*/
		if ($this->locktype == 2) {
			$firstpart = substr($id,0, strpos($id, "."));
			$namepart =  substr($id, strpos($id, ".")+1,strlen($id));
			if (file_exists($this->lockrootdir . $namepart))
				return true;
		}
		if ($this->locktype == 3) {
			try {
				$this->pdoArray[$id] = new PDO($this->pdodsn);
				$data = array($id);
				$STH = $this->pdoArray[$id]->prepare($this->pdooperation["select"]);
				$STH->execute($data);
				$STH->setFetchMode(PDO::FETCH_ASSOC);
				while($row = $STH->fetch()) {
					return true;
				}
			}catch(PDOException $e) {
				return true;
			}
		}
		if ($this->locktype == 4) {
			try {
				$this->pdoArray[$id] = new PDO($this->pdodsn);
				$data = array($id);
				$STH = $this->pdoArray[$id]->prepare("SELECT IS_FREE_LOCK('?')");
				$STH->execute($data);
				$res = $STH->fetchAll();
				if ($res[0][0] == 1) return false;
			}catch(PDOException $e) {
				return true;
			}
		}
		/* Warning! Experimental. For Windows Vista/7, the policy must be updated in order to work
		 * TODO: more testing */
		if ($this->locktype == 5) {
			clearstatcache();
			if (is_link($this->lockrootdir . $id))
				return true;
		}
		if ($this->locktype == 6) {
			clearstatcache();
			/* Create a dummy file for attribute changes, if not existing(if possible).
			 * This does not delete the file, if lock is released! */
			if (file_exists ($this->lockrootdir) == false) {
				if (file_put_contents($this->lockrootdir . $id, "") == false)
					return false;
			}			
			if (is_writeable($this->lockrootdir . $id) == false)
				return true;
		}
		
		return false;
	}
	
}

class T3kQueue {
	
	var $queueRootDir = __DIR__;
	var $queuetype = 0; // 0 - directory hierachy; 1 - compound filename; 2 - pdo
	var $queueDivider = "\\";
	
	/*
	 * Database structure:
	*
	*/
	
	var $pdoArray = ""; // for PDO reference
	var $pdodsn = "";
	var $pdousername = "";
	var $pdopassword = "";
	
	var $usepid = false;
	
	// PDO insert/delete/select
	var $pdooperation = array(
			"insert"  => "INSERT INTO t3kqueue ( t3kqueue,t3kdata,t3kdate ) values ( ?,?,? )",
			"delete" => "DELETE FROM t3kqueue WHERE t3kqueue=?",
			"select" => "SELECT t3kqueue,t3kdata,t3kdate FROM t3kqueue WHERE t3kqueue LIKE ?",
	);
	
	// PDO column names
	var $pdobindings = array(
			"data" => "t3kdata",
			"fullid" => "t3kqueue",
			"creationdate" => "t3kdate",
	);
	
	public function setRootDir($queueRootDir) {
		$this->queueRootDir = $queueRootDir;		
	}
	
	public function setQueueType($queuetype) {
		if (is_numeric($queuetype) == false) $queuetype = 0;
		if ($queuetype < 0)  $queuetype = 0;
		if ($queuetype > 2)  $queuetype = 2;
		$this->queuetype = $queuetype;
	}
	
	public function setDirectoryDivider($queueDivider) {
		$this->queueDivider = $queueDivider;
	}
	
	public function setDBDSNUsernamePassword($dsn, $username, $password) {
		$this->pdodsn = $dsn;
		$this->pdousername = $username;
		$this->pdopassword = $password;
	}
	
	public function setDBOperations($pdooperation) {
		$this->pdooperation = $pdooperation;
	}
	
	public function setDBBindings($pdobindings) {
		$this->pdobindings = $pdobindings;
	}
	
	public function usePid($usepid) {
		$this->$usepid = $usepid;
	}
	
	public function addToQueue($id, $data) {
		$someid = base64_encode(uniqid());
		if ($this->$usepid == true) $somesid = $somesid . base64_encode(getmypid());
		
		if ($this->queuetype == 2) {
			$pdoresult = "";
			try {
				$pdo = new PDO($this->pdodsn);

				//$currenttime = time(); // due to a weird MySQL bug, this is deprecated
				$currenttime = date('Y-m-d H:i:s');
				
				$data = array($someid . "." . $id,$data,$currenttime);
				$STH = $pdo->prepare($this->pdooperation["insert"]);
				$STH->execute($data);
			}catch(PDOException $e) {
				return false;
			}
			return true;
		}
		
		$queuebase = $this->queueRootDir . $someid . "." . $id;
		if ($this->queuetype == 0) {
			$queuebase = $this->queueRootDir . $id . $this->queueDivider . $someid;
		}
	
		return @file_put_contents($queuebase, $data);
	}
	
	public function getQueue($id) {
		$queue = "";
		
		if ($this->queuetype == 2) {
			$pdoresult = "";
			try {
				$pdo = new PDO($this->pdodsn);
				$data = array("%" . $id);
				$STH = $pdo->prepare($this->pdooperation["select"]);
				$STH->execute($data);
				
				while (($row = $STH->fetch(PDO::FETCH_ASSOC)) != false) {
					$queue[$row["t3kqueue"]]["data"] = $row[$this->pdobindings["data"]];
					$queue[$row["t3kqueue"]]["fullid"] = $row[$this->pdobindings["fullid"]];
					$queue[$row["t3kqueue"]]["creationdate"] = $row[$this->pdobindings["creationdate"]];
				}
				
			}catch(PDOException $e) {
				return false;
			}
			return $queue;
		}
		
		$queuebase = $this->queueRootDir . "*." . $id;
		if ($this->queuetype == 0) {
			$queuebase = $this->queueRootDir . $id . $this->queueDivider . "*";
		}
		
		foreach (glob($queuebase) as $filename) {
			$queue[$filename]['data'] = file_get_contents($filename);
			$queue[$filename]['fullid'] = $filename;
			$queue[$filename]['creationdate'] = filemtime($filename);
		}
		return $queue;
	}
	
	public function deleteFromQueue($id) {
		
		if ($this->queuetype == 2) {
			$pdoresult = "";
			try {
				$pdo = new PDO($this->pdodsn);
				foreach ($id as $columnvalue) {
					$data = array($columnvalue["fullid"]);
					$STH = $pdo->prepare($this->pdooperation["delete"]);
					$STH->execute($data);
				}
			}catch(PDOException $e) {
				return false;
			}
			return true;
		}
		
		foreach ($id as $filename) {
			@unlink($filename['fullid']); // TODO: some error handling, if failed
		}
		return true;
	}
	
	
}

?>