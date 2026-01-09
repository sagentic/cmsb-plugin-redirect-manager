<?php

/**
 * Redirect Manager - Redirects Table Schema
 *
 * Note: This schema file is provided for reference but the table is
 * created directly via SQL in the plugin for simplicity.
 */
return [
	'menuName'             => 'Redirect Manager Redirects',
	'_tableName'           => '_redirectmanager_redirects',
	'_primaryKey'          => 'num',
	'menuType'             => 'multi',
	'listPageFields'       => 'sourceUrl, destinationUrl, redirectType, isActive, hitCount',
	'listPageOrder'        => 'dragSortOrder ASC',
	'listPageSearchFields' => '_all_',
	'_filenameFields'      => 'num',
	'_disableView'         => 1,
	'menuHidden'           => 1,
	'menuOrder'            => 9999999999,

	'num' => [
		'type'          => 'none',
		'label'         => 'Record Number',
		'isSystemField' => 1,
	],

	'createdDate' => [
		'type'          => 'none',
		'label'         => 'Created Date',
		'isSystemField' => 1,
	],

	'createdByUserNum' => [
		'type'          => 'none',
		'label'         => 'Created By',
		'isSystemField' => 1,
	],

	'updatedDate' => [
		'type'          => 'none',
		'label'         => 'Updated Date',
		'isSystemField' => 1,
	],

	'updatedByUserNum' => [
		'type'          => 'none',
		'label'         => 'Updated By',
		'isSystemField' => 1,
	],

	'sourceUrl' => [
		'label'            => 'Source URL',
		'type'             => 'textfield',
		'customColumnType' => 'VARCHAR(500) NOT NULL',
		'indexed'          => 1,
	],

	'destinationUrl' => [
		'label'            => 'Destination URL',
		'type'             => 'textfield',
		'customColumnType' => 'VARCHAR(500) NOT NULL',
	],

	'redirectType' => [
		'label'            => 'Redirect Type',
		'type'             => 'list',
		'listType'         => 'pulldown',
		'optionsType'      => 'text',
		'optionsText'      => "301|301 - Permanent\n302|302 - Temporary\n307|307 - Temporary (Strict)\n308|308 - Permanent (Strict)",
		'defaultValue'     => '301',
		'customColumnType' => "ENUM('301','302','307','308') DEFAULT '301'",
	],

	'isPattern' => [
		'label'            => 'Is Pattern',
		'type'             => 'checkbox',
		'defaultValue'     => '0',
		'customColumnType' => 'TINYINT(1) DEFAULT 0',
	],

	'isActive' => [
		'label'            => 'Active',
		'type'             => 'checkbox',
		'defaultValue'     => '1',
		'customColumnType' => 'TINYINT(1) DEFAULT 1',
		'indexed'          => 1,
	],

	'hitCount' => [
		'label'            => 'Hit Count',
		'type'             => 'textfield',
		'defaultValue'     => '0',
		'customColumnType' => 'INT(10) UNSIGNED DEFAULT 0',
	],

	'lastHitDate' => [
		'label'            => 'Last Hit Date',
		'type'             => 'none',
		'customColumnType' => 'DATETIME DEFAULT NULL',
	],

	'notes' => [
		'label'            => 'Notes',
		'type'             => 'textbox',
		'customColumnType' => 'TEXT',
	],

	'dragSortOrder' => [
		'type'          => 'none',
		'label'         => 'Sort Order',
		'isSystemField' => 1,
		'indexed'       => 1,
	],
];
