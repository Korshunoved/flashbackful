<?php
 namespace MailPoetVendor\Doctrine\ORM; if (!defined('ABSPATH')) exit; use MailPoetVendor\Doctrine\Common\EventManager; use MailPoetVendor\Doctrine\DBAL\Connection; use MailPoetVendor\Doctrine\DBAL\DriverManager; use MailPoetVendor\Doctrine\DBAL\LockMode; use MailPoetVendor\Doctrine\ORM\Mapping\ClassMetadata; use MailPoetVendor\Doctrine\ORM\Query\ResultSetMapping; use MailPoetVendor\Doctrine\ORM\Proxy\ProxyFactory; use MailPoetVendor\Doctrine\ORM\Query\FilterCollection; use MailPoetVendor\Doctrine\Common\Util\ClassUtils; use MailPoetVendor\Doctrine\Persistence\Mapping\MappingException; use MailPoetVendor\Doctrine\Persistence\ObjectRepository; use Throwable; use function ltrim; use const E_USER_DEPRECATED; use function trigger_error; class EntityManager implements \MailPoetVendor\Doctrine\ORM\EntityManagerInterface { private $config; private $conn; private $metadataFactory; private $unitOfWork; private $eventManager; private $proxyFactory; private $repositoryFactory; private $expressionBuilder; private $closed = \false; private $filterCollection; private $cache; protected function __construct(\MailPoetVendor\Doctrine\DBAL\Connection $conn, \MailPoetVendor\Doctrine\ORM\Configuration $config, \MailPoetVendor\Doctrine\Common\EventManager $eventManager) { $this->conn = $conn; $this->config = $config; $this->eventManager = $eventManager; $metadataFactoryClassName = $config->getClassMetadataFactoryName(); $this->metadataFactory = new $metadataFactoryClassName(); $this->metadataFactory->setEntityManager($this); $this->metadataFactory->setCacheDriver($this->config->getMetadataCacheImpl()); $this->repositoryFactory = $config->getRepositoryFactory(); $this->unitOfWork = new \MailPoetVendor\Doctrine\ORM\UnitOfWork($this); $this->proxyFactory = new \MailPoetVendor\Doctrine\ORM\Proxy\ProxyFactory($this, $config->getProxyDir(), $config->getProxyNamespace(), $config->getAutoGenerateProxyClasses()); if ($config->isSecondLevelCacheEnabled()) { $cacheConfig = $config->getSecondLevelCacheConfiguration(); $cacheFactory = $cacheConfig->getCacheFactory(); $this->cache = $cacheFactory->createCache($this); } } public function getConnection() { return $this->conn; } public function getMetadataFactory() { return $this->metadataFactory; } public function getExpressionBuilder() { if ($this->expressionBuilder === null) { $this->expressionBuilder = new \MailPoetVendor\Doctrine\ORM\Query\Expr(); } return $this->expressionBuilder; } public function beginTransaction() { $this->conn->beginTransaction(); } public function getCache() { return $this->cache; } public function transactional($func) { if (!\is_callable($func)) { throw new \InvalidArgumentException('Expected argument of type "callable", got "' . \gettype($func) . '"'); } $this->conn->beginTransaction(); try { $return = \call_user_func($func, $this); $this->flush(); $this->conn->commit(); return $return ?: \true; } catch (\Throwable $e) { $this->close(); $this->conn->rollBack(); throw $e; } } public function commit() { $this->conn->commit(); } public function rollback() { $this->conn->rollBack(); } public function getClassMetadata($className) { return $this->metadataFactory->getMetadataFor($className); } public function createQuery($dql = '') { $query = new \MailPoetVendor\Doctrine\ORM\Query($this); if (!empty($dql)) { $query->setDQL($dql); } return $query; } public function createNamedQuery($name) { return $this->createQuery($this->config->getNamedQuery($name)); } public function createNativeQuery($sql, \MailPoetVendor\Doctrine\ORM\Query\ResultSetMapping $rsm) { $query = new \MailPoetVendor\Doctrine\ORM\NativeQuery($this); $query->setSQL($sql); $query->setResultSetMapping($rsm); return $query; } public function createNamedNativeQuery($name) { [$sql, $rsm] = $this->config->getNamedNativeQuery($name); return $this->createNativeQuery($sql, $rsm); } public function createQueryBuilder() { return new \MailPoetVendor\Doctrine\ORM\QueryBuilder($this); } public function flush($entity = null) { if ($entity !== null) { @\trigger_error('Calling ' . __METHOD__ . '() with any arguments to flush specific entities is deprecated and will not be supported in Doctrine ORM 3.0.', \E_USER_DEPRECATED); } $this->errorIfClosed(); $this->unitOfWork->commit($entity); } public function find($className, $id, $lockMode = null, $lockVersion = null) { $class = $this->metadataFactory->getMetadataFor(\ltrim($className, '\\')); if ($lockMode !== null) { $this->checkLockRequirements($lockMode, $class); } if (!\is_array($id)) { if ($class->isIdentifierComposite) { throw \MailPoetVendor\Doctrine\ORM\ORMInvalidArgumentException::invalidCompositeIdentifier(); } $id = [$class->identifier[0] => $id]; } foreach ($id as $i => $value) { if (\is_object($value) && $this->metadataFactory->hasMetadataFor(\MailPoetVendor\Doctrine\Common\Util\ClassUtils::getClass($value))) { $id[$i] = $this->unitOfWork->getSingleIdentifierValue($value); if ($id[$i] === null) { throw \MailPoetVendor\Doctrine\ORM\ORMInvalidArgumentException::invalidIdentifierBindingEntity(); } } } $sortedId = []; foreach ($class->identifier as $identifier) { if (!isset($id[$identifier])) { throw \MailPoetVendor\Doctrine\ORM\ORMException::missingIdentifierField($class->name, $identifier); } $sortedId[$identifier] = $id[$identifier]; unset($id[$identifier]); } if ($id) { throw \MailPoetVendor\Doctrine\ORM\ORMException::unrecognizedIdentifierFields($class->name, \array_keys($id)); } $unitOfWork = $this->getUnitOfWork(); if (($entity = $unitOfWork->tryGetById($sortedId, $class->rootEntityName)) !== \false) { if (!$entity instanceof $class->name) { return null; } switch (\true) { case \MailPoetVendor\Doctrine\DBAL\LockMode::OPTIMISTIC === $lockMode: $this->lock($entity, $lockMode, $lockVersion); break; case \MailPoetVendor\Doctrine\DBAL\LockMode::NONE === $lockMode: case \MailPoetVendor\Doctrine\DBAL\LockMode::PESSIMISTIC_READ === $lockMode: case \MailPoetVendor\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE === $lockMode: $persister = $unitOfWork->getEntityPersister($class->name); $persister->refresh($sortedId, $entity, $lockMode); break; } return $entity; } $persister = $unitOfWork->getEntityPersister($class->name); switch (\true) { case \MailPoetVendor\Doctrine\DBAL\LockMode::OPTIMISTIC === $lockMode: $entity = $persister->load($sortedId); $unitOfWork->lock($entity, $lockMode, $lockVersion); return $entity; case \MailPoetVendor\Doctrine\DBAL\LockMode::PESSIMISTIC_READ === $lockMode: case \MailPoetVendor\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE === $lockMode: return $persister->load($sortedId, null, null, [], $lockMode); default: return $persister->loadById($sortedId); } } public function getReference($entityName, $id) { $class = $this->metadataFactory->getMetadataFor(\ltrim($entityName, '\\')); if (!\is_array($id)) { $id = [$class->identifier[0] => $id]; } $sortedId = []; foreach ($class->identifier as $identifier) { if (!isset($id[$identifier])) { throw \MailPoetVendor\Doctrine\ORM\ORMException::missingIdentifierField($class->name, $identifier); } $sortedId[$identifier] = $id[$identifier]; unset($id[$identifier]); } if ($id) { throw \MailPoetVendor\Doctrine\ORM\ORMException::unrecognizedIdentifierFields($class->name, \array_keys($id)); } if (($entity = $this->unitOfWork->tryGetById($sortedId, $class->rootEntityName)) !== \false) { return $entity instanceof $class->name ? $entity : null; } if ($class->subClasses) { return $this->find($entityName, $sortedId); } $entity = $this->proxyFactory->getProxy($class->name, $sortedId); $this->unitOfWork->registerManaged($entity, $sortedId, []); return $entity; } public function getPartialReference($entityName, $identifier) { $class = $this->metadataFactory->getMetadataFor(\ltrim($entityName, '\\')); if (($entity = $this->unitOfWork->tryGetById($identifier, $class->rootEntityName)) !== \false) { return $entity instanceof $class->name ? $entity : null; } if (!\is_array($identifier)) { $identifier = [$class->identifier[0] => $identifier]; } $entity = $class->newInstance(); $class->setIdentifierValues($entity, $identifier); $this->unitOfWork->registerManaged($entity, $identifier, []); $this->unitOfWork->markReadOnly($entity); return $entity; } public function clear($entityName = null) { if (null !== $entityName && !\is_string($entityName)) { throw \MailPoetVendor\Doctrine\ORM\ORMInvalidArgumentException::invalidEntityName($entityName); } if ($entityName !== null) { @\trigger_error('Calling ' . __METHOD__ . '() with any arguments to clear specific entities is deprecated and will not be supported in Doctrine ORM 3.0.', \E_USER_DEPRECATED); } $this->unitOfWork->clear(null === $entityName ? null : $this->metadataFactory->getMetadataFor($entityName)->getName()); } public function close() { $this->clear(); $this->closed = \true; } public function persist($entity) { if (!\is_object($entity)) { throw \MailPoetVendor\Doctrine\ORM\ORMInvalidArgumentException::invalidObject('EntityManager#persist()', $entity); } $this->errorIfClosed(); $this->unitOfWork->persist($entity); } public function remove($entity) { if (!\is_object($entity)) { throw \MailPoetVendor\Doctrine\ORM\ORMInvalidArgumentException::invalidObject('EntityManager#remove()', $entity); } $this->errorIfClosed(); $this->unitOfWork->remove($entity); } public function refresh($entity) { if (!\is_object($entity)) { throw \MailPoetVendor\Doctrine\ORM\ORMInvalidArgumentException::invalidObject('EntityManager#refresh()', $entity); } $this->errorIfClosed(); $this->unitOfWork->refresh($entity); } public function detach($entity) { @\trigger_error('Method ' . __METHOD__ . '() is deprecated and will be removed in Doctrine ORM 3.0.', \E_USER_DEPRECATED); if (!\is_object($entity)) { throw \MailPoetVendor\Doctrine\ORM\ORMInvalidArgumentException::invalidObject('EntityManager#detach()', $entity); } $this->unitOfWork->detach($entity); } public function merge($entity) { @\trigger_error('Method ' . __METHOD__ . '() is deprecated and will be removed in Doctrine ORM 3.0.', \E_USER_DEPRECATED); if (!\is_object($entity)) { throw \MailPoetVendor\Doctrine\ORM\ORMInvalidArgumentException::invalidObject('EntityManager#merge()', $entity); } $this->errorIfClosed(); return $this->unitOfWork->merge($entity); } public function copy($entity, $deep = \false) { @\trigger_error('Method ' . __METHOD__ . '() is deprecated and will be removed in Doctrine ORM 3.0.', \E_USER_DEPRECATED); throw new \BadMethodCallException("Not implemented."); } public function lock($entity, $lockMode, $lockVersion = null) { $this->unitOfWork->lock($entity, $lockMode, $lockVersion); } public function getRepository($entityName) { return $this->repositoryFactory->getRepository($this, $entityName); } public function contains($entity) { return $this->unitOfWork->isScheduledForInsert($entity) || $this->unitOfWork->isInIdentityMap($entity) && !$this->unitOfWork->isScheduledForDelete($entity); } public function getEventManager() { return $this->eventManager; } public function getConfiguration() { return $this->config; } private function errorIfClosed() { if ($this->closed) { throw \MailPoetVendor\Doctrine\ORM\ORMException::entityManagerClosed(); } } public function isOpen() { return !$this->closed; } public function getUnitOfWork() { return $this->unitOfWork; } public function getHydrator($hydrationMode) { return $this->newHydrator($hydrationMode); } public function newHydrator($hydrationMode) { switch ($hydrationMode) { case \MailPoetVendor\Doctrine\ORM\Query::HYDRATE_OBJECT: return new \MailPoetVendor\Doctrine\ORM\Internal\Hydration\ObjectHydrator($this); case \MailPoetVendor\Doctrine\ORM\Query::HYDRATE_ARRAY: return new \MailPoetVendor\Doctrine\ORM\Internal\Hydration\ArrayHydrator($this); case \MailPoetVendor\Doctrine\ORM\Query::HYDRATE_SCALAR: return new \MailPoetVendor\Doctrine\ORM\Internal\Hydration\ScalarHydrator($this); case \MailPoetVendor\Doctrine\ORM\Query::HYDRATE_SINGLE_SCALAR: return new \MailPoetVendor\Doctrine\ORM\Internal\Hydration\SingleScalarHydrator($this); case \MailPoetVendor\Doctrine\ORM\Query::HYDRATE_SIMPLEOBJECT: return new \MailPoetVendor\Doctrine\ORM\Internal\Hydration\SimpleObjectHydrator($this); default: if (($class = $this->config->getCustomHydrationMode($hydrationMode)) !== null) { return new $class($this); } } throw \MailPoetVendor\Doctrine\ORM\ORMException::invalidHydrationMode($hydrationMode); } public function getProxyFactory() { return $this->proxyFactory; } public function initializeObject($obj) { $this->unitOfWork->initializeObject($obj); } public static function create($connection, \MailPoetVendor\Doctrine\ORM\Configuration $config, \MailPoetVendor\Doctrine\Common\EventManager $eventManager = null) { if (!$config->getMetadataDriverImpl()) { throw \MailPoetVendor\Doctrine\ORM\ORMException::missingMappingDriverImpl(); } $connection = static::createConnection($connection, $config, $eventManager); return new \MailPoetVendor\Doctrine\ORM\EntityManager($connection, $config, $connection->getEventManager()); } protected static function createConnection($connection, \MailPoetVendor\Doctrine\ORM\Configuration $config, \MailPoetVendor\Doctrine\Common\EventManager $eventManager = null) { if (\is_array($connection)) { return \MailPoetVendor\Doctrine\DBAL\DriverManager::getConnection($connection, $config, $eventManager ?: new \MailPoetVendor\Doctrine\Common\EventManager()); } if (!$connection instanceof \MailPoetVendor\Doctrine\DBAL\Connection) { throw new \InvalidArgumentException(\sprintf('Invalid $connection argument of type %s given%s.', \is_object($connection) ? \get_class($connection) : \gettype($connection), \is_object($connection) ? '' : ': "' . $connection . '"')); } if ($eventManager !== null && $connection->getEventManager() !== $eventManager) { throw \MailPoetVendor\Doctrine\ORM\ORMException::mismatchedEventManager(); } return $connection; } public function getFilters() { if (null === $this->filterCollection) { $this->filterCollection = new \MailPoetVendor\Doctrine\ORM\Query\FilterCollection($this); } return $this->filterCollection; } public function isFiltersStateClean() { return null === $this->filterCollection || $this->filterCollection->isClean(); } public function hasFilters() { return null !== $this->filterCollection; } private function checkLockRequirements(int $lockMode, \MailPoetVendor\Doctrine\ORM\Mapping\ClassMetadata $class) : void { switch ($lockMode) { case \MailPoetVendor\Doctrine\DBAL\LockMode::OPTIMISTIC: if (!$class->isVersioned) { throw \MailPoetVendor\Doctrine\ORM\OptimisticLockException::notVersioned($class->name); } break; case \MailPoetVendor\Doctrine\DBAL\LockMode::PESSIMISTIC_READ: case \MailPoetVendor\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE: if (!$this->getConnection()->isTransactionActive()) { throw \MailPoetVendor\Doctrine\ORM\TransactionRequiredException::transactionRequired(); } } } } 