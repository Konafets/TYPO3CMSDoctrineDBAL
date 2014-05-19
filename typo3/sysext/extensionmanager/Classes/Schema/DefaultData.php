<?php
namespace TYPO3\CMS\Extensionmanager\Schema;

use Konafets\DoctrineDbal\Persistence\Legacy\DatabaseConnection;
use Konafets\DoctrineDbal\Exception\InvalidArgumentException;

class DefaultData {
	/**
	 * @var \Konafets\DoctrineDbal\Persistence\Doctrine\DatabaseConnection
	 */
	protected $connection;

	/**
	 * @param $connection \Konafets\DoctrineDbal\Persistence\Legacy\DatabaseConnection
	 *
	 * @throws \Konafets\DoctrineDbal\Exception\InvalidArgumentException
	 */
	public function __construct($connection) {
		if (!$connection instanceof DatabaseConnection) {
			throw new InvalidArgumentException('Constructor must be called with type \TYPO3\DoctrineDbal\Persistence\Legacy\DatabaseConnection');
		}

		$this->connection = $connection;
	}

	/**
	 * @return int
	 */
	public function insertDefaultData() {
		return $this->connection->exec_INSERTquery(
				'tx_extensionmanager_domain_model_repository',
				array(
					'uid' => 1,
					'pid' => 0,
					'title' => 'TYPO3.org Main Repository',
					'description' => 'Main repository on typo3.org. This repository has some mirrors configured which are available with the mirror url.',
					'wsdl_url' => 'http://typo3.org/wsdl/tx_ter_wsdl.php',
					'mirror_list_url' => 'http://repositories.typo3.org/mirrors.xml.gz',
					'last_update' => 1346191200,
					'extension_count' => 0
				)
		);
	}
}

