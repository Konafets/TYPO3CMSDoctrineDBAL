<?php

namespace TYPO3\CMS\Core\Cache\Schema;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2014 Stefano Kowalke <blueduck@gmx.net>
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
 *  A copy is found in the textfile GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
 
/**
 * Class Typo3DatabaseBackendCacheSchema
 * 
 * @package TYPO3\CMS\Core\Cache\Schema
 * @author  Stefano Kowalke <blueduck@gmx.net>
 */
class Typo3DatabaseBackendCacheSchema {

	protected $tableName = '';

	protected $indexCache = '';

	public function __construct($tableName) {
		$this->tableName = $tableName;
		$this->indexCache = $tableName . '_cache_id';
	}

	public function getCacheSchemaFromTemplate() {
		// ###CACHE_TABLE###
		$schema = new \Doctrine\DBAL\Schema\Schema();

		$cacheTable = $schema->createTable($this->tableName);
		$cacheTable->addColumn('id', 'integer', array('autoincrement' => TRUE, 'unsigned' => TRUE, 'notnull' => TRUE));
		$cacheTable->addColumn('identifier', 'string', array('length' => 250, 'default' => '', 'notnull' => TRUE));
		$cacheTable->addColumn('expires', 'integer', array('unsigned' => TRUE, 'default' => '0', 'notnull' => TRUE));
		$cacheTable->addColumn('content', 'blob', array('length' => 16777215));
		$cacheTable->setPrimaryKey(array('id'));
		$cacheTable->addIndex(array('identifier', 'expires'), $this->indexCache);

		return $schema;
	}
}

