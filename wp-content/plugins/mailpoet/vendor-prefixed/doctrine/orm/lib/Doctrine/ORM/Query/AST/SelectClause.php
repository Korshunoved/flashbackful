<?php
 namespace MailPoetVendor\Doctrine\ORM\Query\AST; if (!defined('ABSPATH')) exit; class SelectClause extends \MailPoetVendor\Doctrine\ORM\Query\AST\Node { public $isDistinct; public $selectExpressions = []; public function __construct(array $selectExpressions, $isDistinct) { $this->isDistinct = $isDistinct; $this->selectExpressions = $selectExpressions; } public function dispatch($sqlWalker) { return $sqlWalker->walkSelectClause($this); } } 