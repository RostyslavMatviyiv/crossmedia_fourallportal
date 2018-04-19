<?php
namespace Crossmedia\Fourallportal\Mapping;

use Crossmedia\Fourallportal\Domain\Model\DimensionMapping;
use Crossmedia\Fourallportal\Domain\Model\Event;
use Crossmedia\Fourallportal\Domain\Model\Module;
use Crossmedia\Fourallportal\Service\ApiClient;
use Crossmedia\Fourallportal\TypeConverter\PimBasedTypeConverterInterface;
use Crossmedia\Products\Domain\Repository\ProductRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;
use TYPO3\CMS\Extbase\Persistence\RepositoryInterface;
use TYPO3\CMS\Extbase\Property\PropertyMappingConfiguration;
use TYPO3\CMS\Extbase\Property\TypeConverter\DateTimeConverter;
use TYPO3\CMS\Extbase\Property\TypeConverter\PersistentObjectConverter;
use TYPO3\CMS\Extbase\Reflection\MethodReflection;
use TYPO3\CMS\Extbase\Reflection\ObjectAccess;
use TYPO3\CMS\Extbase\Reflection\PropertyReflection;
use TYPO3\CMS\Extbase\Validation\Error;

abstract class AbstractMapping implements MappingInterface
{
    /**
     * @var string
     */
    protected $repositoryClassName;

    /**
     * @param array $data
     * @param Event $event
     */
    public function import(array $data, Event $event)
    {
        $repository = $this->getObjectRepository();
        $objectId = $event->getObjectId();
        $query = $repository->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(false);
        $query->matching($query->equals('remoteId', $objectId));
        $object = $query->execute()->current();

        switch ($event->getEventType()) {
            case 'delete':
                if (!$object) {
                    // push back event.

                    return;
                }
                $this->removeObjectFromRepository($object);
                unset($object);
                break;
            case 'update':
            case 'create':
                $this->importObjectWithDimensionMappings($data, $object, $event);
                break;
            default:
                throw new \RuntimeException('Unknown event type: ' . $event->getEventType());
        }

        if (isset($object)) {
            $this->processRelationships($object, $data, $event);
        }
    }

    protected function removeObjectFromRepository(DomainObjectInterface $object)
    {
        $this->getObjectRepository()->remove($object);
    }

    /**
     * @param array $data
     * @param AbstractEntity $object
     * @param Module $module
     * @param DimensionMapping|null $dimensionMapping
     */
    protected function mapPropertiesFromDataToObject(array $data, $object, Module $module, DimensionMapping $dimensionMapping = null)
    {
        if (!$data['result']) {
            return;
        }
        $map = MappingRegister::resolvePropertyMapForMapper(static::class);
        $properties = $data['result'][0]['properties'];
        $properties = $this->addMissingNullProperties($properties, $module);
        if ($dimensionMapping !== null) {
            foreach ($properties as $propertyName => $value) {
                if (is_array($value) && array_key_exists('dimensions', $value) && is_array($value['dimensions'])) {
                    foreach ($value['dimensions'] as $dimensionName => $dimensionValue) {
                        if ($dimensionMapping->matches($dimensionName)) {
                            $properties[$propertyName] = $dimensionValue;
                        }
                    }
                }
            }
        }
        $properties = $this->addMissingNullProperties($properties, $module);
        foreach ($properties as $importedName => $propertyValue) {
            if (($map[$importedName] ?? null) === false) {
                continue;
            }
            if (is_array($propertyValue[0] ?? false) && array_key_exists('dimensions', $propertyValue[0])) {
                // Data is provided with dimensions, was not re-assigned during dimension mapping above, indicating that
                // either the PIM side has no dimensions (dimensions are NULL, not array, hence array_key_exists vs isset)
                // or that the TYPO3 side has no dimensions configured. Either way, the value can be found in this property.
                $propertyValue = $propertyValue[0]['value'];
            }
            $customSetter = MappingRegister::resolvePropertyValueSetter(static::class, $importedName);
            if ($customSetter) {
                $customSetter->setValueOnObject($propertyValue, $importedName, $data, $object, $module, $this);
            } else {
                $targetPropertyName = isset($map[$importedName]) ? $map[$importedName] : GeneralUtility::underscoredToLowerCamelCase($importedName);
                $this->mapPropertyValueToObject($targetPropertyName, $propertyValue, $object);
            }
        }
    }

    /**
     * @param string $propertyName
     * @param mixed $propertyValue
     * @param AbstractEntity $object
     */
    protected function mapPropertyValueToObject($propertyName, $propertyValue, $object)
    {
        if (!property_exists(get_class($object), $propertyName)) {
            return;
        }
        if ($propertyValue === null && !reset((new \ReflectionMethod(get_class($object), 'set' . ucfirst($propertyName)))->getParameters())->allowsNull()) {
            ObjectAccess::setProperty($object, $propertyName, null);
            return;
        }
        $configuration = new PropertyMappingConfiguration();

        $configuration->allowAllProperties();
        $configuration->setTypeConverterOption(PersistentObjectConverter::class, PersistentObjectConverter::CONFIGURATION_CREATION_ALLOWED, true);
        $configuration->setTypeConverterOption(PersistentObjectConverter::class, PersistentObjectConverter::CONFIGURATION_MODIFICATION_ALLOWED, true);
        $configuration->setTypeConverterOption(DateTimeConverter::class, DateTimeConverter::CONFIGURATION_DATE_FORMAT, 'Y#m#d\\TH#i#s+');


        $propertyMapper = $this->getAccessiblePropertyMapper();
        $targetType = $this->determineDataTypeForProperty($propertyName, $object);
        if (strpos($targetType, '<')) {
            $childType = substr($targetType, strpos($targetType, '<') + 1, -1);
            $childType = trim($childType, '\\');
            $objectStorage = ObjectAccess::getProperty($object, $propertyName) ?? new ObjectStorage();
            // Step one is to detach all currently related objects. Please note that $objectStorage->removeAll($objectStorage)
            // does not work due to array pointer reset issues with Iterators. The only functioning way is to iterate and
            // detach all, one by one, as below.
            foreach ($objectStorage->toArray() as $item) {
                $objectStorage->detach($item);
            }

            foreach ((array) $propertyValue as $identifier) {
                if (!$identifier) {
                    continue;
                }
                $typeConverter = $propertyMapper->findTypeConverter($identifier, $childType, $configuration);
                if ($typeConverter instanceof PimBasedTypeConverterInterface) {
                    $typeConverter->setParentObjectAndProperty($object, $propertyName);
                }
                $child = $typeConverter->convertFrom($identifier, $childType, [], $configuration);

                if ($child instanceof Error) {
                    // For whatever reason, property validators will return a validation error rather than throw an exception.
                    // We therefore need to check this, log the problem, and skip the property.
                    echo 'Mapping error when mapping property ' . $propertyName . ' on ' . get_class($object) . ':' .  $object->getRemoteId() . ': ' . $child->getMessage() . PHP_EOL;
                    continue;
                }

                if (!$child) {
                    echo 'Child of type ' . $childType . ' identified by ' . $identifier . ' not found when mapping property ' . $propertyName . ' on ' . get_class($object) . ':' .  $object->getRemoteId() . PHP_EOL;
                    continue;
                }
                if (!$objectStorage->contains($child)) {
                    $objectStorage->attach($child);
                }
            }
            $propertyValue = $objectStorage;
        } elseif ($propertyValue !== null) {
            $sourceType = $propertyMapper->determineSourceType($propertyValue);
            if ($targetType !== $sourceType) {
                if ($targetType === 'string' && $sourceType === 'array') {
                    $propertyValue = json_encode($propertyValue, JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG);
                } else {
                    $targetType = trim($targetType, '\\');
                    $typeConverter = $propertyMapper->findTypeConverter($propertyValue, $targetType, $configuration);
                    if ($typeConverter instanceof PimBasedTypeConverterInterface) {
                        $typeConverter->setParentObjectAndProperty($object, $propertyName);
                    }
                    $propertyValue = $typeConverter->convertFrom($propertyValue, $targetType, [], $configuration);
                }

                if ($propertyValue instanceof Error) {
                    // For whatever reason, property validators will return a validation error rather than throw an exception.
                    // We therefore need to check this, log the problem, and skip the property.
                    echo 'Mapping error when mapping property ' . $propertyName . ' on ' . get_class($object) . ':' .  $object->getRemoteId() . ': ' . $propertyValue->getMessage() . PHP_EOL;
                    return;
                }

                // Sanity filter: do not attempt to set Entity with setter if an instance is required but the value is null.
                if ((new \ReflectionMethod(get_class($object), 'set' . ucfirst($propertyName)))->getNumberOfRequiredParameters() === 1) {
                    if (is_null($propertyValue) && is_a($targetType, AbstractEntity::class, true)) {
                        return;
                    }
                }
            }
        }

        $setOnObject = $object;
        $lastPropertyName = $propertyName;
        if (strpos($propertyName, '.') !== false) {
            $propertyPath = explode('.', $propertyName);
            $lastPropertyName = array_pop($propertyPath);
            foreach ($propertyPath as $currentPropertyName) {
                $setOnObject = ObjectAccess::getProperty($setOnObject, $currentPropertyName);
            }
        }

        ObjectAccess::setProperty($setOnObject, $lastPropertyName, $propertyValue);
    }

    /**
     * @param $propertyName
     * @param $object
     * @return string|false
     */
    protected function determineDataTypeForProperty($propertyName, $object)
    {
        if (property_exists(get_class($object), $propertyName)) {
            $property = new PropertyReflection($object, $propertyName);
            $varTags = $property->getTagValues('var');
            if (!empty($varTags)) {
                return strpos($varTags[0], ' ') !== false ? substr($varTags[0], 0, strpos($varTags[0], ' ')) : $varTags[0];
            }
        }

        if (method_exists(get_class($object), 'set' . ucfirst($propertyName))) {
            $method = new MethodReflection($object, 'set' . ucfirst($propertyName));
            $parameters = $method->getParameters();
            if ($parameters[0]->hasType()) {
                return (string) $parameters[0]->getType();
            }

            $varTags = $method->getTagValues('param');
            if (!empty($varTags)) {
                return reset(explode(' ', $varTags[0]));
            }
        }

        throw new \RuntimeException('Type of property ' . $propertyName . ' on ' . get_class($object) . ' could not be determined');
    }

    /**
     * @param $object
     * @param array $data
     * @param Event $event
     */
    protected function processRelationships($object, array $data, Event $event)
    {

    }

    /**
     * @return string
     */
    public function getEntityClassName()
    {
        return substr(str_replace('\\Domain\\Repository\\', '\\Domain\\Model\\', $this->repositoryClassName), 0, -10);
    }

    /**
     * @return AccessiblePropertyMapper
     */
    protected function getAccessiblePropertyMapper()
    {
        return GeneralUtility::makeInstance(ObjectManager::class)->get(AccessiblePropertyMapper::class);
    }

    /**
     * @return RepositoryInterface
     */
    public function getObjectRepository()
    {
        return GeneralUtility::makeInstance(ObjectManager::class)->get($this->repositoryClassName);
    }

    /**
     * @param ApiClient $client
     * @param Module $module
     * @param array $status
     * @return array
     */
    public function check(ApiClient $client, Module $module, array $status)
    {
        $messages = [];
        // Verify the local mapping configuration exists and points to correct properties
        $entityClass = $this->getEntityClassName();
        $map = MappingRegister::resolvePropertyMapForMapper(static::class);
        $messages['property_checks'] = '<h4>
                Property mapping checks
            </h4>';
        if (empty($map)) {
            $messages[] = sprintf(
                '<p class="text-warning">This connector has no mapping information - fields will be mapped 1:1 to properties on %s</p>',
                $entityClass
            );
        } else {
            $messages[] = '<ol>';
            foreach ($map as $sourcePropertyName => $destinationPropertyName) {
                if (!$destinationPropertyName) {
                    $messages[] = '<li class="text-warning">';
                    $messages[] = $sourcePropertyName;
                    $messages[] = ' is ignored!';
                    $messages[] = '</li>';
                    continue;
                }
                $propertyExists = property_exists($entityClass, $destinationPropertyName);
                if ($propertyExists) {
                    $messages[] = '<li class="text-success">';
                } else {
                    $messages[] = '<li class="text-danger">';
                }
                $messages[] = $sourcePropertyName;
                $messages[] = ' is manually mapped to ' . $entityClass . '->' . $destinationPropertyName;
                if (!$propertyExists) {
                    $status['class'] = 'warning';
                    $messages[] = sprintf(' - property does not exist, will cause errors if <strong>%s</strong> is included in data!', $sourcePropertyName);
                }
                $messages[] = '</li>';
            }
        }

        foreach ((new \ReflectionClass($entityClass))->getProperties() as $reflectionProperty) {
            $name = $reflectionProperty->getName();
            if (in_array($name, $map)) {
                continue;
            }
            $setterMethod = 'set' . ucfirst($name);
            if (method_exists($entityClass, $setterMethod)) {
                $messages[] = sprintf(
                    '<li><strong>%s</strong> will map to <strong>%s->%s</strong></li>',
                    GeneralUtility::camelCaseToLowerCaseUnderscored($name),
                    $reflectionProperty->getDeclaringClass()->getNamespaceName(),
                    $name
                );
            }
        }
        $messages[] = '</ol>';

        $status['description'] .= implode(chr(10), $messages);
        return $status;
    }

    /**
     * @param $properties
     * @param Module $module
     * @return mixed
     */
    protected function addMissingNullProperties($properties, Module $module)
    {
        $moduleConfiguration = $module->getModuleConfiguration();
        foreach ($moduleConfiguration['field_conf'] as $field) {
            if (!isset($properties[$field['name']])) {
                $value = '';
                if (isset($field['defaultValue'])) {
                    $value = $field['defaultValue'];
                } else {
                    switch ($field['type']) {
                        case 'CEVarchar':
                            $value = '';
                            break;
                        case 'MAMDate':
                        case 'CEDate':
                            $value = null;
                            break;
                        case 'MAMBoolean';
                        case 'CEBoolean':
                            $value = false;
                            break;
                        case 'CEDouble':
                            $value = 0.0;
                            break;
                        case 'CETimestamp':
                        case 'CEInteger':
                        case 'CELong':
                        case 'MAMNumber':
                        case 'XMPNumber':
                            $value = 0;
                            break;
                        case 'MAMList':
                        case 'CEVarcharList':
                        case 'FIELD_LINK':
                        case 'CEExternalIdList':
                        case 'CEIdList':
                        case 'MANY_TO_MANY':
                        case 'ONE_TO_MANY':
                        case 'MANY_TO_ONE':
                            $value = [];
                            break;
                        case 'CEId':
                        case 'CEExternalId':
                        case 'ONE_TO_ONE':
                            $value = null;
                            break;
                        default:
                            break;
                    }
                }
                $properties[$field['name']] = $value;
            }
        }

        return $properties;
    }

    /**
     * @param Event $event
     * @param null $existingRow
     * @return mixed
     */
    protected function createObject(Event $event, $existingRow = null)
    {
        $class = $this->getEntityClassName();
        $object = new $class();
        ObjectAccess::setProperty($object, 'remoteId', $event->getObjectId());
        ObjectAccess::setProperty($object, 'pid', $event->getModule()->getStoragePid());
        if (isset($existingRow['uid'])) {
            ObjectAccess::setProperty($object, 'uid', $existingRow['uid']);
        }
        $this->getObjectRepository()->add($object);
        GeneralUtility::makeInstance(ObjectManager::class)->get(PersistenceManager::class)->persistAll();
        return $object;
    }

    public function getTableName() {

        $dataMapper = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper::class);
        return $dataMapper->getDataMap($this->getEntityClassName())->getTableName();
    }

    /**
     * @param array $data
     * @param $object
     * @param Event $event
     * @throws \Exception
     */
    protected function importObjectWithDimensionMappings(array $data, $object, Event $event)
    {
        $dimensionMappings = $event->getModule()->getServer()->getDimensionMappings();

        $sysLanguageUids = [];
        $defaultDimensionMapping = null;
        $translationDimensionMappings = [];
        foreach ($dimensionMappings as $dimensionMapping) {
            if ($dimensionMapping->getLanguage() === 0) {
                $defaultDimensionMapping = $dimensionMapping;
            } else {
                $sysLanguageUids[] = $dimensionMapping->getLanguage();
                $translationDimensionMappings[] = $dimensionMapping;
            }
        }

        if (!$object) {
            $object = $this->createObject($event);
        }

        $this->mapPropertiesFromDataToObject($data, $object, $event->getModule(), $defaultDimensionMapping);
        $this->getObjectRepository()->update($object);

        if ($defaultDimensionMapping === null) {
            return;
        }

        $dataMapper = GeneralUtility::makeInstance(DataMapper::class);
        foreach ($translationDimensionMappings as $translationDimensionMapping) {
            $existingRow = $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow('*', $this->getTableName(), 'sys_language_uid = ' . $translationDimensionMapping->getLanguage() . ' AND l10n_parent = ' . $object->getUid());
            if (is_array($existingRow)) {
                $translationObjects = $dataMapper->map($this->getEntityClassName(), [$existingRow]);
                $translationObject = current($translationObjects);
            } else {
                $translationObject = $this->createObject($event, $existingRow);
            }
            $translationObject->_setProperty('_languageUid', $translationDimensionMapping->getLanguage());
            $translationObject->setL10nParent($object);
            $this->mapPropertiesFromDataToObject($data, $translationObject, $event->getModule(), $translationDimensionMapping);
            $this->getObjectRepository()->update($translationObject);
        }

        $GLOBALS['TYPO3_DB']->exec_DELETEquery($this->getTableName(), 'sys_language_uid NOT IN (' . implode(', ', $sysLanguageUids) . ') AND l10n_parent = ' . $object->getUid());
    }
}
