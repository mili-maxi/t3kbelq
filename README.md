"Trick best effort locking and queueing" - a best effort library to make a PHP exclusive locking using (probably atomic) filesystem operations (mkdir/rename) or/and (probably ACID) database operations (via PDO).
by Alen Milincevic 2013

Idea
====

Make a simple generic API for more exotic multithread locking.
The problem is, that PHP, especially when run on a web server (multiple independent processes) is essentialy not thread safe by design.
In order to try to mediate this issue, some POSIX atomic operations could be (miss)used to have a simulated locking, as good as possible.
Queueing is an idea, to have some data queued to be processed one by one (an external application logic must do that) and then the shedule deleted.

This is only a prototype, which was born out of frustration, that other available locking libraries were not efficient, not generic enough or to complicated.

Apache license was chosen as a compromise, since it does not make any "lock-in" (like copyright licences) nor "lock-out" (like too radical copyleft licences). Some other available libraries have that issues...

Trivial examples
================

Simple lock usage:
------------------

1. The subdirectory locker must be preexistant.
Lock control is a subdirectory "blabla".

$lock = new T3kLock();
$lock->setRootDir(__DIR__ . "\\locker\\");
$lock->setLockType(0);
echo "blabla is locked (step 1):" . $lock->islocked("blabla") . "<BR>";
$lock->lock("blabla");
echo "blabla is locked (step 2):" . $lock->islocked("blabla") . "<BR>";
$lock->unlock("blabla");
echo "blabla is locked (step 3):" . $lock->islocked("blabla") . "<BR>";

2. The subdirectory locker must be preexistant.
Lock control is the renaming of "blabla" from/to "blabla.lock".
Either "blabla" or "blabla.lock" must preexist, according to wanted initial state.

$lock = new T3kLock();
$lock->setRootDir(__DIR__ . "\\locker\\");
$lock->setLockType(1);
echo "blabla is locked (step 1):" . $lock->islocked("blabla") . "<BR>";
$lock->lock("blabla");
echo "blabla is locked (step 2):" . $lock->islocked("blabla") . "<BR>";
$lock->unlock("blabla");
echo "blabla is locked (step 3):" . $lock->islocked("blabla") . "<BR>";

3. The database table must be existing previously.
The DSN/username/password must match the configured ones.

$lock->setLockType(3);
$lock->setDBDSNUsernamePassword("mysql:host=localhost;port=3307;dbname=test", "root", "usbw");
echo "blabla is locked (step 1):" . $lock->islocked("blabla") . "<BR>";
$lock->lock("blabla");
echo "blabla is locked (step 2):" . $lock->islocked("blabla") . "<BR>";
$lock->unlock("blabla");
echo "blabla is locked (step 3):" . $lock->islocked("blabla") . "<BR>";

4. The database table must be existing previously.
As here get_lock() is used, nothing is actually modified physically.
The DSN/username/password must match the configured ones.

$lock->setLockType(4);
$lock->setDBDSNUsernamePassword("mysql:host=localhost;port=3307;dbname=test", "root", "usbw");
echo "blabla is locked (step 1):" . $lock->islocked("blabla") . "<BR>";
$lock->lock("blabla");
echo "blabla is locked (step 2):" . $lock->islocked("blabla") . "<BR>";
$lock->unlock("blabla");
echo "blabla is locked (step 3):" . $lock->islocked("blabla") . "<BR>";

5. ! Highly experimental !
Idea: one file, and a unique ID is written in.
The subdirectory "locker" must exist prior to first call.
In concurrency situations, only one will succed, or they will be intermingeled (the problem, needs some cleaning mechanism...)
Depending on the computer architecture, filesystem and OS used (POSIX or not), this has a high chance to succeed anyway without a problem.

$lock->setRootDir(__DIR__ . "\\locker\\");
$lock->setLockType(2);
echo "blabla is locked (step 1):" . $lock->islocked("blabla") . "<BR>";
$lock->lock("blabla");
echo "blabla is locked (step 2):" . $lock->islocked("blabla") . "<BR>";
$lock->unlock("blabla");
echo "blabla is locked (step 3):" . $lock->islocked("blabla") . "<BR>";


Simple queuing usage:
---------------------

1. The subdirectory queue must be preexistant.
Queue control is a subdirectory "blabla".
A random ID is prepended to generated files.

$queue = new T3kQueue();
$queue->setRootDir(__DIR__ . "\\queue\\");
$queue->setQueueType(0);
$queue->addToQueue("queuer","besetzt");
$queue->deleteFromQueue($queue->getQueue("queuer"));

2. The subdirectory queue must be preexistant.
Queue control is a subdirectory "blabla".

$queue = new T3kQueue();
$queue->setRootDir(__DIR__ . "\\queue\\");
$queue->setQueueType(1);
$queue->addToQueue("queuer","besetzt");
$queue->deleteFromQueue($queue->getQueue("queuer"));

3. The database table must be existing previously.
The DSN/username/password must match the configured ones.

$queue = new T3kQueue();
$queue->setDBDSNUsernamePassword("mysql:host=localhost;port=3307;dbname=test", "root", "usbw");
$queue->setQueueType(2);
$queue->addToQueue("queuer","besetzt");
$queue->deleteFromQueue($queue->getQueue("queuer"));


Additional optional functions:
==============================

locking
-------

- public function setLockSuffix($suffix)
- public function setDBOperations($pdooperation)
- public function setDBGetLockTimeout($timeout) 

queueing
-------

- public function setDirectoryDivider($queueDivider)
- public function setDBOperations($pdooperation)
- public function setDBBindings($pdobindings)


Proper locking trivial example
==============================

<?php
	$lock = new T3kLock();
	$lock->setRootDir(__DIR__ . "\\locker\\");
	$lock->setLockType(0);
	$result = $lock->trylockuntiltimeout("anylock99", 30, 100);
	if ($result == false) {
		echo "Locking failed. Timeout or other error!";
	} else {
		echo "Anylock99 succedeed!";
	}
	echo "Is Anylock99?" . $lock->islocked("anylock99") . "<BR>";
	$result = $result->unlock("anylock99");
	if ($result == false) {
		echo "Clearing lock failed!";
	} else {
		echo "Clearing lock succeeded!";
	}
	echo "Still Anylock99?" . $lock->islocked("anylock99") . "<BR>";
?php>

"trylockuntiltimeout" has three parameters: opaque unique lock id, timeout to try and a random pause between retries.
Opaque unique lock id is mandatory. If omited, timeout and random pause are used by their default values.

Experimental symlink locking
============================
Use at your own risk. Will need special permission in Windows Vista and higher.

