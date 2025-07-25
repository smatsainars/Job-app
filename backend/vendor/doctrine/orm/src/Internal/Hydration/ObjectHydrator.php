<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\Hydration;

use BackedEnum;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Query;
use Doctrine\ORM\UnitOfWork;

use function array_fill_keys;
use function array_keys;
use function array_map;
use function assert;
use function count;
use function is_array;
use function key;
use function ltrim;
use function spl_object_id;

/**
 * The ObjectHydrator constructs an object graph out of an SQL result set.
 *
 * Internal note: Highly performance-sensitive code.
 */
class ObjectHydrator extends AbstractHydrator
{
    /** @var mixed[] */
    private array $identifierMap = [];

    /** @var mixed[] */
    private array $resultPointers = [];

    /** @var mixed[] */
    private array $idTemplate = [];

    private int $resultCounter = 0;

    /** @var mixed[] */
    private array $rootAliases = [];

    /** @var mixed[] */
    private array $initializedCollections = [];

    /** @var array<string, PersistentCollection> */
    private array $uninitializedCollections = [];

    /** @var mixed[] */
    private array $existingCollections = [];

    protected function prepare(): void
    {
        if (! isset($this->hints[UnitOfWork::HINT_DEFEREAGERLOAD])) {
            $this->hints[UnitOfWork::HINT_DEFEREAGERLOAD] = true;
        }

        foreach ($this->resultSetMapping()->aliasMap as $dqlAlias => $className) {
            $this->identifierMap[$dqlAlias] = [];
            $this->idTemplate[$dqlAlias]    = '';

            // Remember which associations are "fetch joined", so that we know where to inject
            // collection stubs or proxies and where not.
            if (! isset($this->resultSetMapping()->relationMap[$dqlAlias])) {
                continue;
            }

            $parent = $this->resultSetMapping()->parentAliasMap[$dqlAlias];

            if (! isset($this->resultSetMapping()->aliasMap[$parent])) {
                if (isset($this->resultSetMapping()->nestedEntities[$dqlAlias])) {
                    continue;
                }

                throw HydrationException::parentObjectOfRelationNotFound($dqlAlias, $parent);
            }

            $sourceClassName = $this->resultSetMapping()->aliasMap[$parent];
            $sourceClass     = $this->getClassMetadata($sourceClassName);
            $assoc           = $sourceClass->associationMappings[$this->resultSetMapping()->relationMap[$dqlAlias]];

            $this->hints['fetched'][$parent][$assoc->fieldName] = true;

            if ($assoc->isManyToMany()) {
                continue;
            }

            // Mark any non-collection opposite sides as fetched, too.
            if (! $assoc->isOwningSide()) {
                $this->hints['fetched'][$dqlAlias][$assoc->mappedBy] = true;

                continue;
            }

            // handle fetch-joined owning side bi-directional one-to-one associations
            if ($assoc->inversedBy !== null) {
                $class        = $this->getClassMetadata($className);
                $inverseAssoc = $class->associationMappings[$assoc->inversedBy];

                if (! $inverseAssoc->isToOne()) {
                    continue;
                }

                $this->hints['fetched'][$dqlAlias][$inverseAssoc->fieldName] = true;
            }
        }
    }

    protected function cleanup(): void
    {
        $eagerLoad = isset($this->hints[UnitOfWork::HINT_DEFEREAGERLOAD]) && $this->hints[UnitOfWork::HINT_DEFEREAGERLOAD] === true;

        parent::cleanup();

        $this->identifierMap            =
        $this->initializedCollections   =
        $this->uninitializedCollections =
        $this->existingCollections      =
        $this->resultPointers           = [];

        if ($eagerLoad) {
            $this->uow->triggerEagerLoads();
        }

        $this->uow->hydrationComplete();
    }

    protected function cleanupAfterRowIteration(): void
    {
        $this->identifierMap            =
        $this->initializedCollections   =
        $this->uninitializedCollections =
        $this->existingCollections      =
        $this->resultPointers           = [];
    }

    /**
     * {@inheritDoc}
     */
    protected function hydrateAllData(): array
    {
        $result = [];

        while ($row = $this->statement()->fetchAssociative()) {
            $this->hydrateRowData($row, $result);
        }

        // Take snapshots from all newly initialized collections
        foreach ($this->initializedCollections as $coll) {
            $coll->takeSnapshot();
        }

        foreach ($this->uninitializedCollections as $coll) {
            if (! $coll->isInitialized()) {
                $coll->setInitialized(true);
            }
        }

        return $result;
    }

    /**
     * Initializes a related collection.
     *
     * @param string $fieldName      The name of the field on the entity that holds the collection.
     * @param string $parentDqlAlias Alias of the parent fetch joining this collection.
     */
    private function initRelatedCollection(
        object $entity,
        ClassMetadata $class,
        string $fieldName,
        string $parentDqlAlias,
    ): PersistentCollection {
        $oid      = spl_object_id($entity);
        $relation = $class->associationMappings[$fieldName];
        $value    = $class->propertyAccessors[$fieldName]->getValue($entity);

        if ($value === null || is_array($value)) {
            $value = new ArrayCollection((array) $value);
        }

        if (! $value instanceof PersistentCollection) {
            assert($relation->isToMany());
            $value = new PersistentCollection(
                $this->em,
                $this->metadataCache[$relation->targetEntity],
                $value,
            );
            $value->setOwner($entity, $relation);

            $class->propertyAccessors[$fieldName]->setValue($entity, $value);
            $this->uow->setOriginalEntityProperty($oid, $fieldName, $value);

            $this->initializedCollections[$oid . $fieldName] = $value;
        } elseif (
            isset($this->hints[Query::HINT_REFRESH]) ||
            isset($this->hints['fetched'][$parentDqlAlias][$fieldName]) &&
             ! $value->isInitialized()
        ) {
            // Is already PersistentCollection, but either REFRESH or FETCH-JOIN and UNINITIALIZED!
            $value->setDirty(false);
            $value->setInitialized(true);
            $value->unwrap()->clear();

            $this->initializedCollections[$oid . $fieldName] = $value;
        } else {
            // Is already PersistentCollection, and DON'T REFRESH or FETCH-JOIN!
            $this->existingCollections[$oid . $fieldName] = $value;
        }

        return $value;
    }

    /**
     * Gets an entity instance.
     *
     * @param string $dqlAlias The DQL alias of the entity's class.
     * @phpstan-param array<string, mixed> $data     The instance data.
     *
     * @throws HydrationException
     */
    private function getEntity(array $data, string $dqlAlias): object
    {
        $className = $this->resultSetMapping()->aliasMap[$dqlAlias];

        if (isset($this->resultSetMapping()->discriminatorColumns[$dqlAlias])) {
            $fieldName = $this->resultSetMapping()->discriminatorColumns[$dqlAlias];

            if (! isset($this->resultSetMapping()->metaMappings[$fieldName])) {
                throw HydrationException::missingDiscriminatorMetaMappingColumn($className, $fieldName, $dqlAlias);
            }

            $discrColumn = $this->resultSetMapping()->metaMappings[$fieldName];

            if (! isset($data[$discrColumn])) {
                throw HydrationException::missingDiscriminatorColumn($className, $discrColumn, $dqlAlias);
            }

            if ($data[$discrColumn] === '') {
                throw HydrationException::emptyDiscriminatorValue($dqlAlias);
            }

            $discrMap           = $this->metadataCache[$className]->discriminatorMap;
            $discriminatorValue = $data[$discrColumn];
            if ($discriminatorValue instanceof BackedEnum) {
                $discriminatorValue = $discriminatorValue->value;
            }

            $discriminatorValue = (string) $discriminatorValue;

            if (! isset($discrMap[$discriminatorValue])) {
                throw HydrationException::invalidDiscriminatorValue($discriminatorValue, array_keys($discrMap));
            }

            $className = $discrMap[$discriminatorValue];

            unset($data[$discrColumn]);
        }

        if (isset($this->hints[Query::HINT_REFRESH_ENTITY], $this->rootAliases[$dqlAlias])) {
            $this->registerManaged($this->metadataCache[$className], $this->hints[Query::HINT_REFRESH_ENTITY], $data);
        }

        $this->hints['fetchAlias'] = $dqlAlias;

        return $this->uow->createEntity($className, $data, $this->hints);
    }

    /**
     * @param class-string $className
     * @phpstan-param array<string, mixed> $data
     */
    private function getEntityFromIdentityMap(string $className, array $data): object|bool
    {
        // TODO: Abstract this code and UnitOfWork::createEntity() equivalent?
        $class = $this->metadataCache[$className];

        if ($class->isIdentifierComposite) {
            $idHash = UnitOfWork::getIdHashByIdentifier(
                array_map(
                    /** @return mixed */
                    static fn (string $fieldName) => isset($class->associationMappings[$fieldName]) && assert($class->associationMappings[$fieldName]->isToOneOwningSide())
                        ? $data[$class->associationMappings[$fieldName]->joinColumns[0]->name]
                        : $data[$fieldName],
                    $class->identifier,
                ),
            );

            return $this->uow->tryGetByIdHash(ltrim($idHash), $class->rootEntityName);
        } elseif (isset($class->associationMappings[$class->identifier[0]])) {
            $association = $class->associationMappings[$class->identifier[0]];
            assert($association->isToOneOwningSide());

            return $this->uow->tryGetByIdHash($data[$association->joinColumns[0]->name], $class->rootEntityName);
        }

        return $this->uow->tryGetByIdHash($data[$class->identifier[0]], $class->rootEntityName);
    }

    /**
     * Hydrates a single row in an SQL result set.
     *
     * @internal
     * First, the data of the row is split into chunks where each chunk contains data
     * that belongs to a particular component/class. Afterwards, all these chunks
     * are processed, one after the other. For each chunk of class data only one of the
     * following code paths is executed:
     * Path A: The data chunk belongs to a joined/associated object and the association
     *         is collection-valued.
     * Path B: The data chunk belongs to a joined/associated object and the association
     *         is single-valued.
     * Path C: The data chunk belongs to a root result element/object that appears in the topmost
     *         level of the hydrated result. A typical example are the objects of the type
     *         specified by the FROM clause in a DQL query.
     *
     * @param mixed[] $row    The data of the row to process.
     * @param mixed[] $result The result array to fill.
     */
    protected function hydrateRowData(array $row, array &$result): void
    {
        // Initialize
        $id                 = $this->idTemplate; // initialize the id-memory
        $nonemptyComponents = [];
        // Split the row data into chunks of class data.
        $rowData = $this->gatherRowData($row, $id, $nonemptyComponents);

        // reset result pointers for each data row
        $this->resultPointers = [];

        // Hydrate the data chunks
        foreach ($rowData['data'] as $dqlAlias => $data) {
            $entityName = $this->resultSetMapping()->aliasMap[$dqlAlias];

            if (isset($this->resultSetMapping()->parentAliasMap[$dqlAlias])) {
                // It's a joined result

                $parentAlias = $this->resultSetMapping()->parentAliasMap[$dqlAlias];
                // we need the $path to save into the identifier map which entities were already
                // seen for this parent-child relationship
                $path = $parentAlias . '.' . $dqlAlias;

                // We have a RIGHT JOIN result here. Doctrine cannot hydrate RIGHT JOIN Object-Graphs
                if (! isset($nonemptyComponents[$parentAlias])) {
                    // TODO: Add special case code where we hydrate the right join objects into identity map at least
                    continue;
                }

                $parentClass   = $this->metadataCache[$this->resultSetMapping()->aliasMap[$parentAlias]];
                $relationField = $this->resultSetMapping()->relationMap[$dqlAlias];
                $relation      = $parentClass->associationMappings[$relationField];
                $reflField     = $parentClass->propertyAccessors[$relationField];

                // Get a reference to the parent object to which the joined element belongs.
                if ($this->resultSetMapping()->isMixed && isset($this->rootAliases[$parentAlias])) {
                    $objectClass  = $this->resultPointers[$parentAlias];
                    $parentObject = $objectClass[key($objectClass)];
                } elseif (isset($this->resultPointers[$parentAlias])) {
                    $parentObject = $this->resultPointers[$parentAlias];
                } else {
                    // Parent object of relation not found, mark as not-fetched again
                    if (isset($nonemptyComponents[$dqlAlias])) {
                        $element = $this->getEntity($data, $dqlAlias);

                        // Update result pointer and provide initial fetch data for parent
                        $this->resultPointers[$dqlAlias]               = $element;
                        $rowData['data'][$parentAlias][$relationField] = $element;
                    } else {
                        $element = null;
                    }

                    // Mark as not-fetched again
                    unset($this->hints['fetched'][$parentAlias][$relationField]);
                    continue;
                }

                $oid = spl_object_id($parentObject);

                // Check the type of the relation (many or single-valued)
                if (! $relation->isToOne()) {
                    // PATH A: Collection-valued association
                    $reflFieldValue = $reflField->getValue($parentObject);

                    if (isset($nonemptyComponents[$dqlAlias])) {
                        $collKey = $oid . $relationField;
                        if (isset($this->initializedCollections[$collKey])) {
                            $reflFieldValue = $this->initializedCollections[$collKey];
                        } elseif (! isset($this->existingCollections[$collKey])) {
                            $reflFieldValue = $this->initRelatedCollection($parentObject, $parentClass, $relationField, $parentAlias);
                        }

                        $indexExists  = isset($this->identifierMap[$path][$id[$parentAlias]][$id[$dqlAlias]]);
                        $index        = $indexExists ? $this->identifierMap[$path][$id[$parentAlias]][$id[$dqlAlias]] : false;
                        $indexIsValid = $index !== false ? isset($reflFieldValue[$index]) : false;

                        if (! $indexExists || ! $indexIsValid) {
                            if (isset($this->existingCollections[$collKey])) {
                                // Collection exists, only look for the element in the identity map.
                                $element = $this->getEntityFromIdentityMap($entityName, $data);
                                if ($element) {
                                    $this->resultPointers[$dqlAlias] = $element;
                                } else {
                                    unset($this->resultPointers[$dqlAlias]);
                                }
                            } else {
                                $element = $this->getEntity($data, $dqlAlias);

                                if (isset($this->resultSetMapping()->indexByMap[$dqlAlias])) {
                                    $indexValue = $row[$this->resultSetMapping()->indexByMap[$dqlAlias]];
                                    $reflFieldValue->hydrateSet($indexValue, $element);
                                    $this->identifierMap[$path][$id[$parentAlias]][$id[$dqlAlias]] = $indexValue;
                                } else {
                                    if (! $reflFieldValue->contains($element)) {
                                        $reflFieldValue->hydrateAdd($element);
                                        $reflFieldValue->last();
                                    }

                                    $this->identifierMap[$path][$id[$parentAlias]][$id[$dqlAlias]] = $reflFieldValue->key();
                                }

                                // Update result pointer
                                $this->resultPointers[$dqlAlias] = $element;
                            }
                        } else {
                            // Update result pointer
                            $this->resultPointers[$dqlAlias] = $reflFieldValue[$index];
                        }
                    } elseif (! $reflFieldValue) {
                        $this->initRelatedCollection($parentObject, $parentClass, $relationField, $parentAlias);
                    } elseif ($reflFieldValue instanceof PersistentCollection && $reflFieldValue->isInitialized() === false && ! isset($this->uninitializedCollections[$oid . $relationField])) {
                        $this->uninitializedCollections[$oid . $relationField] = $reflFieldValue;
                    }
                } else {
                    // PATH B: Single-valued association
                    $reflFieldValue = $reflField->getValue($parentObject);

                    if (! $reflFieldValue || isset($this->hints[Query::HINT_REFRESH]) || $this->uow->isUninitializedObject($reflFieldValue)) {
                        // we only need to take action if this value is null,
                        // we refresh the entity or its an uninitialized proxy.
                        if (isset($nonemptyComponents[$dqlAlias])) {
                            $element = $this->getEntity($data, $dqlAlias);
                            $reflField->setValue($parentObject, $element);
                            $this->uow->setOriginalEntityProperty($oid, $relationField, $element);
                            $targetClass = $this->metadataCache[$relation->targetEntity];

                            if ($relation->isOwningSide()) {
                                // TODO: Just check hints['fetched'] here?
                                // If there is an inverse mapping on the target class its bidirectional
                                if ($relation->inversedBy !== null) {
                                    $inverseAssoc = $targetClass->associationMappings[$relation->inversedBy];
                                    if ($inverseAssoc->isToOne()) {
                                        $targetClass->propertyAccessors[$inverseAssoc->fieldName]->setValue($element, $parentObject);
                                        $this->uow->setOriginalEntityProperty(spl_object_id($element), $inverseAssoc->fieldName, $parentObject);
                                    }
                                }
                            } else {
                                // For sure bidirectional, as there is no inverse side in unidirectional mappings
                                $targetClass->propertyAccessors[$relation->mappedBy]->setValue($element, $parentObject);
                                $this->uow->setOriginalEntityProperty(spl_object_id($element), $relation->mappedBy, $parentObject);
                            }

                            // Update result pointer
                            $this->resultPointers[$dqlAlias] = $element;
                        } else {
                            $this->uow->setOriginalEntityProperty($oid, $relationField, null);
                            $reflField->setValue($parentObject, null);
                        }
                        // else leave $reflFieldValue null for single-valued associations
                    } else {
                        // Update result pointer
                        $this->resultPointers[$dqlAlias] = $reflFieldValue;
                    }
                }
            } else {
                // PATH C: Its a root result element
                $this->rootAliases[$dqlAlias] = true; // Mark as root alias
                $entityKey                    = $this->resultSetMapping()->entityMappings[$dqlAlias] ?: 0;

                // if this row has a NULL value for the root result id then make it a null result.
                if (! isset($nonemptyComponents[$dqlAlias])) {
                    if ($this->resultSetMapping()->isMixed) {
                        $result[] = [$entityKey => null];
                    } else {
                        $result[] = null;
                    }

                    $resultKey = $this->resultCounter;
                    ++$this->resultCounter;
                    continue;
                }

                // check for existing result from the iterations before
                if (! isset($this->identifierMap[$dqlAlias][$id[$dqlAlias]])) {
                    $element = $this->getEntity($data, $dqlAlias);

                    if ($this->resultSetMapping()->isMixed) {
                        $element = [$entityKey => $element];
                    }

                    if (isset($this->resultSetMapping()->indexByMap[$dqlAlias])) {
                        $resultKey = $row[$this->resultSetMapping()->indexByMap[$dqlAlias]];

                        if (isset($this->hints['collection'])) {
                            $this->hints['collection']->hydrateSet($resultKey, $element);
                        }

                        $result[$resultKey] = $element;
                    } else {
                        $resultKey = $this->resultCounter;
                        ++$this->resultCounter;

                        if (isset($this->hints['collection'])) {
                            $this->hints['collection']->hydrateAdd($element);
                        }

                        $result[] = $element;
                    }

                    $this->identifierMap[$dqlAlias][$id[$dqlAlias]] = $resultKey;

                    // Update result pointer
                    $this->resultPointers[$dqlAlias] = $element;
                } else {
                    // Update result pointer
                    $index                           = $this->identifierMap[$dqlAlias][$id[$dqlAlias]];
                    $this->resultPointers[$dqlAlias] = $result[$index];
                    $resultKey                       = $index;
                }
            }

            if (isset($this->hints[Query::HINT_INTERNAL_ITERATION]) && $this->hints[Query::HINT_INTERNAL_ITERATION]) {
                $this->uow->hydrationComplete();
            }
        }

        if (! isset($resultKey)) {
            $this->resultCounter++;
        }

        // Append scalar values to mixed result sets
        if (isset($rowData['scalars'])) {
            if (! isset($resultKey)) {
                $resultKey = isset($this->resultSetMapping()->indexByMap['scalars'])
                    ? $row[$this->resultSetMapping()->indexByMap['scalars']]
                    : $this->resultCounter - 1;
            }

            foreach ($rowData['scalars'] as $name => $value) {
                $result[$resultKey][$name] = $value;
            }
        }

        // Append new object to mixed result sets
        if (isset($rowData['newObjects'])) {
            if (! isset($resultKey)) {
                $resultKey = $this->resultCounter - 1;
            }

            $scalarCount = (isset($rowData['scalars']) ? count($rowData['scalars']) : 0);

            foreach ($rowData['newObjects'] as $objIndex => $newObject) {
                $obj = $newObject['obj'];

                if ($scalarCount === 0 && count($rowData['newObjects']) === 1) {
                    $result[$resultKey] = $obj;

                    continue;
                }

                $result[$resultKey][$objIndex] = $obj;
            }
        }
    }

    /** @param mixed[] $data pre-hydrated SQL Result Row. */
    protected function hydrateNestedEntity(array $data, string $dqlAlias): mixed
    {
        if (isset($this->resultSetMapping()->nestedEntities[$dqlAlias])) {
            return $this->getEntity($data, $dqlAlias);
        }

        return $data;
    }

    /**
     * When executed in a hydrate() loop we may have to clear internal state to
     * decrease memory consumption.
     */
    public function onClear(mixed $eventArgs): void
    {
        parent::onClear($eventArgs);

        $aliases = array_keys($this->identifierMap);

        $this->identifierMap = array_fill_keys($aliases, []);
    }
}
