<?php
namespace Crossmedia\Fourallportal\Mapping;

class MappingRegister
{
    /**
     * @var array
     */
    static protected $mappings = [];

    /**
     * @var array
     */
    static protected $propertyMaps = [];

    /**
     * @return void
     */
    public static function registerMapping($className, array $propertyMap = array())
    {
        self::$mappings[$className] = $className;
        self::$propertyMaps[$className] = $propertyMap;
    }

    /**
     * @return array
     */
    public static function getMappings()
    {
        return self::$mappings;
    }

    /**
     * @return array
     */
    public static function resolvePropertyMapForMapper($className)
    {
        return self::$propertyMaps[$className];
    }

    /**
     * @param array $config
     * @return array
     */
    public function getTcaSelectItems($config) {
        $items = [];
        foreach (MappingRegister::getMappings() as $className => $name) {
            $items[] = array(0 => $name, 1 => $className);
        }
        $config['items'] = array_merge($config['items'], $items);
        return $config;
    }

}
