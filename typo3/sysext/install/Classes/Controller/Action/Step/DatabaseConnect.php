<?php
namespace TYPO3\CMS\Install\Controller\Action\Step;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2013 Christian Kuhn <lolli@schwarzbu.ch>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Database connect step:
 * - Needs execution if database credentials are not set or fail to connect
 * - Renders fields for database connection fields
 * - Sets database credentials in LocalConfiguration
 * - Loads / unloads ext:dbal and ext:adodb if requested
 * - Loads / unloads ext:doctrine_dbal if requested Note: Added the comment for Doctrine
 */
class DatabaseConnect extends AbstractStepAction {

	/**
	 * Execute database step:
	 * - Load / unload dbal & adodb
	 * - Load / unload ext:doctrine_dbal Note: We load Doctrine here to.
	 * - Set database connect credentials in LocalConfiguration
	 *
	 * @return array<\TYPO3\CMS\Install\Status\StatusInterface>
	 */
	public function execute() {
		$result = array();

		/** @var $configurationManager \TYPO3\CMS\Core\Configuration\ConfigurationManager */
		$configurationManager = $this->objectManager->get('TYPO3\\CMS\\Core\\Configuration\\ConfigurationManager');

		$postValues = $this->postValues['values'];
		// TODO: Why is here an isset at the first line but not at the other checks?
		if (isset($postValues['loadDbal'])) {
			$result[] = $this->executeLoadDbalExtension();
		} elseif (isset($postValues['unloadDbal'])) {
			$result[] = $this->executeUnloadDbalExtension();
		} elseif (isset($postValues['loadDoctrine'])) {
			$result[] = $this->executeLoadDoctrineExtension();
		} elseif (isset($postValues['unloadDoctrine'])) {
			$result[] = $this->executeUnloadDoctrineExtension();
		} elseif (isset($postValues['setDbalDriver'])) {
			$driver = $postValues['setDbalDriver'];
			switch ($driver) {
				case 'mssql':
				case 'odbc_mssql':
					$driverConfig = array(
						'useNameQuote' => TRUE,
						'quoteClob' => FALSE,
					);
					break;
				case 'oci8':
					$driverConfig = array(
						'driverOptions' => array(
							'connectSID' => '',
						),
					);
					break;
			}
			$config = array(
				'_DEFAULT' => array(
					'type' => 'adodb',
					'config' => array(
						'driver' => $driver,
					)
				)
			);
			if (isset($driverConfig)) {
				$config['_DEFAULT']['config'] = array_merge($config['_DEFAULT']['config'], $driverConfig);
			}
			$configurationManager->setLocalConfigurationValueByPath('EXTCONF/dbal/handlerCfg', $config);
		} elseif (isset($postValues['setDoctrineDriver'])) {
			$localConfigurationPathValuePairs['DB/driver'] = $postValues['setDoctrineDriver'];
			$configurationManager->setLocalConfigurationValuesByPathValuePairs($localConfigurationPathValuePairs);
		} else {
			$localConfigurationPathValuePairs = array();

			if ($this->isDbalEnabled()) {
				$config = $configurationManager->getConfigurationValueByPath('EXTCONF/dbal/handlerCfg');
				$driver = $config['_DEFAULT']['config']['driver'];
				if ($driver === 'oci8') {
					$config['_DEFAULT']['config']['driverOptions']['connectSID'] = ($postValues['type'] === 'sid');
					$localConfigurationPathValuePairs['EXTCONF/dbal/handlerCfg'] = $config;
				}
			}

			if (!empty($postValues['username'])) {
				$value = $postValues['username'];
				if (strlen($value) <= 50) {
					$localConfigurationPathValuePairs['DB/username'] = $value;
				} else {
					/** @var $errorStatus \TYPO3\CMS\Install\Status\ErrorStatus */
					$errorStatus = $this->objectManager->get('TYPO3\\CMS\\Install\\Status\\ErrorStatus');
					$errorStatus->setTitle('Database username not valid');
					$errorStatus->setMessage('Given username must be shorter than fifty characters.');
					$result[] = $errorStatus;
				}
			}

			if (!empty($postValues['password'])) {
				$value = $postValues['password'];
				if (strlen($value) <= 50) {
					$localConfigurationPathValuePairs['DB/password'] = $value;
				} else {
					/** @var $errorStatus \TYPO3\CMS\Install\Status\ErrorStatus */
					$errorStatus = $this->objectManager->get('TYPO3\\CMS\\Install\\Status\\ErrorStatus');
					$errorStatus->setTitle('Database password not valid');
					$errorStatus->setMessage('Given password must be shorter than fifty characters.');
					$result[] = $errorStatus;
				}
			}

			if (!empty($postValues['host'])) {
				$value = $postValues['host'];
				if (preg_match('/^[a-zA-Z0-9_\\.-]+(:.+)?$/', $value) && strlen($value) <= 50) {
					$localConfigurationPathValuePairs['DB/host'] = $value;
				} else {
					/** @var $errorStatus \TYPO3\CMS\Install\Status\ErrorStatus */
					$errorStatus = $this->objectManager->get('TYPO3\\CMS\\Install\\Status\\ErrorStatus');
					$errorStatus->setTitle('Database host not valid');
					$errorStatus->setMessage('Given host is not alphanumeric (a-z, A-Z, 0-9 or _-.:) or longer than fifty characters.');
					$result[] = $errorStatus;
				}
			}

			if (!empty($postValues['port']) && $postValues['host'] !== 'localhost') {
				$value = $postValues['port'];
				if (preg_match('/^[0-9]+(:.+)?$/', $value) && $value > 0 && $value <= 65535) {
					$localConfigurationPathValuePairs['DB/port'] = (int)$value;
				} else {
					/** @var $errorStatus \TYPO3\CMS\Install\Status\ErrorStatus */
					$errorStatus = $this->objectManager->get('TYPO3\\CMS\\Install\\Status\\ErrorStatus');
					$errorStatus->setTitle('Database port not valid');
					$errorStatus->setMessage('Given port is not numeric or within range 1 to 65535.');
					$result[] = $errorStatus;
				}
			}

			if (!empty($postValues['socket']) && $postValues['socket'] !== '') {
				if (@file_exists($postValues['socket'])) {
					$localConfigurationPathValuePairs['DB/socket'] = $postValues['socket'];
				} else {
					/** @var $errorStatus \TYPO3\CMS\Install\Status\ErrorStatus */
					$errorStatus = $this->objectManager->get('TYPO3\\CMS\\Install\\Status\\ErrorStatus');
					$errorStatus->setTitle('Socket does not exist');
					$errorStatus->setMessage('Given socket location does not exist on server.');
					$result[] = $errorStatus;
				}
			}

			if (!empty($postValues['database'])) {
				$value = $postValues['database'];
				if (strlen($value) <= 50) {
					$localConfigurationPathValuePairs['DB/database'] = $value;
				} else {
					/** @var $errorStatus \TYPO3\CMS\Install\Status\ErrorStatus */
					$errorStatus = $this->objectManager->get('TYPO3\\CMS\\Install\\Status\\ErrorStatus');
					$errorStatus->setTitle('Database name not valid');
					$errorStatus->setMessage('Given database name must be shorter than fifty characters.');
					$result[] = $errorStatus;
				}
			}

			if (!empty($postValues['sslmode'])) {
				$value = $postValues['sslmode'];
				$localConfigurationPathValuePairs['DB/sslmode'] = $value;
			}

			if (!empty($localConfigurationPathValuePairs)) {
				$configurationManager->setLocalConfigurationValuesByPathValuePairs($localConfigurationPathValuePairs);

				// After setting new credentials, test again and create an error message if connect is not successful
				// @TODO: This could be simplified, if isConnectSuccessful could be released from TYPO3_CONF_VARS
				// and fed with connect values directly in order to obsolete the bootstrap reload.
				\TYPO3\CMS\Core\Core\Bootstrap::getInstance()
					->populateLocalConfiguration()
					->disableCoreAndClassesCache();
				if ($this->isDbalEnabled()) {
					require(ExtensionManagementUtility::extPath('dbal') . 'ext_localconf.php');
					\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Cache\\CacheManager')->setCacheConfigurations($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']);
				}
				if (!$this->isConnectSuccessful()) {
					/** @var $errorStatus \TYPO3\CMS\Install\Status\ErrorStatus */
					$errorStatus = $this->objectManager->get('TYPO3\\CMS\\Install\\Status\\ErrorStatus');
					$errorStatus->setTitle('Database connect not successful');
					$errorStatus->setMessage('Connecting the database with given settings failed. Please check.');
					$result[] = $errorStatus;
				}
			}
		}

		return $result;
	}

	/**
	 * Step needs to be executed if database connection is not successful.
	 *
	 * @throws \TYPO3\CMS\Install\Controller\Exception\RedirectException
	 * @return boolean
	 */
	public function needsExecution() {
		if ($this->isConnectSuccessful() && $this->isConfigurationComplete()) {
			return FALSE;
		}
		if (!$this->isHostConfigured() && !$this->isDbalEnabled() && !$this->isDoctrineEnabled()) {
			$this->useDefaultValuesForNotConfiguredOptions();
			throw new \TYPO3\CMS\Install\Controller\Exception\RedirectException(
				'Wrote default settings to LocalConfiguration.php, redirect needed',
				1377611168
			);
		}
		return TRUE;
	}

	/**
	 * Executes the step
	 *
	 * @return string Rendered content
	 */
	protected function executeAction() {
		$isDbalEnabled = $this->isDbalEnabled();
		$isDoctrineEnabled = $this->isDoctrineEnabled();
		$this->view
			->assign('isDbalEnabled', $isDbalEnabled)
			->assign('isDoctrineEnabled', $isDoctrineEnabled)
			->assign('username', $this->getConfiguredUsername())
			->assign('password', $this->getConfiguredPassword())
			->assign('host', $this->getConfiguredHost())
			->assign('port', $this->getConfiguredOrDefaultPort())
			->assign('database', $GLOBALS['TYPO3_CONF_VARS']['DB']['database'] ?: '')
			->assign('socket', $GLOBALS['TYPO3_CONF_VARS']['DB']['socket'] ?: '')
			->assign('charset', $this->getConfiguredCharset());

		if ($isDbalEnabled) {
			$this->view->assign('selectedDbalDriver', $this->getSelectedDbalDriver());
			$this->view->assign('dbalDrivers', $this->getAvailableDbalDrivers());
			$this->setDbalInputFieldsToRender();
		} elseif ($isDoctrineEnabled) {
			$this->view->assign('selectedDoctrineDriver', $this->getSelectedDoctrineDriver());
			$this->view->assign('doctrineDrivers', $this->getAvailableDoctrineDrivers());
			$this->setDoctrineInputFieldsToRender();
		}
		else {
			$this->view
				->assign('renderConnectDetailsUsername', TRUE)
				->assign('renderConnectDetailsPassword', TRUE)
				->assign('renderConnectDetailsHost', TRUE)
				->assign('renderConnectDetailsPort', TRUE)
				->assign('renderConnectDetailsSocket', TRUE)
				->assign('renderConnectDetailsCharset', TRUE);
		}
		$this->assignSteps();

		return $this->view->render();
	}

	/**
	 * Render connect port and label
	 *
	 * @return integer Configured or default port
	 */
	protected function getConfiguredOrDefaultPort() {
		$configuredPort = (int)$this->getConfiguredPort();
		if (!$configuredPort) {
			if ($this->isDbalEnabled()) {
				$driver = $this->getSelectedDbalDriver();
				switch ($driver) {
					case 'postgres':
						$port = 5432;
						break;
					case 'mssql':
					case 'odbc_mssql':
						$port = 1433;
						break;
					case 'oci8':
						$port = 1521;
						break;
					default:
						$port = 3306;
				}
			} elseif ($this->isDoctrineEnabled()) {
				$driver = $this->getSelectedDoctrineDriver();
				switch ($driver) {
					case 'pdo_mysql':
						$port = 3306;
						break;
					case 'pdo_pgsql':
						$port = 5432;
						break;
					case 'pdo_sqlsrv':
						$port = 1433;
						break;
					case 'oci8':
						$port = 1521;
						break;
					default:
						$port = 3306;
				}
			} else {
				$port = 3306;
			}
		} else {
			$port = $configuredPort;
		}
		return $port;
	}

	/**
	 * Test connection with given credentials
	 *
	 * @return boolean TRUE if connect was successful
	 */
	protected function isConnectSuccessful() {
		if ($this->isDoctrineEnabled()) {
			/** @var $databaseConnection \Konafets\DoctrineDbal\Persistence\Legacy\DatabaseConnection */
			$databaseConnection = $this->objectManager->get('Konafets\\DoctrineDbal\\Persistence\\Legacy\\DatabaseConnection');
		} else {
			/** @var $databaseConnection \TYPO3\CMS\Core\Database\DatabaseConnection */
			$databaseConnection = $this->objectManager->get('TYPO3\\CMS\\Core\\Database\\DatabaseConnection');
		}

		if ($this->isDbalEnabled()) {
			// Set additional connect information based on dbal driver. postgres for example needs
			// database name already for connect.
			if (isset($GLOBALS['TYPO3_CONF_VARS']['DB']['database'])) {
				$databaseConnection->setDatabaseName($GLOBALS['TYPO3_CONF_VARS']['DB']['database']);
			}
		}

		if ($this->isDoctrineEnabled()) {
			// Set additional connect information based on dbal driver. postgres for example needs
			// database name already for connect.
			if (!empty($GLOBALS['TYPO3_CONF_VARS']['DB']['database'])) {
				$databaseConnection->setDatabaseName($GLOBALS['TYPO3_CONF_VARS']['DB']['database']);
			}
			if (isset($GLOBALS['TYPO3_CONF_VARS']['DB']['driver'])) {
				$databaseConnection->setDatabaseDriver($GLOBALS['TYPO3_CONF_VARS']['DB']['driver']);
			}
		}

		$databaseConnection->setDatabaseUsername($this->getConfiguredUsername());
		$databaseConnection->setDatabasePassword($this->getConfiguredPassword());

		if (empty($GLOBALS['TYPO3_CONF_VARS']['DB']['socket'])) {
			$databaseConnection->setDatabaseHost($this->getConfiguredHost());
			$databaseConnection->setDatabasePort($this->getConfiguredPort());
		} else {
			$databaseConnection->setDatabaseSocket($this->getConfiguredSocket());
		}

		$databaseConnection->setDatabaseCharset($this->getConfiguredCharset());

		$result = FALSE;
		if ($this->isDoctrineEnabled() && $this->getConfiguredUsername()) {
			/** @var $configurationManager \TYPO3\CMS\Core\Configuration\ConfigurationManager */
			$configurationManager = $this->objectManager->get('TYPO3\\CMS\\Core\\Configuration\\ConfigurationManager');
			$isInitialInstallationInProgress = $configurationManager->getConfigurationValueByPath('SYS/isInitialInstallationInProgress');

			$databaseConnection->connectDatabase($isInitialInstallationInProgress);
		}

		if (@$databaseConnection->isConnected()) {
			$result = TRUE;
		}

		return $result;
	}

	/**
	 * Check LocalConfiguration.php for required database settings:
	 * - 'host' is mandatory and must not be empty
	 * - 'port' OR 'socket' is mandatory, but may be empty
	 *
	 * @return boolean TRUE if host is set
	 */
	protected function isHostConfigured() {
		$hostConfigured = TRUE;
		if (empty($GLOBALS['TYPO3_CONF_VARS']['DB']['host'])) {
			$hostConfigured = FALSE;
		}
		if (
			!isset($GLOBALS['TYPO3_CONF_VARS']['DB']['port'])
			&& !isset($GLOBALS['TYPO3_CONF_VARS']['DB']['socket'])
		) {
			$hostConfigured = FALSE;
		}
		return $hostConfigured;
	}

	/**
	 * Check LocalConfiguration.php for required database settings:
	 * - 'host' is mandatory and must not be empty
	 * - 'port' OR 'socket' is mandatory, but may be empty
	 * - 'username' and 'password' are mandatory, but may be empty
	 *
	 * @return boolean TRUE if required settings are present
	 */
	protected function isConfigurationComplete() {
		$configurationComplete = $this->isHostConfigured();
		if (!isset($GLOBALS['TYPO3_CONF_VARS']['DB']['username'])) {
			$configurationComplete = FALSE;
		}
		if (!isset($GLOBALS['TYPO3_CONF_VARS']['DB']['password'])) {
			$configurationComplete = FALSE;
		}
		return $configurationComplete;
	}

	/**
	 * Write DB settings to LocalConfiguration.php, using default values.
	 * With the switch from mysql to mysqli in 6.1, some mandatory settings were
	 * added. This method tries to add those settings in case of an upgrade, and
	 * pre-configures settings in case of a "new" install process.
	 *
	 * There are two different connection types:
	 * - Unix domain socket. This may be available if mysql is running on localhost
	 * - TCP/IP connection to some mysql system somewhere.
	 *
	 * Unix domain socket connections are quicker than TCP/IP, so it is
	 * tested if a unix domain socket connection to localhost is successful. If not,
	 * a default configuration for TCP/IP is used.
	 *
	 * @return void
	 */
	protected function useDefaultValuesForNotConfiguredOptions() {
		$localConfigurationPathValuePairs = array();

		$localConfigurationPathValuePairs['DB/host'] = $this->getConfiguredHost();

		// If host is "local" either by upgrading or by first install, we try a socket
		// connection first and use TCP/IP as fallback
		if ($localConfigurationPathValuePairs['DB/host'] === 'localhost'
			|| \TYPO3\CMS\Core\Utility\GeneralUtility::cmpIP($localConfigurationPathValuePairs['DB/host'], '127.*.*.*')
			|| strlen($localConfigurationPathValuePairs['DB/host']) === 0
		) {
			if ($this->isConnectionWithUnixDomainSocketPossible()) {
				$localConfigurationPathValuePairs['DB/host'] = 'localhost';
				$localConfigurationPathValuePairs['DB/socket'] = $this->getConfiguredSocket();
			} else {
				if (!\TYPO3\CMS\Core\Utility\GeneralUtility::isFirstPartOfStr($localConfigurationPathValuePairs['DB/host'], '127.')) {
					$localConfigurationPathValuePairs['DB/host'] = '127.0.0.1';
				}
			}
		}

		if (!isset($localConfigurationPathValuePairs['DB/socket'])) {
			// Make sure a default port is set if not configured yet
			// This is independent from any host configuration
			$port = $this->getConfiguredPort();
			if ($port > 0) {
				$localConfigurationPathValuePairs['DB/port'] = $port;
			} else {
				$localConfigurationPathValuePairs['DB/port'] = $this->getConfiguredOrDefaultPort();
			}
		}

		/** @var \TYPO3\CMS\Core\Configuration\ConfigurationManager $configurationManager */
		$configurationManager = $this->objectManager->get('TYPO3\\CMS\\Core\\Configuration\\ConfigurationManager');
		$configurationManager->setLocalConfigurationValuesByPathValuePairs($localConfigurationPathValuePairs);
	}

	/**
	 * Test if a unix domain socket can be opened. This does not
	 * authenticate but only tests if a connect is successful.
	 *
	 * @return boolean TRUE on success
	 */
	protected function isConnectionWithUnixDomainSocketPossible() {
		$result = FALSE;
		// Use configured socket
		$socket = $this->getConfiguredSocket();
		if (!strlen($socket) > 0) {
			// If no configured socket, use default php socket
			$defaultSocket = ini_get('mysqli.default_socket');
			if (strlen($defaultSocket) > 0) {
				$socket = $defaultSocket;
			}
		}
		if (strlen($socket) > 0) {
			$socketOpenResult = @fsockopen('unix://' . $socket);
			if ($socketOpenResult) {
				fclose($socketOpenResult);
				$result = TRUE;
			}
		}
		return $result;
	}

	/**
	 * Render fields required for successful connect based on dbal driver selection.
	 * Hint: There is a code duplication in handle() and this method. This
	 * is done by intention to keep this code area easy to maintain and understand.
	 *
	 * @return void
	 */
	protected function setDbalInputFieldsToRender() {
		$driver = $this->getSelectedDbalDriver();
		switch($driver) {
			case 'mssql':
			case 'odbc_mssql':
			case 'postgres':
				$this->view
					->assign('renderConnectDetailsUsername', TRUE)
					->assign('renderConnectDetailsPassword', TRUE)
					->assign('renderConnectDetailsHost', TRUE)
					->assign('renderConnectDetailsPort', TRUE)
					->assign('renderConnectDetailsDatabase', TRUE);
				break;
			case 'oci8':
				$this->view
					->assign('renderConnectDetailsUsername', TRUE)
					->assign('renderConnectDetailsPassword', TRUE)
					->assign('renderConnectDetailsHost', TRUE)
					->assign('renderConnectDetailsPort', TRUE)
					->assign('renderConnectDetailsDatabase', TRUE)
					->assign('renderConnectDetailsOracleSidConnect', TRUE);
				$type = isset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['dbal']['handlerCfg']['_DEFAULT']['config']['driverOptions']['connectSID'])
					? $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['dbal']['handlerCfg']['_DEFAULT']['config']['driverOptions']['connectSID']
					: '';
				if ($type === TRUE) {
					$this->view->assign('oracleSidSelected', TRUE);
				}
				break;
		}
	}

	/**
	 * Render fields required for successful connect based on dbal driver selection.
	 * Hint: There is a code duplication in handle() and this method. This
	 * is done by intention to keep this code area easy to maintain and understand.
	 *
	 * @see http://docs.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html
	 * @return void
	 */
	protected function setDoctrineInputFieldsToRender() {
		// TODO: Alter here the fields which are needed for the drivers
		$driver = $this->getSelectedDoctrineDriver();
		switch($driver) {
			case 'pdo_mysql':
				$this->view
					->assign('renderConnectDetailsUsername', TRUE)
					->assign('renderConnectDetailsPassword', TRUE)
					->assign('renderConnectDetailsHost', TRUE)
					->assign('renderConnectDetailsSocket', TRUE)
					->assign('renderConnectDetailsPort', TRUE)
					->assign('renderConnectDetailsCharset', TRUE);
				break;
			case 'pdo_sqlite':
				$this->view
					->assign('renderConnectDetailsUsername', TRUE)
					->assign('renderConnectDetailsPassword', TRUE)
					->assign('renderConnectDetailsPath', TRUE)
					->assign('renderConnectDetailsMemory', TRUE);
				break;
			case 'pdo_pgsql':
				$this->view
					->assign('renderConnectDetailsUsername', TRUE)
					->assign('renderConnectDetailsPassword', TRUE)
					->assign('renderConnectDetailsHost', TRUE)
					->assign('renderConnectDetailsSocket', TRUE)
					->assign('renderConnectDetailsPort', TRUE)
					->assign('renderConnectDetailsSslMode', TRUE)
					->assign('renderConnectDetailsCharset', TRUE);
				break;
			case 'oci8':
				$this->view
					->assign('renderConnectDetailsUsername', TRUE)
					->assign('renderConnectDetailsPassword', TRUE)
					->assign('renderConnectDetailsHost', TRUE)
					->assign('renderConnectDetailsPort', TRUE)
					->assign('renderConnectDetailsCharset', TRUE);
				break;
			case 'pdo_sqlsrv':
				$this->view
					->assign('renderConnectDetailsUsername', TRUE)
					->assign('renderConnectDetailsPassword', TRUE)
					->assign('renderConnectDetailsHost', TRUE)
					->assign('renderConnectDetailsPort', TRUE)
					->assign('renderConnectDetailsDatabase', TRUE);
				break;
			default:
		}
	}

	/**
	 * Returns a list of database drivers that are available on current server.
	 *
	 * @return array
	 */
	protected function getAvailableDbalDrivers() {
		$supportedDrivers = $this->getSupportedDbalDrivers();
		$availableDrivers = array();
		$selectedDbalDriver = $this->getSelectedDbalDriver();
		foreach ($supportedDrivers as $abstractionLayer => $drivers) {
			foreach ($drivers as $driver => $info) {
				if (isset($info['combine']) && $info['combine'] === 'OR') {
					$isAvailable = FALSE;
				} else {
					$isAvailable = TRUE;
				}
				// Loop through each PHP module dependency to ensure it is loaded
				foreach ($info['extensions'] as $extension) {
					if (isset($info['combine']) && $info['combine'] === 'OR') {
						$isAvailable |= extension_loaded($extension);
					} else {
						$isAvailable &= extension_loaded($extension);
					}
				}
				if ($isAvailable) {
					if (!isset($availableDrivers[$abstractionLayer])) {
						$availableDrivers[$abstractionLayer] = array();
					}
					$availableDrivers[$abstractionLayer][$driver] = array();
					$availableDrivers[$abstractionLayer][$driver]['driver'] = $driver;
					$availableDrivers[$abstractionLayer][$driver]['label'] = $info['label'];
					$availableDrivers[$abstractionLayer][$driver]['selected'] = FALSE;
					if ($selectedDbalDriver === $driver) {
						$availableDrivers[$abstractionLayer][$driver]['selected'] = TRUE;
					}
				}
			}
		}
		return $availableDrivers;
	}

	/**
	 * Returns a list of database drivers that are available on current server.
	 *
	 * @return array
	 */
	protected function getAvailableDoctrineDrivers() {
		$supportedDrivers = $this->getSupportedDoctrineDrivers();
		$availableDrivers = array('none' => array('driver' => 'none', 'label' => 'Select a driver', 'selected' => TRUE));
		$selectedDoctrineDriver = $this->getSelectedDoctrineDriver();
		foreach ($supportedDrivers as $driver => $info) {
			if (isset($info['combine']) && $info['combine'] === 'OR') {
				$isAvailable = FALSE;
			} else {
				$isAvailable = TRUE;
			}
			// Loop through each PHP module dependency to ensure it is loaded
			foreach ($info['extensions'] as $extension) {
				if (isset($info['combine']) && $info['combine'] === 'OR') {
					$isAvailable |= extension_loaded($extension);
				} else {
					$isAvailable &= extension_loaded($extension);
				}
			}
			if ($isAvailable) {
				$availableDrivers[$driver] = array();
				$availableDrivers[$driver]['driver'] = $driver;
				$availableDrivers[$driver]['label'] = $info['label'];
				$availableDrivers[$driver]['selected'] = FALSE;
				if ($selectedDoctrineDriver === $driver) {
					$availableDrivers[$driver]['selected'] = TRUE;
				}
			}
		}

		return $availableDrivers;
	}

	/**
	 * Returns a list of DBAL supported database drivers, with a
	 * user-friendly name and any PHP module dependency.
	 *
	 * @return array
	 */
	protected function getSupportedDbalDrivers() {
		$supportedDrivers = array(
			'Native' => array(
				'mssql' => array(
					'label' => 'Microsoft SQL Server',
					'extensions' => array('mssql')
				),
				'oci8' => array(
					'label' => 'Oracle OCI8',
					'extensions' => array('oci8')
				),
				'postgres' => array(
					'label' => 'PostgreSQL',
					'extensions' => array('pgsql')
				)
			),
			'ODBC' => array(
				'odbc_mssql' => array(
					'label' => 'Microsoft SQL Server',
					'extensions' => array('odbc', 'mssql')
				)
			)
		);
		return $supportedDrivers;
	}

	/**
	 * Returns a list of Doctrine DBAL supported database drivers, with a
	 * user-friendly name and any PHP module dependency.
	 *
	 * @return array
	 */
	protected function getSupportedDoctrineDrivers() {
		$supportedDrivers = array(
			'pdo_mysql' => array(
				'label' => 'MySQL PDO driver',
				'extensions' => array('pdo_mysql')
			),
			'pdo_sqlite' => array(
				'label' => 'SQLite PDO driver',
				'extensions' => array('pdo_sqlite')
			),
			'pdo_pgsql' => array(
				'label' => 'PostgreSQL PDO driver',
				'extensions' => array('pdo_pgsql')
			),
			'sqlsrv' => array(
				'label' => 'Microsoft SQL Server PDO driver',
				'extensions' => array('pdo_sqlsrv')
			),
			'oci8' => array(
				'label' => 'Oracle OCI8 PDO driver',
				'extensions' => array('oci8')
			)
		);

		return $supportedDrivers;
	}

	/**
	 * Get selected dbal driver if any
	 *
	 * @return string Dbal driver or empty string if not yet selected
	 */
	protected function getSelectedDbalDriver() {
		if (isset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['dbal']['handlerCfg']['_DEFAULT']['config']['driver'])) {
			return $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['dbal']['handlerCfg']['_DEFAULT']['config']['driver'];
		}
		return '';
	}

	/**
	 * Get selected dbal driver if any
	 * TODO: Add stuff for Doctrine here
	 * @return string Dbal driver or empty string if not yet selected
	 */
	protected function getSelectedDoctrineDriver() {
		$result = 'none';

		if (isset($GLOBALS['TYPO3_CONF_VARS']['DB']['driver'])) {
			$result = $GLOBALS['TYPO3_CONF_VARS']['DB']['driver'];
		}

		return $result;
	}



	/**
	 * Adds dbal and adodb to list of loaded extensions
	 *
	 * @return \TYPO3\CMS\Install\Status\StatusInterface
	 */
	protected function executeLoadDbalExtension() {
		if (!ExtensionManagementUtility::isLoaded('adodb')) {
			ExtensionManagementUtility::loadExtension('adodb');
		}
		if (!ExtensionManagementUtility::isLoaded('dbal')) {
			ExtensionManagementUtility::loadExtension('dbal');
		}
		/** @var $errorStatus \TYPO3\CMS\Install\Status\WarningStatus */
		$warningStatus = $this->objectManager->get('TYPO3\\CMS\\Install\\Status\\WarningStatus');
		$warningStatus->setTitle('Loaded database abstraction layer');
		return $warningStatus;
	}

	/**
	 * Remove dbal and adodb from list of loaded extensions
	 *
	 * @return \TYPO3\CMS\Install\Status\StatusInterface
	 */
	protected function executeUnloadDbalExtension() {
		if (ExtensionManagementUtility::isLoaded('adodb')) {
			ExtensionManagementUtility::unloadExtension('adodb');
		}
		if (ExtensionManagementUtility::isLoaded('dbal')) {
			ExtensionManagementUtility::unloadExtension('dbal');
		}
		// @TODO: Remove configuration from TYPO3_CONF_VARS['EXTCONF']['dbal']
		/** @var $errorStatus \TYPO3\CMS\Install\Status\WarningStatus */
		$warningStatus = $this->objectManager->get('TYPO3\\CMS\\Install\\Status\\WarningStatus');
		$warningStatus->setTitle('Removed database abstraction layer');
		return $warningStatus;
	}

	/**
	 * Adds Doctrine DBAL to list of loaded extensions
	 * @return \TYPO3\CMS\Install\Status\StatusInterface
	 */
	protected function executeLoadDoctrineExtension() {
		if (!\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('doctrine_dbal')) {
			\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::loadExtension('doctrine_dbal');
		}
		/** @var $errorStatus \TYPO3\CMS\Install\Status\WarningStatus */
		$warningStatus = $this->objectManager->get('TYPO3\\CMS\\Install\\Status\\WarningStatus');
		$warningStatus->setTitle('Loaded Doctrine DBAL');

		return $warningStatus;
	}

	/**
	 * Remove Doctrine DBAL from list of loaded extensions
	 * @return \TYPO3\CMS\Install\Status\StatusInterface
	 */
	protected function executeUnloadDoctrineExtension() {
		if (\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('doctrine_dbal')) {
			\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::unloadExtension('doctrine_dbal');
		}
		// @TODO: Remove configuration from TYPO3_CONF_VARS['EXTCONF']['dbal']
		/** @var $errorStatus \TYPO3\CMS\Install\Status\WarningStatus */
		$warningStatus = $this->objectManager->get('TYPO3\\CMS\\Install\\Status\\WarningStatus');
		$warningStatus->setTitle('Removed Doctrine DBAL');

		return $warningStatus;
	}

	/**
	 * Returns configured username, if set
	 *
	 * @return string
	 */
	protected function getConfiguredUsername() {
		$username = isset($GLOBALS['TYPO3_CONF_VARS']['DB']['username']) ? $GLOBALS['TYPO3_CONF_VARS']['DB']['username'] : '';

		return $username;
	}

	/**
	 * Returns configured password, if set
	 *
	 * @return string
	 */
	protected function getConfiguredPassword() {
		$password = isset($GLOBALS['TYPO3_CONF_VARS']['DB']['password']) ? $GLOBALS['TYPO3_CONF_VARS']['DB']['password'] : '';

		return $password;
	}

	/**
	 * Returns configured host with port split off if given
	 *
	 * @return string
	 */
	protected function getConfiguredHost() {
		$host = isset($GLOBALS['TYPO3_CONF_VARS']['DB']['host']) ? $GLOBALS['TYPO3_CONF_VARS']['DB']['host'] : '';
		$port = isset($GLOBALS['TYPO3_CONF_VARS']['DB']['port']) ? $GLOBALS['TYPO3_CONF_VARS']['DB']['port'] : '';
		if (strlen($port) < 1 && substr_count($host, ':') === 1) {
			list($host) = explode(':', $host);
		}

		return $host;
	}

	/**
	 * Returns configured port. Gets port from host value if port is not yet set.
	 *
	 * @return integer
	 */
	protected function getConfiguredPort() {
		$host = isset($GLOBALS['TYPO3_CONF_VARS']['DB']['host']) ? $GLOBALS['TYPO3_CONF_VARS']['DB']['host'] : '';
		$port = isset($GLOBALS['TYPO3_CONF_VARS']['DB']['port']) ? $GLOBALS['TYPO3_CONF_VARS']['DB']['port'] : '';
		if (strlen($port) === 0 && substr_count($host, ':') === 1) {
			$hostPortArray = explode(':', $host);
			$port = $hostPortArray[1];
		}

		return (int)$port;
	}

	/**
	 * Returns configured socket, if set
	 *
	 * @return string|NULL
	 */
	protected function getConfiguredSocket() {
		$socket = isset($GLOBALS['TYPO3_CONF_VARS']['DB']['socket']) ? $GLOBALS['TYPO3_CONF_VARS']['DB']['socket'] : '';

		return $socket;
	}

	/**
	 * Returns configured charset, if set
	 *
	 * @return string
	 */
	protected function getConfiguredCharset() {
		$charset = isset($GLOBALS['TYPO3_CONF_VARS']['DB']['charset']) ? $GLOBALS['TYPO3_CONF_VARS']['DB']['charset'] : 'utf8';

		return $charset;
	}

	/**
	 * Returns configured driver, if set
	 *
	 * @return string
	 */
	protected function getConfiguredDriver() {
		$driver = isset($GLOBALS['TYPO3_CONF_VARS']['DB']['driver']) ? $GLOBALS['TYPO3_CONF_VARS']['DB']['driver'] : 'pdo_mysql';

		return $driver;
	}
}
