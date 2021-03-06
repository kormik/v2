<?php
namespace Vivo\Repository;

use Vivo\CMS\Model\Entity;
use Vivo\CMS\Model\PathInterface;
use Vivo\Storage;
use Vivo\Repository\Watcher;
use Vivo\Storage\PathBuilder\PathBuilderInterface;
use Vivo\IO;
use Vivo\Storage\StorageInterface;
use Vivo\IO\IOUtil;

use Zend\Serializer\Adapter\AdapterInterface as Serializer;
use Zend\Cache\Storage\StorageInterface as Cache;
use Zend\EventManager\EventManagerInterface;

/**
 * Repository class provides methods to work with CMS repository.
 * Repository supports transactions.
 * Commit method commits the current transaction, making its changes permanent.
 * Rollback rolls back the current transaction, canceling its changes.
 */
class Repository implements RepositoryInterface
{
    /**
     * Entity object filename
     */
    const ENTITY_FILENAME = 'Entity.object';

    /**
     * Event manager
     * @var EventManagerInterface
     */
    protected $events;

	/**
	 * @var \Vivo\Storage\StorageInterface
	 */
	private $storage;

    /**
     * Path in storage where temp files can be created
     * @var string
     */
    protected $tmpPathInStorage = '/_tmp';

    /**
     * Object watcher
     * @var Watcher
     */
    protected $watcher;

	/**
	 * @var Serializer
	 */
	private $serializer;

    /**
     * The cache for objects of the model
     * The entities are passed to this cache as objects, thus the cache has to support serialization
     * @var Cache
     */
    protected $cache;

    /**
     * PathBuilder
     * @var PathBuilderInterface
     */
    protected $pathBuilder;

    /**
     * @var IOUtil
     */
    protected $ioUtil;

    /**
     * List of entities that are prepared to be persisted
     * @var PathInterface[]
     */
    protected $saveEntities         = array();

    /**
     * List of streams that are prepared to be persisted
     * Map: path => stream
     * @var IO\InputStreamInterface[]
     */
    protected $saveStreams          = array();

    /**
     * List of data items prepared to be persisted
     * Map: path => data
     * @var string[]
     */
    protected $saveData             = array();

    /**
     * List of files that are prepared to be copied
     * @var array
     */
    protected $copyFiles            = array();

    /**
     * List of paths of entities prepared for deletion
     * @var string[]
     */
    protected $deleteEntityPaths    = array();

    /**
     * List of resource paths that are prepared to be deleted
     * @var string[]
     */
    protected $deletePaths          = array();

    /**
     * List of temporary files which will be moved to their final place if everything is well in commit
     * Map: path => tempPath
     * @var string[]
     */
    protected $tmpFiles             = array();

    /**
     * List of temporary paths which will be deleted if everything is well in commit
     * Map: path => tempPath
     * @var string[]
     */
    protected $tmpDelFiles          = array();

    /**
     * Constructor
     * @param \Vivo\Storage\StorageInterface $storage
     * @param \Zend\Cache\Storage\StorageInterface $cache
     * @param \Zend\Serializer\Adapter\AdapterInterface $serializer
     * @param Watcher $watcher
     * @param \Vivo\IO\IOUtil $ioUtil
     * @param \Zend\EventManager\EventManagerInterface $events
     * @throws Exception\Exception
     */
    public function __construct(Storage\StorageInterface $storage,
                                Cache $cache = null,
                                Serializer $serializer,
                                Watcher $watcher,
                                IOUtil $ioUtil,
                                EventManagerInterface $events)
	{
        if ($cache) {
            //Check that cache supports all required data types
            $requiredTypes  = array('NULL', 'boolean', 'integer', 'double', 'string', 'array', 'object');
            $supportedTypes = $cache->getCapabilities()->getSupportedDatatypes();
            foreach ($requiredTypes as $requiredType) {
                if (!$supportedTypes[$requiredType]) {
                    throw new Exception\Exception(sprintf(
                        "%s: The cache used for Repository must support data type '%s'", __METHOD__, $requiredType));
                }
            }
        }
		$this->storage          = $storage;
        $this->cache            = $cache;
		$this->serializer       = $serializer;
        $this->watcher          = $watcher;
        $this->ioUtil           = $ioUtil;
        $this->pathBuilder      = $this->storage->getPathBuilder();
        $this->events           = $events;
	}

    /**
     * Returns entity identified by path
     * When the entity is not found, throws an exception
     * @param string $path Entity path
     * @return \Vivo\CMS\Model\Entity|null
     * @throws Exception\EntityNotFoundException
     */
    public function getEntity($path)
    {
        $path       = $this->pathBuilder->sanitize($path);
        //Get entity from watcher
        $entity     = $this->watcher->get($path);
        if ($entity) {
            return $entity;
        }
        //Get entity from cache
        $entity = $this->getEntityFromCache($path);
        if ($entity) {
            return $entity;
        }
        //Get the entity from storage
        $entity = $this->getEntityFromStorage($path);
        if ($entity) {
            return $entity;
        }
        throw new Exception\EntityNotFoundException(
            sprintf("%s: No entity found at path '%s'", __METHOD__, $path));
    }

    /**
     * Returns if an entity exists in the repository at the given path
     * @param string $path
     * @return boolean
     */
    public function hasEntity($path)
    {
        try {
            $this->getEntity($path);
            $hasEntity  = true;
        } catch (Exception\EntityNotFoundException $e) {
            $hasEntity  = false;
        }
        return $hasEntity;
    }

    /**
     * Looks up an entity in cache and returns it
     * If the entity does not exist in cache or the cache is not configured, returns null
     * @param string $path
     * @return \Vivo\CMS\Model\Entity|null
     */
    protected function getEntityFromCache($path)
    {
        $path       = $this->pathBuilder->sanitize($path);
        if ($this->cache) {
            $cacheSuccess   = null;
            $entity         = $this->cache->getItem($path, $cacheSuccess);
            if ($cacheSuccess) {
                //Store entity to watcher
                $this->watcher->add($entity);
            }
        } else {
            $entity = null;
        }
        return $entity;
    }

    /**
     * Looks up an entity in storage and returns it
     * If the entity is not found returns null
     * @param string $path
     * @return \Vivo\CMS\Model\Entity|null
     */
    public function getEntityFromStorage($path)
    {
        $path           = $this->pathBuilder->sanitize($path);
        $pathComponents = array($path, self::ENTITY_FILENAME);
        $fullPath       = $this->pathBuilder->buildStoragePath($pathComponents, true);
        if (!$this->storage->isObject($fullPath)) {
            return null;
        }
        $entitySer      = $this->storage->get($fullPath);
        /* @var $entity \Vivo\CMS\Model\Entity */
        try {
            $entity         = $this->serializer->unserialize($entitySer);
        } catch (\Exception $e) {
            throw new Exception\Exception(
                    sprintf("%s: Can't unserialize entity with path `%s`.", __METHOD__, $fullPath),null, $e);
        }

        //Set volatile path property of entity instance
        $entity->setPath($path);
        //Store entity to watcher
        $this->watcher->add($entity);
        //Store entity to cache
        if ($this->cache) {
            $this->cache->setItem($path, $entity);
        }
        return $entity;
    }

	/**
     * Returns parent folder
     * If there is no parent folder (ie this is a root), returns null
	 * @param PathInterface $folder
	 * @return \Vivo\CMS\Model\Entity
	 */
	public function getParent(PathInterface $folder)
	{
        $path               = $this->getAndCheckPath($folder);
        $pathElements       = $this->pathBuilder->getStoragePathComponents($path);
        if (count($pathElements) == 0) {
            //$folder is a root folder
            return null;
        }
        array_pop($pathElements);
        $parentFolderPath   = $this->pathBuilder->buildStoragePath($pathElements, true);
        $parentFolder       = $this->getEntity($parentFolderPath);
		return $parentFolder;
	}

    /**
     * Returns children of an entity
     * When $deep == true, returns descendants rather than children
     * @param PathInterface $entity
     * @param bool|string $className
     * @param bool $deep
     * @return \Vivo\CMS\Model\Entity[]
     */
    public function getChildren(PathInterface $entity, $className = false, $deep = false)
	{
		$children       = array();
		$descendants    = array();
		$path           = $this->getAndCheckPath($entity);
		//TODO: tady se zamyslet, zda neskenovat podle tridy i obsahy
		//if (is_subclass_of($class_name, 'Vivo\Cms\Model\Content')) {
		//	$names = $this->storage->scan("$path/Contents");
		//}
		//else {
			$names = $this->storage->scan($path);
		//}
		sort($names); // sort it in a natural way
		foreach ($names as $name) {
            $childPath = $this->pathBuilder->buildStoragePath(array($path, $name), true);
            if (!$this->storage->isObject($childPath)) {
                try {
                    $entity = $this->getEntity($childPath);
                    if ($entity/* && ($entity instanceof CMS\Model\Site || CMS::$securityManager->authorize($entity, 'Browse', false))*/) {
                        $children[] = $entity;
                    }
                } catch (Exception\EntityNotFoundException $e) {
                    //Fix for the situation when a directory exists without an Entity.object
                }
            }
		}

		// sorting
// 		$entity = $this->getEntity($path, false);
// 		if ($entity instanceof CMS\Model\Entity) {
			//@todo: sorting? jedine pre Interface "SortableInterface" ?
// 			$sorting = method_exists($entity, 'sorting') ? $entity->sorting() : $entity->sorting;
// 			if (Util\Object::is_a($sorting, 'Closure')) {
// 				usort($children, $sorting);
// 			} elseif (is_string($sorting)) {
// 				$cmp_function = 'cmp_'.str_replace(' ', '_', ($rpos = strrpos($sorting, '_')) ? substr($sorting, $rpos + 1) : $sorting);
// 				if (method_exists($entity, $cmp_function)) {
// 					usort($children, array($entity, $cmp_function));
// 				}
// 			}
// 		}

		foreach ($children as $child) {
            if (!$className || $child instanceof $className) {
                $descendants[]  = $child;
            }
            //All descendants
			if ($deep) {
                $childDescendants   = $this->getChildren($child, $className, $deep);
                $descendants        = array_merge($descendants, $childDescendants);
			}
		}
		return $descendants;
	}

	/**
     * Returns true when the folder has children
	 * @param PathInterface $folder
	 * @return bool
	 */
	public function hasChildren(PathInterface $folder)
	{
		$path = $this->getAndCheckPath($folder);
		foreach ($this->storage->scan($path) as $name) {
            $pathElements   = array($path, $name, self::ENTITY_FILENAME);
            $childPath      = $this->pathBuilder->buildStoragePath($pathElements);
			if ($this->storage->contains($childPath)) {
				return true;
			}
		}
		return false;
	}

    /**
     * Saves entity to repository
     * Changes become persistent when commit method is called within request
     * @param \Vivo\CMS\Model\PathInterface $entity
     * @return mixed|\Vivo\CMS\Model\PathInterface
     * @throws Exception\InvalidPathException
     */
    public function saveEntity(PathInterface $entity)
	{
        $entityPath = $this->getAndCheckPath($entity);
        $sanitized  = $this->pathBuilder->sanitize($entityPath);
        if ($entityPath != $sanitized) {
            throw new Exception\InvalidPathException(
                sprintf("%s: Invalid path '%s', expected '%s'", __METHOD__, $entityPath, $sanitized));
        }
        $this->saveEntities[$entityPath]    = $entity;
        return $entity;
	}

    /**
     * Adds a stream to the list of streams to be saved
     * @param PathInterface $entity
     * @param string $name
     * @param \Vivo\IO\InputStreamInterface $stream
     * @return void
     */
    public function writeResource(PathInterface $entity, $name, \Vivo\IO\InputStreamInterface $stream)
	{
        $entityPath                 = $this->getAndCheckPath($entity);
        $pathComponents             = array($entityPath, $name);
		$path                       = $this->pathBuilder->buildStoragePath($pathComponents, true);
        $this->saveStreams[$path]   = $stream;
	}

    /**
     * Adds an entity resource (data) to the list of resources to be saved
     * @param PathInterface $entity
     * @param string $name Name of resource
     * @param string $data
     */
    public function saveResource(PathInterface $entity, $name, $data)
	{
        $entityPath             = $this->getAndCheckPath($entity);
        $pathComponents         = array($entityPath, $name);
        $path                   = $this->pathBuilder->buildStoragePath($pathComponents, true);
        $this->saveData[$path]  = $data;
	}

	/**
     * Returns an input stream for reading from the resource
	 * @param PathInterface $entity
	 * @param string $name Resource file name.
	 * @return \Vivo\IO\InputStreamInterface
	 */
	public function readResource(PathInterface $entity, $name)
	{
        $entityPath     = $this->getAndCheckPath($entity);
        $pathComponents = array($entityPath, $name);
        $path           = $this->pathBuilder->buildStoragePath($pathComponents, true);
        $stream         = $this->storage->read($path);
		return $stream;
	}

    /**
     * @param PathInterface $entity
     * @return array
     */
    public function scanResources(PathInterface $entity)
    {
        $entityPath = $entity->getPath();

        $resources = array();
        $names = $this->storage->scan($entityPath);

        foreach ($names as $name) {
            $path = $this->pathBuilder->buildStoragePath(array($entityPath, $name));
            if($name != self::ENTITY_FILENAME && $this->storage->isObject($path)) {
                $resources[] = $name;
            }
        }

        return $resources;
    }

    /**
     * Returns resource from storage
     * @param PathInterface $entity
     * @param string $name
     * @throws Exception\InvalidArgumentException
     * @return string
     */
	public function getResource(PathInterface $entity, $name)
	{
        if ($name == '' || is_null($name)) {
            throw new Exception\InvalidArgumentException(sprintf("%s: Resource name cannot be empty", __METHOD__));
        }
        $entityPath     = $this->getAndCheckPath($entity);
        $pathComponents = array($entityPath, $name);
        $path           = $this->pathBuilder->buildStoragePath($pathComponents, true);
        $data           = $this->storage->get($path);
		return $data;
	}

    /**
     * Adds an entity to the list of entities to be deleted
     * @param PathInterface $entity
     */
    public function deleteEntity(PathInterface $entity)
	{
        $path   = $this->getAndCheckPath($entity);
        $this->deleteEntityByPath($path);
	}

    /**
     * Deletes entity by path
     * @param string $path
     */
    public function deleteEntityByPath($path)
    {
        $path                   = $this->pathBuilder->sanitize($path);
        $this->deleteEntityPaths[] = $path;
    }

    /**
     * Adds an entity's resource to the list of resources to be deleted
     * @param PathInterface $entity
     * @param string $name Name of the resource
     */
    public function deleteResource(PathInterface $entity, $name)
	{
        $entityPath             = $this->getAndCheckPath($entity);
        $pathComponents         = array($entityPath, $name);
        $path                   = $this->pathBuilder->buildStoragePath($pathComponents, true);
        $this->deletePaths[]    = $path;
	}

    /**
     * Moves entity
     * @param PathInterface $entity
     * @param string $target
     * @return null|\Vivo\CMS\Model\Entity
     */
    public function moveEntity(PathInterface $entity, $target)
    {
        $this->storage->move($entity->getPath(), $target);
        if ($this->hasEntity($target)) {
            $moved  = $this->getEntity($target);
        } else {
            $moved  = null;
        }
        return $moved;
    }

    /**
     * Copies entity
     * @param PathInterface $entity
     * @param string $target
     * @return null|\Vivo\CMS\Model\Entity
     */
    public function copyEntity(PathInterface $entity, $target)
    {
        $this->storage->copy($entity->getPath(), $target);
        if ($this->hasEntity($target)) {
            $copy   = $this->getEntity($target);
        } else {
            $copy   = null;
        }
        return $copy;
    }

	/**
	 * @param string $path Source path.
	 * @param string $target Destination path.
	 */
// 	function move($path, $target) {
// 		if (strpos($target, "$path/") === 0)
// 			throw new CMS\Exception(500, 'recursive_operation', array($path, $target));
// 		$path2 = str_replace(' ', '\\ ', $path);
// 		$this->indexer->deleteByQuery("vivo_cms_model_entity_path:$path2 OR vivo_cms_model_entity_path:$path2/*");
// 		$entity = $this->getEntity($path);
// 		if (method_exists($entity, 'beforeMove')) {
// 			$entity->beforeMove($target);
// 			CMS::$logger->warn('Method '.get_class($entity).'::geforeMove() is deprecated. Use Vivo\CMS\Event methods instead.');
// 		}
// 		$this->callEventOn($entity, CMS\Event::ENTITY_BEFORE_MOVE);
// 		$this->storage->move($path, $target);
// 		$targetEntity = $this->getEntity($target);
// 		$targetEntity->path = rtrim($target, '/'); // @fixme: tady by melo dojit nejspis ke smazani kese, tak aby nova entita mela novou cestu a ne starou
// 		$this->callEventOn($targetEntity, CMS\Event::ENTITY_AFTER_MOVE);
// 		//CMS::$cache->clear_mem(); //@fixme: Dodefinovat metodu v Cache tridach - nestaci definice v /FS/Cache, ale i do /FS/DB/Cache - definovat ICache
// 		$this->reindex($targetEntity, true);
// 		$this->indexer->commit();
// 		return $targetEntity;
// 	}

//	/**
//	 * @param string $path Source path.
//	 * @param string $target Destination path.
//	 * @throws Vivo\CMS\Exception 500, Recursive operation
//	 */
// 	function copy($path, $target) {
// 		if (strpos($target, "$path/") === 0)
// 			throw new CMS\Exception(500, 'recursive_operation', array($path, $target));
// 		$entity = $this->getEntity($path);
// 		CMS::$securityManager->authorize($entity, 'Copy');
// 		if (method_exists($entity, 'beforeCopy')) {
// 			$entity->beforeCopy($target);
// 			CMS::$logger->warn('Method '.get_class($entity).'::geforeCopy() is deprecated. Use Vivo\CMS\Event methods instead.');
// 		}
// 		$this->callEventOn($entity, CMS\Event::ENTITY_BEFORE_COPY);
// 		$this->storage->copy($path, $target);
// 		if ($entity = $this->getEntity($target, false)) {
// 			if ($entity->title)
// 				$entity->title .= ' COPY';
// 			$this->copy_entity($entity);
// 			$this->commit();
// 			$this->reindex($entity);
// 		}
// 		return $entity;
// 	}

//	/**
//	 * @param Vivo\CMS\Model\Entity $entity
//	 */
    /*
	private function copy_entity($entity) {
		$entity->uuid = CMS\Model\Entity::create_uuid();
		$entity->created = $entity->modified = $entity->published = CMS::$current_time;
		$entity->createdBy = $entity->modifiedBy =
			($user = CMS::$securityManager->getUserPrincipal()) ?
				"{$user->domain}\\{$user->username}" :
				Context::$instance->site->domain.'\\'.Security\Manager::USER_ANONYMOUS;
// 		if (method_exists($entity, 'afterCopy')) {
// 			$entity->afterCopy();
// 			CMS::$logger->warn('Method '.get_class($entity).'::afterCopy() is deprecated. Use Vivo\CMS\Event methods instead.');
// 		}
		CMS::$event->invoke(CMS\Event::ENTITY_AFTER_COPY, $entity);
		$this->saveEntity($entity);
		foreach($this->getAllContents($entity) as $content) {
			$this->copy_entity($content);
		}
		foreach ($entity->getChildren() as $child) {
			$this->copy_entity($child);
		}
	}
    */

    /**
     * Returns descendants of a specific path from storage
     * @param string $path
     * @return Entity[]
     */
    public function getDescendantsFromStorage($path)
    {
        $path           = $this->pathBuilder->sanitize($path);
        /** @var $descendants Entity[] */
        $descendants    = array();
        $names = $this->storage->scan($path);
        foreach ($names as $name) {
            $childPath = $this->pathBuilder->buildStoragePath(array($path, $name), true);
            if (!$this->storage->isObject($childPath)) {
                try {
                    $entity = $this->getEntityFromStorage($childPath);
                    if ($entity) {
                        $descendants[]      = $entity;
                        $childDescendants   = $this->getDescendantsFromStorage($entity->getPath());
                        $descendants        = array_merge($descendants, $childDescendants);
                    }
                } catch (Exception\EntityNotFoundException $e) {
                    //Fix for the situation when a directory exists without an Entity.object
                }
            }
        }
        return $descendants;
    }

    /**
     * Begins transaction
     * A transaction is always open, do not call 'begin()' explicitly
     */
    public function begin()
    {
        throw new Exception\Exception("%s: A transaction is always open, do not call 'begin()' explicitly", __METHOD__);
    }

    /**
     * Commit commits the current transaction, making its changes permanent
     */
    public function commit()
    {
        try {
            //Delete - Phase 1 (move to temp files)
            //a) Entities
            foreach ($this->deleteEntityPaths as $path) {
                $tmpPath                    = $this->pathBuilder->buildStoragePath(
                                                    array($this->tmpPathInStorage, uniqid('del-')), true);
                $this->tmpDelFiles[$path]   = $tmpPath;
                $this->storage->move($path, $tmpPath);
            }
            //b) Resources
            foreach ($this->deletePaths as $path) {
                $tmpPath                    = $this->pathBuilder->buildStoragePath(
                                                    array($this->tmpPathInStorage, uniqid('del-')), true);
                $this->tmpDelFiles[$path]   = $tmpPath;
                $this->storage->move($path, $tmpPath);
            }
            //Save - Phase 1 (serialize entities and files into temp files)
            //a) Entity
            foreach ($this->saveEntities as $entity) {
                $pathElements           = array($entity->getPath(), self::ENTITY_FILENAME);
                $path                   = $this->pathBuilder->buildStoragePath($pathElements, true);
                $tmpPath                = $path . '.' . uniqid('tmp-');
                $this->tmpFiles[$path]  = $tmpPath;
                $entitySer              = $this->serializer->serialize($entity);
                $this->storage->set($tmpPath, $entitySer);
            }
            //b) Data
            foreach ($this->saveData as $path => $data) {
                $tmpPath                = $path . '.' . uniqid('tmp-');
                $this->tmpFiles[$path]  = $tmpPath;
                $this->storage->set($tmpPath, $data);
            }
            //c) Streams
            foreach ($this->saveStreams as $path => $stream) {
                $tmpPath                = $path . '.' . uniqid('tmp-');
                $this->tmpFiles[$path]  = $tmpPath;
                $output                 = $this->storage->write($tmpPath);
                $this->ioUtil->copy($stream, $output, 1024);
            }

            //Delete - Phase 2 (delete the temp files) - this is done in removeTempFiles

            //Save Phase 2 (rename temp files to real ones)
            foreach ($this->tmpFiles as $path => $tmpPath) {
                if (!$this->storage->move($tmpPath, $path)) {
                    throw new Exception\Exception(
                        sprintf("%s: Move failed; source: '%s', destination: '%s'", __METHOD__, $tmpPath, $path));
                }
            }

            //The actual commit is successfully done, now process references to entities in Watcher, Cache, etc

            //Delete entities from Watcher and Cache
 			foreach ($this->deleteEntityPaths as $path) {
                $this->watcher->remove($path);
                if ($this->cache) {
                    $this->cache->removeItem($path);
                }
 			}
            //Save entities to Watcher and Cache
 			foreach ($this->saveEntities as $entity) {
                //Watcher
                $this->watcher->add($entity);
                //Cache
                if ($this->cache) {
                    $this->cache->setItem($entity->getPath(), $entity);
                }
            }
            //Trigger commit event
            $eventParams    = array(
                'copy_files'            => $this->copyFiles,
                'delete_paths'          => $this->deletePaths,
                'delete_entity_paths'   => $this->deleteEntityPaths,
                'save_data'             => $this->saveData,
                'save_entities'         => $this->saveEntities,
                'save_streams'          => $this->saveStreams,
                'tmp_del_files'         => $this->tmpDelFiles,
                'tmp_files'             => $this->tmpFiles,
            );
            $event          = new Event(EventInterface::EVENT_COMMIT, $this, $eventParams);
            $this->events->trigger($event);
            //Clean-up after commit
            $this->reset();
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Resets/clears the changes scheduled for this transaction
     * Calling this function on uncommitted transaction may lead to data loss!
     */
    protected function reset()
    {
        $this->copyFiles            = array();
        $this->deletePaths          = array();
        $this->deleteEntityPaths       = array();
        $this->saveData             = array();
        $this->saveEntities         = array();
        $this->saveStreams          = array();
        $this->removeTempFiles();
    }

    /**
     * Rollback rolls back the current transaction, canceling changes
     */
    public function rollback()
    {
        //Move back everything that was moved during Delete Phase 1
        foreach ($this->tmpDelFiles as $path => $tmpPath) {
            try {
                $this->storage->move($tmpPath, $path);
            } catch (\Exception $e) {
                //Just continue
            }
        }
        //Delete remaining temp files - done by reset()
        $this->reset();
    }

    /**
     * Removes temporary files created during commit
     */
    protected function removeTempFiles()
    {
        //TempDelFiles
        foreach ($this->tmpDelFiles as $tmpPath) {
            try {
                if ($this->storage->contains($tmpPath)) {
                    $this->storage->remove($tmpPath);
                }
            } catch (\Exception $e) {
                //Just continue, we should try to remove all temp files even though an exception has been thrown
            }
        }
        $this->tmpDelFiles          = array();
        //TempFiles
        foreach ($this->tmpFiles as $path) {
            try {
                if ($this->storage->contains($path)) {
                    $this->storage->remove($path);
                }
            } catch (\Exception $e) {
                //Just continue, we should try to remove all temp files even though an exception has been thrown
            }
        }
        $this->tmpFiles             = array();
    }

    /**
     * Retrieves and returns entity path, if path is not set, throws an exception
     * @param PathInterface $entity
     * @return string
     * @throws Exception\PathNotSetException
     */
    protected function getAndCheckPath(PathInterface $entity)
    {
        $path   = $entity->getPath();
        if (!$path) {
            throw new Exception\PathNotSetException(sprintf("%s: Entity has no path set", __METHOD__));
        }
        return $path;
    }
}
