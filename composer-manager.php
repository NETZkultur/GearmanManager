#!/usr/bin/env php
<?php

if (file_exists(__DIR__ . '/../../autoload.php')) {
	require __DIR__ . '/../../autoload.php';
}
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
	require __DIR__ . '/vendor/autoload.php';
}


/**
 * Implements the worker portions of the PEAR Net_Gearman library
 *
 * @author      Brian Moon <brian@moonspot.net>
 * @copyright   1997-Present Brian Moon
 * @package     GearmanManager
 *
 */

declare(ticks = 1);

/**
 * Uncomment and set to your prefix.
 */
//define("NET_GEARMAN_JOB_CLASS_PREFIX", "");

/**
 * Implements the worker portions of the PEAR Net_Gearman library
 */
class ComposerManager extends GearmanManager {

	public static $LOG = array();

	private $start_time;

	protected function start_lib_worker($worker_list, $timeouts = array()) {

		$thisWorker = new \Net\Gearman\Worker();

		foreach ($this->servers as $s) {
			$this->log("Adding server $s", GearmanManager::LOG_LEVEL_WORKER_INFO);
			$thisWorker->addServers($s);
		}

		foreach ($worker_list as $w) {
			$timeout = (isset($timeouts[$w]) ? $timeouts[$w] : null);
			$message = "Adding job $w";
			if ($timeout) {
				$message .= "; timeout: $timeout";
			}
			$this->log($message, GearmanManager::LOG_LEVEL_WORKER_INFO);
			$thisWorker->addFunction($w, function ($workload) use ($w) {
				static $objects;

				if($objects===null) $objects = array();

				$job_name = $w;

				if ($this->prefix) {
					$func = $this->prefix . $job_name;
				} else {
					$func = $job_name;
				}

				if (empty($objects[$job_name]) && !function_exists($func) && !class_exists($func, false)) {

					if (!isset($this->functions[$job_name])) {
						$this->log("Function $func is not a registered job name");

						return;
					}

					require_once $this->functions[$job_name]["path"];

					if (class_exists($func) && method_exists($func, "run")) {

						$this->log("Creating a $func object", GearmanManager::LOG_LEVEL_WORKER_INFO);
						$objects[$job_name] = new $func();
					} elseif (!function_exists($func)) {

						$this->log("Function $func not found");

						return;
					}
				}

				$this->log(" Starting Job: $job_name", GearmanManager::LOG_LEVEL_WORKER_INFO);

				$this->log(" Workload: $w", GearmanManager::LOG_LEVEL_DEBUG);

				$log = array();

				$this->log(" workload object for $workload", GearmanManager::LOG_LEVEL_DEBUG);

				/**
				 * Run the real function here
				 */
				if (isset($objects[$job_name])) {
					$this->log(" Calling object for $job_name.", GearmanManager::LOG_LEVEL_DEBUG);
					$result = $objects[$job_name]->run($workload, $log);
				} elseif (function_exists($func)) {
					$this->log(" Calling function for $job_name.", GearmanManager::LOG_LEVEL_DEBUG);
					$result = $func($workload, $log);
				} else {
					$this->log(" FAILED to find a function or class for $job_name.", GearmanManager::LOG_LEVEL_INFO);
				}

				if (!empty($log)) {
					foreach ($log as $l) {

						if (!is_scalar($l)) {
							$l = explode("\n", trim(print_r($l, true)));
						} elseif (strlen($l) > 256) {
							$l = substr($l, 0, 256) . "...(truncated)";
						}

						if (is_array($l)) {
							foreach ($l as $ln) {
								$this->log(" $ln", GearmanManager::LOG_LEVEL_WORKER_INFO);
							}
						} else {
							$this->log(" $l", GearmanManager::LOG_LEVEL_WORKER_INFO);
						}
					}
				}

				$result_log = $result;

				if (!is_scalar($result_log)) {
					$result_log = explode("\n", trim(print_r($result_log, true)));
				} elseif (strlen($result_log) > 256) {
					$result_log = substr($result_log, 0, 256) . "...(truncated)";
				}

				if (is_array($result_log)) {
					foreach ($result_log as $ln) {
						$this->log("$ln", GearmanManager::LOG_LEVEL_DEBUG);
					}
				} else {
					$this->log("$result_log", GearmanManager::LOG_LEVEL_DEBUG);
				}

				$this->job_execution_count++;

				return $result;
			}, $timeout);
		}

		$thisWorker->attachCallback(array($this, 'job_start'), \Net\Gearman\Worker::JOB_START);
		$thisWorker->attachCallback(array($this, 'job_complete'), \Net\Gearman\Worker::JOB_COMPLETE);
		$thisWorker->attachCallback(array($this, 'job_fail'), \Net\Gearman\Worker::JOB_FAIL);

		$this->start_time = time();
		$this->job_execution_count++;

		$thisWorker->work(array($this, "monitor"));
		$thisWorker->unregisterAll();
	}

	/**
	 * Monitor call back for worker. Return false to stop worker
	 *
	 * @param   bool $idle If true the worker was idle
	 * @param   int $lastJob The time the last job was run
	 * @return  bool
	 *
	 */
	public function monitor($idle, $lastJob) {

		if ($this->max_run_time > 0 && time() - $this->start_time > $this->max_run_time) {
			$this->log("Been running too long, exiting", GearmanManager::LOG_LEVEL_WORKER_INFO);
			$this->stop_work = true;
		}

		$time = time() - $lastJob;

		$this->log("Worker's last job $time seconds ago", GearmanManager::LOG_LEVEL_CRAZY);

		if (!empty($this->config["max_runs_per_worker"]) && $this->job_execution_count >= $this->config["max_runs_per_worker"]) {
			$this->log("Ran $this->job_execution_count jobs which is over the maximum({$this->config['max_runs_per_worker']}), exiting", GearmanManager::LOG_LEVEL_WORKER_INFO);
			$this->stop_work = true;
		}

		return $this->stop_work;
	}

	/**
	 * Call back for when jobs are started
	 */
	public function job_start($handle, $job, $args) {
		$this->log("($handle) Starting Job: $job", GearmanManager::LOG_LEVEL_WORKER_INFO);
		$this->log("($handle) Workload: " . json_encode($args), GearmanManager::LOG_LEVEL_DEBUG);
		self::$LOG = array();
	}


	/**
	 * Call back for when jobs are completed
	 */
	public function job_complete($handle, $job, $result) {

		$this->log("($handle) Completed Job: $job", GearmanManager::LOG_LEVEL_WORKER_INFO);

		$this->log_result($handle, $result);
	}

	/**
	 * Call back for when jobs fail
	 */
	public function job_fail($handle, $job, $result) {

		$message = "($handle) Failed Job: $job: " . $result->getMessage();

		$this->log($message, GearmanManager::LOG_LEVEL_WORKER_INFO);

		$this->log_result($handle, $result);
	}

	/**
	 * Logs the result of complete/failed jobs
	 *
	 * @param   mixed $result Result returned from worker
	 * @return  void
	 *
	 */
	private function log_result($handle, $result) {

		if (!empty(self::$LOG)) {
			foreach (self::$LOG as $l) {

				if (!is_scalar($l)) {
					$l = explode("\n", trim(print_r($l, true)));
				} elseif (strlen($l) > 256) {
					$l = substr($l, 0, 256) . "...(truncated)";
				}

				if (is_array($l)) {
					$log_message = "";
					foreach ($l as $ln) {
						$log_message .= "($handle) $ln\n";
					}
					$this->log($log_message, GearmanManager::LOG_LEVEL_WORKER_INFO);
				} else {
					$this->log("($handle) $l", GearmanManager::LOG_LEVEL_WORKER_INFO);
				}
			}
		}


		$result_log = $result;

		if (!is_scalar($result_log)) {
			$result_log = explode("\n", trim(print_r($result_log, true)));
		} elseif (strlen($result_log) > 256) {
			$result_log = substr($result_log, 0, 256) . "...(truncated)";
		}

		if (is_array($result_log)) {
			$log_message = "";
			foreach ($result_log as $ln) {
				$log_message .= "($handle) $ln\n";
			}
			$this->log($log_message, GearmanManager::LOG_LEVEL_DEBUG);
		} else {
			$this->log("($handle) $result_log", GearmanManager::LOG_LEVEL_DEBUG);
		}
	}

	/**
	 * Validates the PECL compatible worker files/functions
	 */
	protected function validate_lib_workers() {

		foreach ($this->functions as $func => $props) {
			require_once $props["path"];
			$real_func = $this->prefix . $func;
			if (!function_exists($real_func) &&
				(!class_exists($real_func) || !method_exists($real_func, "run"))
			) {
				$this->log("Function $real_func not found in " . $props["path"]);
				posix_kill($this->pid, SIGUSR2);
				exit();
			}
		}
	}
}

$mgr = new ComposerManager();